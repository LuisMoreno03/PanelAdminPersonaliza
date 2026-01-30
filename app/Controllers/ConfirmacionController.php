<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion');
    }

    private function normalizeShopifyOrderId($id): string
    {
        $s = trim((string)$id);
        if ($s === '') return '';
        if (preg_match('~/Order/(\d+)~i', $s, $m)) return (string)$m[1];
        return $s;
    }

    private function orderKeyFromPedido(array $pedido): string
    {
        $sid = trim((string)($pedido['shopify_order_id'] ?? ''));
        if ($sid !== '' && $sid !== '0') return $sid;
        return (string)($pedido['id'] ?? '');
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
                return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'No auth']);
            }

            $userId = (int) session('user_id');
            if ($userId <= 0) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'User inválido']);
            }

            $db = \Config\Database::connect();

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

            $driver = strtolower((string)($db->DBDriver ?? ''));

            if ($hasShopifyId) {
                if (str_contains($driver, 'mysql')) {
                    $orderKeySql = "COALESCE(NULLIF(TRIM(p.shopify_order_id),''), CONCAT(p.id,''))";
                } else {
                    $orderKeySql = "COALESCE(NULLIF(TRIM(CAST(p.shopify_order_id AS TEXT)),''), CAST(p.id AS TEXT))";
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
                )
                ->join(
                    'pedidos_estado pe',
                    "pe.order_id COLLATE utf8mb4_unicode_ci = ($orderKeySql) COLLATE utf8mb4_unicode_ci",
                    'left',
                    false
                )
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por preparar','faltan archivos')", null, false)
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();

            $this->applyEtiquetaExclusions($q, $db);
            $this->applyPedidoJsonExclusions($q, $db, $hasPedidoJson);

            $expressOrderExpr = $this->expressOrderExpr();

            $rows = $q
                ->orderBy($expressOrderExpr, 'ASC', false) // ✅ Express primero
                ->orderBy('p.created_at', 'ASC')           // ✅ luego más antiguos
                ->orderBy('p.id', 'ASC')
                ->get()
                ->getResultArray();

            if ($hasPedidoJson && $rows) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return !$this->isCancelledFromPedidoJson($r['pedido_json'] ?? null);
                }));
                foreach ($rows as &$r) { unset($r['pedido_json']); }
                unset($r);
            }

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => $e->getMessage()]);
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
                return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'No auth']);
            }

            $userId = (int) session('user_id');
            $user   = session('nombre') ?? 'Sistema';

            $payload = $this->request->getJSON(true) ?? [];
            $count = (int)($payload['count'] ?? 5);
            $count = in_array($count, [5, 10], true) ? $count : 5;

            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId  = in_array('shopify_order_id', $pFields, true);
            $hasPedidoJson = in_array('pedido_json', $pFields, true);

            if (!$hasShopifyId) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => 'La tabla pedidos no tiene la columna shopify_order_id.'
                ]);
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

            $expressOrderExpr = $this->expressOrderExpr();

            $candidatosRaw = $candQuery
                ->orderBy($expressOrderExpr, 'ASC', false) // ✅ Express primero
                ->orderBy('p.created_at', 'ASC')           // ✅ luego más antiguos
                ->orderBy('p.id', 'ASC')
                ->limit($limitFetch)
                ->get()
                ->getResultArray();

            if (!$candidatosRaw) {
                $db->transComplete();
                return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos']);
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
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'message' => 'Sin candidatos (filtrados por cancelación/etiquetas)'
                ]);
            }

            $ids = array_column($candidatos, 'id');

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update(['assigned_to_user_id' => $userId, 'assigned_at' => $now]);

            foreach ($candidatos as $c) {
                $orderKey = trim((string)($c['shopify_order_id'] ?? ''));
                if ($orderKey === '') $orderKey = (string)($c['id'] ?? '');
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
                        'order_id' => $orderKey,
                        'estado' => 'Por preparar',
                        'estado_updated_at' => $now,
                        'estado_updated_by_name' => $user
                    ]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'Transacción falló']);
            }

            return $this->response->setJSON(['ok' => true, 'assigned' => count($ids)]);
        } catch (\Throwable $e) {
            log_message('error', 'pull() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
        }

        $userId = (int) session('user_id');
        if ($userId <= 0) return $this->response->setJSON(['ok' => false]);

        \Config\Database::connect()
            ->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);

        return $this->response->setJSON(['ok' => true]);
    }
     public function guardarNota()
    {
        // 1) Leer JSON o fallback a POST
        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }

        $orderId = trim((string)($payload['order_id'] ?? $payload['id'] ?? ''));
        if ($orderId === '') {
            return $this->respond([
                'success' => false,
                'message' => 'order_id requerido',
            ], 422);
        }

        $note       = (string)($payload['note'] ?? '');
        $modifiedBy = trim((string)($payload['modified_by'] ?? $payload['user'] ?? ''));
        $modifiedAt = $this->toDbDateTime((string)($payload['modified_at'] ?? '')) ?? date('Y-m-d H:i:s');

        $now = date('Y-m-d H:i:s');

        $db = Database::connect();
        $table = $db->table('confirmacion_order_notes');

        try {
            // 2) Ver si existe por order_id (upsert manual)
            $existing = $table->select('id')
                ->where('order_id', $orderId)
                ->get()
                ->getRowArray();

            $data = [
                'order_id'     => $orderId,
                'note'         => $note,
                'modified_by'  => $modifiedBy !== '' ? $modifiedBy : null,
                'modified_at'  => $modifiedAt,
                'updated_at'   => $now,
            ];

            if ($existing && isset($existing['id'])) {
                $table->where('order_id', $orderId)->update($data);
            } else {
                $data['created_at'] = $now;
                $table->insert($data);
            }

            // 3) Leer lo guardado y responder consistente
            $saved = $table->where('order_id', $orderId)->get()->getRowArray();

            return $this->respond([
                'success'     => true,
                'note'        => (string)($saved['note'] ?? ''),
                'modified_by' => (string)($saved['modified_by'] ?? ''),
                'modified_at' => $this->dbToIso((string)($saved['modified_at'] ?? '')),
            ]);

        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error guardando nota: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }
        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'ID inválido']);
        }

        try {
            $idNorm = $this->normalizeShopifyOrderId($id);

            $db = \Config\Database::connect();
            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasPedidoJson = in_array('pedido_json', $pFields, true);
            $hasImgLocales = in_array('imagenes_locales', $pFields, true);
            $hasProdImages = in_array('product_images', $pFields, true);

            $pedido = $db->table('pedidos')
                ->groupStart()
                    ->where('id', $idNorm)
                    ->orWhere('shopify_order_id', $idNorm)
                    ->orWhere('shopify_order_id', (string)$id)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);
            }

            $shopifyId = $pedido['shopify_order_id'] ?? null;

            $orderJson = null;
            if ($hasPedidoJson) {
                $orderJson = json_decode($pedido['pedido_json'] ?? '', true);
            }

            if (!$orderJson || (empty($orderJson['line_items']) && empty($orderJson['lineItems']))) {
                $orderJson = [
                    'id' => $shopifyId ?: ($pedido['id'] ?? null),
                    'name' => $pedido['numero'] ?? ('#'.($shopifyId ?: $pedido['id'])),
                    'created_at' => $pedido['created_at'] ?? null,
                    'customer' => ['first_name' => $pedido['cliente'] ?? '', 'last_name' => ''],
                    'line_items' => [],
                ];
            }

            $imagenesLocales = $hasImgLocales ? json_decode($pedido['imagenes_locales'] ?? '{}', true) : [];
            if (!is_array($imagenesLocales)) $imagenesLocales = [];

            $productImages   = $hasProdImages ? json_decode($pedido['product_images'] ?? '{}', true) : [];
            if (!is_array($productImages)) $productImages = [];

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => $imagenesLocales,
                'product_images' => $productImages,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'detalles() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => $e->getMessage()]);
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
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No auth']);
        }

        $orderIdRaw = (string)($this->request->getGet('order_id') ?? '');
        $orderId = $this->normalizeShopifyOrderId($orderIdRaw);
        if ($orderId === '') {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'order_id requerido']);
        }

        $db = \Config\Database::connect();
        $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
        if (!$pedido) $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
        if (!$pedido) return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);

        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        if (!is_array($imagenes)) $imagenes = [];

        return $this->response->setJSON(['success' => true, 'data' => $imagenes]);
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
                return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No auth']);
            }

            $orderIdRaw = (string)$this->request->getPost('order_id');
            $orderId    = $this->normalizeShopifyOrderId($orderIdRaw);

            $index = (int)$this->request->getPost('line_index');
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
                log_message('error', 'Upload validate error: ' . $err);

                return $this->response->setJSON([
                    'success' => false,
                    'message' => $err,
                ]);
            }

            if (!$file || !$file->isValid()) {
                $msg = $file ? $file->getErrorString() : 'Archivo inválido';
                log_message('error', 'Upload invalid file: ' . $msg);

                return $this->response->setJSON([
                    'success' => false,
                    'message' => $msg,
                ]);
            }

            $modifiedBy = trim((string)$this->request->getPost('modified_by'));
            $modifiedAt = trim((string)$this->request->getPost('modified_at'));
            if ($modifiedBy === '') $modifiedBy = session('nombre') ?? 'Sistema';
            if ($modifiedAt === '') $modifiedAt = date('c');

            if ($orderId === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'order_id inválido'
                ]);
            }

            $db = \Config\Database::connect();

            $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
            if (!$pedido) $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
            if (!$pedido) return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);

            $pFields = $db->getFieldNames('pedidos') ?? [];
            if (!in_array('imagenes_locales', $pFields, true)) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'Falta columna pedidos.imagenes_locales'
                ]);
            }

            $orderKey = $this->orderKeyFromPedido($pedido);
            if (trim($orderKey) === '') $orderKey = (string)($pedido['id'] ?? $orderId);

            $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
            if (!is_array($imagenes)) $imagenes = [];

            // borrar anterior del mismo index si existe y es local
            $prev = $imagenes[(string)$index] ?? $imagenes[$index] ?? null;
            $prevUrl = '';
            if (is_string($prev)) $prevUrl = $prev;
            if (is_array($prev))  $prevUrl = (string)($prev['url'] ?? $prev['value'] ?? '');
            if ($prevUrl) $this->tryDeleteLocalUploadByUrl($prevUrl);

            // guardar en PUBLIC: /public/uploads/confirmacion/{orderKey}/
            $dir = rtrim(FCPATH, '/\\') . '/uploads/confirmacion/' . $orderKey;

            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                log_message('error', 'No se pudo crear el directorio: ' . $dir);

                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se pudo crear el directorio de subida. Revisa permisos.'
                ]);
            }

            $name = $file->getRandomName();

            // evita re-mover si ya se movió
            if (!$file->hasMoved()) {
                $file->move($dir, $name);
            }

            if (!is_file($dir . '/' . $name)) {
                log_message('error', 'move() no generó el archivo. Dir: '.$dir.' Name: '.$name);

                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se pudo guardar el archivo en el servidor.'
                ]);
            }

            $url = base_url('uploads/confirmacion/' . $orderKey . '/' . $name);

            $imagenes[(string)$index] = [
                'url'         => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
            ];

            $db->table('pedidos')
                ->where('id', (int)$pedido['id'])
                ->update(['imagenes_locales' => json_encode($imagenes, JSON_UNESCAPED_SLASHES)]);

            $nuevoEstado = $this->validarEstadoAutomatico((int)$pedido['id'], $orderKey);

            return $this->response->setJSON([
                'success'     => true,
                'url'         => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
                'estado'      => $nuevoEstado
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'subirImagen() error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error subiendo imagen: ' . $e->getMessage(),
            ]);
        }
    }

    private function tryDeleteLocalUploadByUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') return;

        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($path === '') return;

        $needle = '/uploads/confirmacion/';
        $pos = strpos($path, $needle);
        if ($pos === false) return;

        $rel = substr($path, $pos); // desde /uploads/confirmacion/...
        $full = rtrim(FCPATH, '/\\') . $rel;

        if (is_file($full)) {
            @unlink($full);
        }
    }

    public function guardarEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'ok' => false, 'message' => 'No auth']);
        }

        try {
            $db = \Config\Database::connect();
            $payload = $this->request->getJSON(true) ?? [];

            $orderIdRaw = (string)($payload['order_id'] ?? $payload['id'] ?? '');
            $orderId = $this->normalizeShopifyOrderId($orderIdRaw);

            $estado   = (string)($payload['estado'] ?? '');
            $mantener = (bool)($payload['mantener_asignado'] ?? false);

            if ($orderId === '' || $estado === '') {
                return $this->response->setStatusCode(400)->setJSON(['success' => false, 'ok' => false, 'message' => 'Payload inválido']);
            }

            $user = session('nombre') ?? 'Sistema';
            $now  = date('Y-m-d H:i:s');

            $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
            if (!$pedido) $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['success' => false, 'ok' => false, 'message' => 'Pedido no encontrado']);
            }

            $orderKey = $this->orderKeyFromPedido($pedido);

            $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();

            if ($existe) {
                $db->table('pedidos_estado')->where('order_id', $orderKey)->update([
                    'estado' => $estado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user,
                ]);
            } else {
                $db->table('pedidos_estado')->insert([
                    'order_id' => $orderKey,
                    'estado' => $estado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user,
                ]);
            }

            $db->table('pedidos_estado_historial')->insert([
                'order_id' => $orderKey,
                'estado' => $estado,
                'user_name' => $user,
                'created_at' => $now
            ]);

            $estadoLower = mb_strtolower(trim($estado));

            if ($estadoLower === 'confirmado' || $estadoLower === 'cancelado') {
                $db->table('pedidos')
                    ->where('id', (int)$pedido['id'])
                    ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
            }

            return $this->response->setJSON(['success' => true, 'ok' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'guardarEstado() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'ok' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function validarEstadoAutomatico(int $pedidoId, string $orderKey): string
    {
        $db = \Config\Database::connect();
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

                $val = $imagenes[(string)$i] ?? $imagenes[$i] ?? null;
                $url = '';

                if (is_string($val)) $url = $val;
                elseif (is_array($val)) $url = (string)($val['url'] ?? $val['value'] ?? '');

                if (trim($url) !== '') $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas) ? 'Confirmado' : 'Faltan archivos';
        $now  = date('Y-m-d H:i:s');
        $user = session('nombre') ?? 'Sistema';

        $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();
        if ($existe) {
            $db->table('pedidos_estado')
                ->where('order_id', $orderKey)
                ->update([
                    'estado' => $nuevoEstado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user
                ]);
        } else {
            $db->table('pedidos_estado')->insert([
                'order_id' => $orderKey,
                'estado' => $nuevoEstado,
                'estado_updated_at' => $now,
                'estado_updated_by_name' => $user
            ]);
        }

        $db->table('pedidos_estado_historial')->insert([
            'order_id' => $orderKey,
            'estado' => $nuevoEstado,
            'user_name' => $user,
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
        $title = strtolower($item['title'] ?? '');
        $sku   = strtolower($item['sku'] ?? '');

        $keywords = ['llavero', 'lampara', 'lámpara'];

        foreach ($keywords as $word) {
            if (str_contains($title, $word)) return true;
        }

        if (str_contains($sku, 'llav')) return true;

        foreach (($item['properties'] ?? []) as $p) {
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', (string)($p['value'] ?? ''))) return true;
        }

        return false;
    }

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
        $pedidoJson = (string)$pedidoJson;
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
            $s = trim((string)$v);
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
        $financial = mb_strtolower(trim((string)$financialRaw));

        if ($financial !== '') {
            if (in_array($financial, ['refunded', 'voided', 'partially_refunded', 'partially-refunded'], true)) return true;
            if (str_contains($financial, 'refund') || str_contains($financial, 'reembols') || str_contains($financial, 'void')) return true;
        }

        $tagsRaw = $o['tags'] ?? '';
        $tags = is_array($tagsRaw) ? implode(',', $tagsRaw) : (string)$tagsRaw;
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
                    $rt = mb_strtolower(trim((string)($x['restock_type'] ?? '')));
                    if ($rt === 'cancel') return true;
                }
            }
        }

        return false;
    }
}
