<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion');
    }

    /* =====================================================
       Helpers base
    ===================================================== */

    private function json(array $data, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }

    private function normalizeShopifyOrderId($id): string
    {
        $s = trim((string) $id);
        if ($s === '') return '';
        if (preg_match('~/Order/(\d+)~i', $s, $m)) return (string) $m[1];
        return $s;
    }

    private function orderKeyFromPedido(array $pedido): string
    {
        $sid = trim((string) ($pedido['shopify_order_id'] ?? ''));
        if ($sid !== '' && $sid !== '0') return $sid;
        return (string) ($pedido['id'] ?? '');
    }

    private function getCurrentUserName(): string
    {
        $u = (string) (session('nombre') ?? session('user_name') ?? 'Sistema');
        $u = trim($u);
        return $u !== '' ? $u : 'Sistema';
    }

    private function toDbDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        // Si ya viene como "Y-m-d H:i:s"
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        // ISO / otros formatos parseables
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dbToIso(?string $dbDateTime): string
    {
        $dbDateTime = trim((string) $dbDateTime);
        if ($dbDateTime === '') return '';

        try {
            $dt = new \DateTimeImmutable($dbDateTime);
            return $dt->format(DATE_ATOM); // ISO 8601
        } catch (\Throwable $e) {
            return $dbDateTime;
        }
    }

    private function findPedidoByAny(string $idNorm): ?array
    {
        $db = Database::connect();

        $pedido = $db->table('pedidos')
            ->groupStart()
                ->where('id', $idNorm)
                ->orWhere('shopify_order_id', $idNorm)
                ->orWhere('numero', $idNorm)
            ->groupEnd()
            ->get()
            ->getRowArray();

        return $pedido ?: null;
    }

    /**
     * ✅ Expresión SQL para ordenar:
     *  - Express primero (0)
     *  - Normal después (1)
     * Detecta "express" dentro de p.forma_envio (case-insensitive)
     */
    private function expressOrderExpr(): string
    {
        return "CASE
            WHEN LOWER(TRIM(COALESCE(p.forma_envio,''))) LIKE '%express%' THEN 0
            ELSE 1
        END";
    }

    /* =====================================================
      GET /confirmacion/my-queue
      ✅ Express primero
      ✅ Dentro de cada grupo: más antiguos primero
      ✅ over_24h = 1 si assigned_at tiene +24h
    ===================================================== */
    public function myQueue()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->json(['ok' => false, 'success' => false, 'message' => 'No auth'], 401);
            }

            $userId = (int) session('user_id');
            if ($userId <= 0) {
                return $this->json(['ok' => false, 'success' => false, 'message' => 'User inválido'], 401);
            }

            $db = Database::connect();

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId  = in_array('shopify_order_id', $pFields, true);
            $hasPedidoJson = in_array('pedido_json', $pFields, true);
            $hasAssignedAt = in_array('assigned_at', $pFields, true);

            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);
            $hasPeUserName  = in_array('user_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            $driver = strtolower((string) ($db->DBDriver ?? ''));

            // orderKey SQL: usa shopify_order_id si existe y no es '' ni '0', si no usa p.id
            if ($hasShopifyId) {
                if (str_contains($driver, 'mysql')) {
                    $orderKeySql = "COALESCE(NULLIF(NULLIF(TRIM(p.shopify_order_id),''),'0'), CONCAT(p.id,''))";
                } else {
                    $orderKeySql = "COALESCE(NULLIF(NULLIF(TRIM(CAST(p.shopify_order_id AS TEXT)),''),'0'), CAST(p.id AS TEXT))";
                }
            } else {
                $orderKeySql = str_contains($driver, 'mysql')
                    ? "CONCAT(p.id,'')"
                    : "CAST(p.id AS TEXT)";
            }

            if (!$hasAssignedAt) {
                $assignedAtSelect = "NULL as assigned_at";
                $over24Expr = "0";
            } else {
                $assignedAtSelect = "p.assigned_at";

                if (str_contains($driver, 'mysql')) {
                    $over24Expr = "CASE
                        WHEN p.assigned_at IS NOT NULL AND p.assigned_at <= (NOW() - INTERVAL 24 HOUR) THEN 1
                        ELSE 0
                    END";
                } else {
                    $over24Expr = "CASE
                        WHEN p.assigned_at IS NOT NULL AND p.assigned_at <= (NOW() - INTERVAL '24 hours') THEN 1
                        ELSE 0
                    END";
                }
            }

            $q = $db->table('pedidos p')
                ->select(
                    "p.id, " .
                    ($hasShopifyId ? "p.shopify_order_id, " : "NULL as shopify_order_id, ") .
                    ($hasPedidoJson ? "p.pedido_json, " : "NULL as pedido_json, ") .
                    "p.numero, p.cliente, p.total, p.estado_envio, p.forma_envio, p.etiquetas, p.articulos, p.created_at, " .
                    "$assignedAtSelect, " .
                    "($over24Expr) as over_24h, " .
                    "COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect",
                    false
                );

            // JOIN pedidos_estado: MySQL con COLLATE, otros sin COLLATE
            if (str_contains($driver, 'mysql')) {
                $q->join(
                    'pedidos_estado pe',
                    "pe.order_id COLLATE utf8mb4_unicode_ci = ($orderKeySql) COLLATE utf8mb4_unicode_ci",
                    'left',
                    false
                );
            } else {
                $q->join(
                    'pedidos_estado pe',
                    "pe.order_id = ($orderKeySql)",
                    'left',
                    false
                );
            }

            $q->where('p.assigned_to_user_id', $userId)
              ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por preparar','faltan archivos')", null, false)
              ->groupStart()
                  ->where('p.estado_envio IS NULL', null, false)
                  ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                  ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
              ->groupEnd();

            $this->applyEtiquetaExclusions($q, $db);
            $this->applyPedidoJsonExclusions($q, $db, $hasPedidoJson);

            $rows = $q
                ->orderBy($this->expressOrderExpr(), 'ASC', false)
                ->orderBy('p.created_at', 'ASC')
                ->orderBy('p.id', 'ASC')
                ->get()
                ->getResultArray();

            // filtro final de cancelación por JSON (doble seguridad)
            if ($hasPedidoJson && $rows) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return !$this->isCancelledFromPedidoJson($r['pedido_json'] ?? null);
                }));
                foreach ($rows as &$r) { unset($r['pedido_json']); }
                unset($r);
            }

            return $this->json(['ok' => true, 'success' => true, 'data' => $rows ?: []]);
        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController myQueue() error: ' . $e->getMessage());
            return $this->json(['ok' => false, 'success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* =====================================================
      POST /confirmacion/pull
      ✅ Express primero
      ✅ Si hay pocos express: completa con normales más antiguos
    ===================================================== */
    public function pull()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->json(['ok' => false, 'message' => 'No auth'], 401);
            }

            $userId = (int) session('user_id');
            $user   = $this->getCurrentUserName();

            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) $payload = [];

            $count = (int) ($payload['count'] ?? 5);
            $count = in_array($count, [5, 10], true) ? $count : 5;

            $db  = Database::connect();
            $now = date('Y-m-d H:i:s');

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId  = in_array('shopify_order_id', $pFields, true);
            $hasPedidoJson = in_array('pedido_json', $pFields, true);

            if (!$hasShopifyId) {
                return $this->json(['ok' => false, 'message' => 'La tabla pedidos no tiene shopify_order_id'], 500);
            }

            $db->transStart();

            $limitFetch = max($count * 8, $count);
            $select = 'p.id, p.shopify_order_id, p.etiquetas' . ($hasPedidoJson ? ', p.pedido_json' : '');

            $candQuery = $db->table('pedidos p')
                ->select($select, false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar')))", 'por preparar')
                ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)')
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();

            $this->applyEtiquetaExclusions($candQuery, $db);
            $this->applyPedidoJsonExclusions($candQuery, $db, $hasPedidoJson);

            $candidatosRaw = $candQuery
                ->orderBy($this->expressOrderExpr(), 'ASC', false)
                ->orderBy('p.created_at', 'ASC')
                ->orderBy('p.id', 'ASC')
                ->limit($limitFetch)
                ->get()
                ->getResultArray();

            if (!$candidatosRaw) {
                $db->transComplete();
                return $this->json(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos']);
            }

            $candidatos = $candidatosRaw;

            if ($hasPedidoJson) {
                $candidatos = array_values(array_filter($candidatos, function ($r) {
                    return !$this->isCancelledFromPedidoJson($r['pedido_json'] ?? null);
                }));
            }

            // ✅ ya vienen ordenados por (express primero + antiguos)
            $candidatos = array_slice($candidatos, 0, $count);

            if (!$candidatos) {
                $db->transComplete();
                return $this->json(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos (filtrados)']);
            }

            $ids = array_column($candidatos, 'id');

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update(['assigned_to_user_id' => $userId, 'assigned_at' => $now]);

            foreach ($candidatos as $c) {
                $orderKey = trim((string) ($c['shopify_order_id'] ?? ''));
                if ($orderKey === '') $orderKey = (string) ($c['id'] ?? '');
                if ($orderKey === '') continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => $orderKey,
                    'estado'     => 'Por preparar',
                    'user_name'  => $user,
                    'created_at' => $now
                ]);

                $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();
                if (!$existe) {
                    $db->table('pedidos_estado')->insert([
                        'order_id'               => $orderKey,
                        'estado'                 => 'Por preparar',
                        'estado_updated_at'      => $now,
                        'estado_updated_by_name' => $user
                    ]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->json(['ok' => false, 'message' => 'Transacción falló'], 500);
            }

            return $this->json(['ok' => true, 'assigned' => count($ids)]);
        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController pull() error: ' . $e->getMessage());
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->json(['ok' => false], 401);
        }

        $userId = (int) session('user_id');
        if ($userId <= 0) return $this->json(['ok' => false], 400);

        Database::connect()
            ->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);

        return $this->json(['ok' => true]);
    }

    /* =====================================================
       POST /confirmacion/guardar-nota
       ✅ FIX: incluye toDbDateTime() y dbToIso()
       ✅ FIX: usa \Config\Database y no $this->respond()
    ===================================================== */
    public function guardarNota()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->json(['success' => false, 'ok' => false, 'message' => 'No auth'], 401);
            }

            // 1) Leer JSON o fallback a POST
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                $payload = $this->request->getPost() ?? [];
            }

            $orderIdRaw = trim((string) ($payload['order_id'] ?? $payload['id'] ?? ''));
            if ($orderIdRaw === '') {
                return $this->json(['success' => false, 'ok' => false, 'message' => 'order_id requerido'], 422);
            }

            $idNorm = $this->normalizeShopifyOrderId($orderIdRaw);

            $note       = (string) ($payload['note'] ?? '');
            $modifiedBy = trim((string) ($payload['modified_by'] ?? $payload['user'] ?? ''));
            if ($modifiedBy === '') $modifiedBy = $this->getCurrentUserName();

            $modifiedAt = $this->toDbDateTime((string) ($payload['modified_at'] ?? '')) ?? date('Y-m-d H:i:s');
            $now        = date('Y-m-d H:i:s');

            $db = Database::connect();

            // Resolver pedido y usar orderKey consistente (shopify_order_id si existe)
            $pedido = $this->findPedidoByAny($idNorm) ?: $this->findPedidoByAny($orderIdRaw);
            $orderKey = $pedido ? $this->orderKeyFromPedido($pedido) : $idNorm;
            if (trim($orderKey) === '') $orderKey = $orderIdRaw;

            $table = $db->table('confirmacion_order_notes');

            // 2) Ver si existe por order_id (upsert manual)
            $existing = $table->select('id')
                ->where('order_id', $orderKey)
                ->get()
                ->getRowArray();

            $data = [
                'order_id'    => $orderKey,
                'note'        => $note,
                'modified_by' => $modifiedBy !== '' ? $modifiedBy : null,
                'modified_at' => $modifiedAt,
                'updated_at'  => $now,
            ];

            if ($existing && isset($existing['id'])) {
                $table->where('order_id', $orderKey)->update($data);
            } else {
                $data['created_at'] = $now;
                $table->insert($data);
            }

            // 3) Leer lo guardado y responder consistente
            $saved = $table->where('order_id', $orderKey)->get()->getRowArray();

            return $this->json([
                'success'     => true,
                'ok'          => true,
                'order_id'    => $orderKey,
                'note'        => (string) ($saved['note'] ?? ''),
                'modified_by' => (string) ($saved['modified_by'] ?? ''),
                'modified_at' => $this->dbToIso((string) ($saved['modified_at'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController guardarNota() error: ' . $e->getMessage());
            return $this->json([
                'success' => false,
                'ok'      => false,
                'message' => 'Error guardando nota: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->json(['success' => false, 'message' => 'No autenticado'], 401);
        }
        if (!$id) {
            return $this->json(['success' => false, 'message' => 'ID inválido'], 400);
        }

        try {
            $idNorm = $this->normalizeShopifyOrderId($id);

            $db = Database::connect();
            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasPedidoJson = in_array('pedido_json', $pFields, true);
            $hasImgLocales = in_array('imagenes_locales', $pFields, true);
            $hasProdImages = in_array('product_images', $pFields, true);

            $pedido = $this->findPedidoByAny($idNorm) ?: $this->findPedidoByAny((string) $id);

            if (!$pedido) {
                return $this->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);
            }

            $shopifyId = $pedido['shopify_order_id'] ?? null;

            $orderJson = null;
            if ($hasPedidoJson) {
                $orderJson = json_decode($pedido['pedido_json'] ?? '', true);
            }

            if (!$orderJson || (empty($orderJson['line_items']) && empty($orderJson['lineItems']))) {
                $orderJson = [
                    'id' => $shopifyId ?: ($pedido['id'] ?? null),
                    'name' => $pedido['numero'] ?? ('#' . ($shopifyId ?: ($pedido['id'] ?? ''))),
                    'created_at' => $pedido['created_at'] ?? null,
                    'customer' => ['first_name' => $pedido['cliente'] ?? '', 'last_name' => ''],
                    'line_items' => [],
                ];
            }

            $imagenesLocales = $hasImgLocales ? json_decode($pedido['imagenes_locales'] ?? '{}', true) : [];
            if (!is_array($imagenesLocales)) $imagenesLocales = [];

            $productImages = $hasProdImages ? json_decode($pedido['product_images'] ?? '{}', true) : [];
            if (!is_array($productImages)) $productImages = [];

            return $this->json([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => $imagenesLocales,
                'product_images' => $productImages,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController detalles() error: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ✅ alias para /confirmacion/upload
    public function uploadConfirmacion()
    {
        return $this->subirImagen();
    }

    public function listFiles()
    {
        if (!session()->get('logged_in')) {
            return $this->json(['success' => false, 'message' => 'No auth'], 401);
        }

        $orderIdRaw = (string) ($this->request->getGet('order_id') ?? '');
        $orderId = $this->normalizeShopifyOrderId($orderIdRaw);
        if ($orderId === '') {
            return $this->json(['success' => false, 'message' => 'order_id requerido'], 400);
        }

        $db = Database::connect();
        $pedido = $this->findPedidoByAny($orderId);
        if (!$pedido) return $this->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        if (!is_array($imagenes)) $imagenes = [];

        return $this->json(['success' => true, 'data' => $imagenes]);
    }

    /* =====================================================
      POST /confirmacion/subir-imagen
      ✅ guarda archivo público + persiste en DB + borra anterior del mismo index
      ✅ FIX: ext_in + try/catch + control mkdir/move
    ===================================================== */
    public function subirImagen()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->json(['success' => false, 'message' => 'No auth'], 401);
            }

            helper(['url']);

            $orderIdRaw = (string) ($this->request->getPost('order_id') ?? '');
            $orderId    = $this->normalizeShopifyOrderId($orderIdRaw);

            $index = (int) ($this->request->getPost('line_index') ?? 0);
            $file  = $this->request->getFile('file');

            // ✅ Límite 20MB (en KB)
            $maxKB = 20480;

            // ✅ VALIDACIÓN más robusta: ext_in en vez de mime_in
            $rules = [
                'file' => [
                    'rules'  => 'uploaded[file]|max_size[file,' . $maxKB . ']|is_image[file]|ext_in[file,jpg,jpeg,png,webp,gif,svg]',
                    'errors' => [
                        'uploaded'  => 'Debes subir un archivo.',
                        'max_size'  => 'La imagen no puede superar 20MB.',
                        'is_image'  => 'El archivo debe ser una imagen válida.',
                        'ext_in'    => 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.',
                    ],
                ],
            ];

            if (!$this->validate($rules)) {
                $err = $this->validator->getError('file') ?? 'Archivo inválido';
                log_message('error', 'Confirmacion subirImagen validate error: ' . $err);

                return $this->json(['success' => false, 'message' => $err], 200);
            }

            if (!$file || !$file->isValid()) {
                $msg = $file ? $file->getErrorString() : 'Archivo inválido';
                log_message('error', 'Confirmacion subirImagen invalid file: ' . $msg);

                return $this->json(['success' => false, 'message' => $msg], 200);
            }

            $modifiedBy = trim((string) $this->request->getPost('modified_by'));
            $modifiedAt = trim((string) $this->request->getPost('modified_at'));
            if ($modifiedBy === '') $modifiedBy = $this->getCurrentUserName();
            if ($modifiedAt === '') $modifiedAt = date(DATE_ATOM);

            if ($orderId === '') {
                return $this->json(['success' => false, 'message' => 'order_id inválido'], 400);
            }

            $db = Database::connect();

            $pedido = $this->findPedidoByAny($orderId);
            if (!$pedido) return $this->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

            $pFields = $db->getFieldNames('pedidos') ?? [];
            if (!in_array('imagenes_locales', $pFields, true)) {
                return $this->json(['success' => false, 'message' => 'Falta columna pedidos.imagenes_locales'], 500);
            }

            $orderKey = $this->orderKeyFromPedido($pedido);
            if (trim($orderKey) === '') $orderKey = (string) ($pedido['id'] ?? $orderId);

            $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
            if (!is_array($imagenes)) $imagenes = [];

            // borrar anterior del mismo index si existe y es local
            $prev = $imagenes[(string) $index] ?? $imagenes[$index] ?? null;
            $prevUrl = '';
            if (is_string($prev)) $prevUrl = $prev;
            if (is_array($prev))  $prevUrl = (string) ($prev['url'] ?? $prev['value'] ?? '');
            if ($prevUrl) $this->tryDeleteLocalUploadByUrl($prevUrl);

            // guardar en PUBLIC: /public/uploads/confirmacion/{orderKey}/
            $dir = rtrim(FCPATH, '/\\') . '/uploads/confirmacion/' . $orderKey;

            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                log_message('error', 'Confirmacion subirImagen: no se pudo crear directorio: ' . $dir);
                return $this->json(['success' => false, 'message' => 'No se pudo crear el directorio. Revisa permisos.'], 500);
            }

            $name = $file->getRandomName();

            if (!$file->hasMoved()) {
                $file->move($dir, $name);
            }

            if (!is_file($dir . '/' . $name)) {
                log_message('error', 'Confirmacion subirImagen: move() no generó archivo. Dir: ' . $dir . ' Name: ' . $name);
                return $this->json(['success' => false, 'message' => 'No se pudo guardar el archivo en el servidor.'], 500);
            }

            $url = base_url('uploads/confirmacion/' . $orderKey . '/' . $name);

            $imagenes[(string) $index] = [
                'url'         => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
            ];

            $db->table('pedidos')
                ->where('id', (int) $pedido['id'])
                ->update(['imagenes_locales' => json_encode($imagenes, JSON_UNESCAPED_SLASHES)]);

            $nuevoEstado = $this->validarEstadoAutomatico((int) $pedido['id'], $orderKey);

            return $this->json([
                'success'     => true,
                'url'         => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
                'estado'      => $nuevoEstado,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion subirImagen() error: ' . $e->getMessage());
            return $this->json([
                'success' => false,
                'message' => 'Error subiendo imagen: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function tryDeleteLocalUploadByUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') return;

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') return;

        $needle = '/uploads/confirmacion/';
        $pos = strpos($path, $needle);
        if ($pos === false) return;

        $rel  = substr($path, $pos); // desde /uploads/confirmacion/...
        $full = rtrim(FCPATH, '/\\') . $rel;

        if (is_file($full)) {
            @unlink($full);
        }
    }

    public function guardarEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->json(['success' => false, 'ok' => false, 'message' => 'No auth'], 401);
        }

        try {
            $db = Database::connect();

            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) $payload = [];

            $orderIdRaw = (string) ($payload['order_id'] ?? $payload['id'] ?? '');
            $orderId    = $this->normalizeShopifyOrderId($orderIdRaw);

            $estado   = (string) ($payload['estado'] ?? '');
            $mantener = (bool) ($payload['mantener_asignado'] ?? false);

            if ($orderId === '' || trim($estado) === '') {
                return $this->json(['success' => false, 'ok' => false, 'message' => 'Payload inválido'], 400);
            }

            $user = $this->getCurrentUserName();
            $now  = date('Y-m-d H:i:s');

            $pedido = $this->findPedidoByAny($orderId);
            if (!$pedido) {
                return $this->json(['success' => false, 'ok' => false, 'message' => 'Pedido no encontrado'], 404);
            }

            $orderKey = $this->orderKeyFromPedido($pedido);
            if (trim($orderKey) === '') $orderKey = (string) ($pedido['id'] ?? $orderId);

            $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();

            if ($existe) {
                $db->table('pedidos_estado')->where('order_id', $orderKey)->update([
                    'estado'                 => $estado,
                    'estado_updated_at'      => $now,
                    'estado_updated_by_name' => $user,
                ]);
            } else {
                $db->table('pedidos_estado')->insert([
                    'order_id'               => $orderKey,
                    'estado'                 => $estado,
                    'estado_updated_at'      => $now,
                    'estado_updated_by_name' => $user,
                ]);
            }

            $db->table('pedidos_estado_historial')->insert([
                'order_id'   => $orderKey,
                'estado'     => $estado,
                'user_name'  => $user,
                'created_at' => $now
            ]);

            $estadoLower = mb_strtolower(trim($estado));

            // si confirmado/cancelado -> desasignar
            if ($estadoLower === 'confirmado' || $estadoLower === 'cancelado') {
                $db->table('pedidos')
                    ->where('id', (int) $pedido['id'])
                    ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
            }

            // (mantener_asignado) lo dejas por si luego quieres evitar desasignar cuando faltan archivos
            // ahora mismo tu JS manda mantener_asignado para "faltan archivos", pero aquí no se desasigna en ese caso.

            return $this->json(['success' => true, 'ok' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion guardarEstado() error: ' . $e->getMessage());
            return $this->json([
                'success' => false,
                'ok'      => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function validarEstadoAutomatico(int $pedidoId, string $orderKey): string
    {
        $db = Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasPedidoJson = in_array('pedido_json', $pFields, true);
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        if (!$hasPedidoJson || !$hasImgLocales) return 'Por preparar';

        $pedido = $db->table('pedidos')->where('id', $pedidoId)->get()->getRowArray();
        if (!$pedido) return 'Por preparar';

        $order    = json_decode($pedido['pedido_json'] ?? '', true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        if (!is_array($imagenes)) $imagenes = [];

        $requeridas = 0;
        $cargadas   = 0;

        foreach (($order['line_items'] ?? []) as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;

                $val = $imagenes[(string) $i] ?? $imagenes[$i] ?? null;
                $url = '';

                if (is_string($val)) $url = $val;
                elseif (is_array($val)) $url = (string) ($val['url'] ?? $val['value'] ?? '');

                if (trim($url) !== '') $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas) ? 'Confirmado' : 'Faltan archivos';
        $now  = date('Y-m-d H:i:s');
        $user = $this->getCurrentUserName();

        $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();
        if ($existe) {
            $db->table('pedidos_estado')
                ->where('order_id', $orderKey)
                ->update([
                    'estado'                 => $nuevoEstado,
                    'estado_updated_at'      => $now,
                    'estado_updated_by_name' => $user
                ]);
        } else {
            $db->table('pedidos_estado')->insert([
                'order_id'               => $orderKey,
                'estado'                 => $nuevoEstado,
                'estado_updated_at'      => $now,
                'estado_updated_by_name' => $user
            ]);
        }

        $db->table('pedidos_estado_historial')->insert([
            'order_id'   => $orderKey,
            'estado'     => $nuevoEstado,
            'user_name'  => $user,
            'created_at' => $now
        ]);

        if ($nuevoEstado === 'Confirmado') {
            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
        }

        return $nuevoEstado;
    }

    private function requiereImagen(array $item): bool
    {
        $title = strtolower((string) ($item['title'] ?? ''));
        $sku   = strtolower((string) ($item['sku'] ?? ''));

        $keywords = ['llavero', 'lampara', 'lámpara'];

        foreach ($keywords as $word) {
            if (str_contains($title, $word)) return true;
        }

        if (str_contains($sku, 'llav')) return true;

        foreach (($item['properties'] ?? []) as $p) {
            $v = (string) ($p['value'] ?? '');
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', $v)) return true;
        }

        return false;
    }

    /* =====================================================
       Exclusiones por etiquetas / JSON
    ===================================================== */

    private function applyEtiquetaExclusions($q, $db): void
    {
        $bad = [
            'cancel', 'cancelado', 'cancelada', 'cancelled', 'canceled',
            'anulado', 'voided',
            'cliente pide cancelar pedido',
            'cliente pide cancelar',
            'pide cancelar pedido',
            'pide cancelar',
            'cancelar pedido',
            'devuel', 'devuelto', 'devuelta',
            'devolu', 'devolucion', 'devolución',
            'devolucion 100', 'devolucion 100%', 'devolución 100', 'devolución 100%',
            'devolucion total', 'devolución total',
            'returned', 'return',
            'reemb', 'reembolso', 'reembolsado',
            'refund', 'refunded',
            'contracargo', 'chargeback',
            'dispute', 'disputa',
        ];

        $tagExpr = "LOWER(COALESCE(p.etiquetas,''))";

        foreach ($bad as $t) {
            $t = mb_strtolower(trim($t));
            if ($t === '') continue;

            $like = '%' . $db->escapeLikeString($t) . '%';
            $q->where("$tagExpr NOT LIKE " . $db->escape($like), null, false);
        }
    }

    private function applyPedidoJsonExclusions($q, $db, bool $hasPedidoJson): void
    {
        if (!$hasPedidoJson) return;

        $jsonExpr = "LOWER(COALESCE(p.pedido_json,''))";

        $needles = [
            '"cancelled_at":"', '"cancelled_at": "',
            '"canceled_at":"',  '"canceled_at": "',
            '"cancel_reason":"', '"cancel_reason": "',
            '"cancelreason":"',  '"cancelreason": "',
            '"financial_status":"refunded', '"financial_status": "refunded',
            '"financial_status":"voided',   '"financial_status": "voided',
            '"financial_status":"partially_refunded', '"financial_status": "partially_refunded',
            '"financial_status":"partially-refunded', '"financial_status": "partially-refunded',
            '"refunds":[{', '"refunds": [{', '"refunds":[ {', '"refunds": [ {',
            '"kind":"refund"', '"kind": "refund"',
            '"restock_type":"cancel', '"restock_type": "cancel',
            'cliente pide cancelar pedido',
            'devolucion 100', 'devolución 100',
            'devolucion', 'devolución',
            'devuelto', 'devuelta',
            'chargeback', 'contracargo',
            'dispute', 'disputa',
        ];

        foreach ($needles as $t) {
            $t = mb_strtolower(trim($t));
            if ($t === '') continue;

            $like = '%' . $db->escapeLikeString($t) . '%';
            $q->where("$jsonExpr NOT LIKE " . $db->escape($like), null, false);
        }
    }

    private function isCancelledFromPedidoJson(?string $pedidoJson): bool
    {
        $pedidoJson = (string) $pedidoJson;
        if (trim($pedidoJson) === '') return false;

        $o = json_decode($pedidoJson, true);

        if (!is_array($o)) {
            $s = mb_strtolower($pedidoJson);

            if (preg_match('/"cancelled_at"\s*:\s*"(.*?)"/i', $s)) return true;
            if (preg_match('/"canceled_at"\s*:\s*"(.*?)"/i', $s)) return true;
            if (preg_match('/"cancel_reason"\s*:\s*"(.*?)"/i', $s)) return true;
            if (preg_match('/"financial_status"\s*:\s*"(refunded|voided|partially_refunded|partially-refunded)"/i', $s)) return true;
            if (preg_match('/"refunds"\s*:\s*\[\s*\{/i', $s)) return true;
            if (preg_match('/cliente\s+pide\s+cancelar\s+pedido|devoluci[oó]n|devuelt|reembols|refund|chargeback|contracargo|disput/i', $s)) return true;

            return false;
        }

        if (isset($o['order']) && is_array($o['order'])) $o = $o['order'];
        if (isset($o['data']) && is_array($o['data'])) {
            if (isset($o['data']['order']) && is_array($o['data']['order'])) $o = $o['data']['order'];
            elseif (isset($o['data']['node']) && is_array($o['data']['node'])) $o = $o['data']['node'];
        }

        $notEmpty = static function ($v): bool {
            if ($v === null) return false;
            $s = trim((string) $v);
            return $s !== '' && mb_strtolower($s) !== 'null';
        };

        foreach (['cancelled_at', 'canceled_at', 'cancelledAt', 'canceledAt'] as $k) {
            if (array_key_exists($k, $o) && $notEmpty($o[$k])) return true;
        }

        foreach (['cancel_reason', 'cancelReason'] as $k) {
            if (array_key_exists($k, $o) && $notEmpty($o[$k])) return true;
        }

        $financialRaw = $o['financial_status'] ?? $o['displayFinancialStatus'] ?? $o['financialStatus'] ?? '';
        if (is_array($financialRaw)) {
            $financialRaw = $financialRaw['displayName'] ?? $financialRaw['name'] ?? $financialRaw['value'] ?? '';
        }
        $financial = mb_strtolower(trim((string) $financialRaw));

        if ($financial !== '') {
            if (in_array($financial, ['refunded', 'voided', 'partially_refunded', 'partially-refunded'], true)) return true;
            if (str_contains($financial, 'refund') || str_contains($financial, 'reembols') || str_contains($financial, 'void')) return true;
        }

        $tagsRaw = $o['tags'] ?? '';
        $tags = is_array($tagsRaw) ? implode(',', $tagsRaw) : (string) $tagsRaw;
        $tags = mb_strtolower(trim($tags));

        if ($tags !== '' && preg_match('/(cliente\s+pide\s+cancelar\s+pedido|cancel|devolu|devuel|devuelt|reemb|refund|returned|chargeback|contracargo|disput)/i', $tags)) {
            return true;
        }

        if (isset($o['refunds']) && is_array($o['refunds']) && count($o['refunds']) > 0) return true;
        if (isset($o['refunds']['edges']) && is_array($o['refunds']['edges']) && count($o['refunds']['edges']) > 0) return true;

        if (isset($o['refunds']) && is_array($o['refunds'])) {
            foreach ($o['refunds'] as $rf) {
                if (!is_array($rf)) continue;
                $rli = $rf['refund_line_items'] ?? [];
                if (!is_array($rli)) continue;

                foreach ($rli as $x) {
                    if (!is_array($x)) continue;
                    $rt = mb_strtolower(trim((string) ($x['restock_type'] ?? '')));
                    if ($rt === 'cancel') return true;
                }
            }
        }

        return false;
    }
}
