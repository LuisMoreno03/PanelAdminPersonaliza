<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion');
    }

    /* =====================================================
      GET /confirmacion/my-queue
      -> SOLO pedidos asignados a mi user
      -> SOLO "Por preparar" O "Faltan archivos"
      -> SOLO NO ENVIADOS (unfulfilled)
      ✅ Importante: si falta alguna imagen, el pedido debe seguir en tu cola
    ===================================================== */
    public function myQueue()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
            }

            $userId = (int) session('user_id');
            $db = \Config\Database::connect();

            // Campos reales (no romper si faltan)
            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId = in_array('shopify_order_id', $pFields, true);

            // pedidos_estado columnas posibles
            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);
            $hasPeUserName  = in_array('user_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            $q = $db->table('pedidos p')
                ->select("p.id, " .
                    ($hasShopifyId ? "p.shopify_order_id, " : "NULL as shopify_order_id, ") .
                    "p.numero, p.cliente, p.total, p.estado_envio, p.forma_envio, p.etiquetas, p.articulos, p.created_at, " .
                    "COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect", false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where('p.assigned_to_user_id', $userId)
                // ✅ incluir "faltan archivos" también
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por preparar','faltan archivos')", null, false);

            // ✅ SOLO NO ENVIADOS
            $q->groupStart()
                ->where('p.estado_envio IS NULL', null, false)
                ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
            ->groupEnd();

            $rows = $q->orderBy('p.created_at', 'ASC')->get()->getResultArray();

            return $this->response->setJSON([
                'ok'   => true,
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================
      POST /confirmacion/pull
      -> Asigna pedidos NO enviados + Por preparar + no asignados
      -> Toma los más antiguos
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
            $count = (int) ($payload['count'] ?? 5);
            if ($count <= 0) $count = 5;

            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId = in_array('shopify_order_id', $pFields, true);
            if (!$hasShopifyId) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => 'La tabla pedidos no tiene la columna shopify_order_id. Indícame cuál columna guarda el ID de Shopify.'
                ]);
            }

            $db->transStart();

            // Candidatos: Por preparar + no asignados + no enviados
            $candidatos = $db->table('pedidos p')
                ->select('p.id, p.shopify_order_id')
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar')))", 'por preparar')
                ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)')
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd()
                ->orderBy('p.created_at', 'ASC')
                ->limit($count)
                ->get()
                ->getResultArray();

            if (!$candidatos) {
                $db->transComplete();
                return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos']);
            }

            $ids = array_column($candidatos, 'id');

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            // Asegurar historial/estado
            foreach ($candidatos as $c) {
                if (empty($c['shopify_order_id'])) continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => $c['shopify_order_id'],
                    'estado'     => 'Por preparar',
                    'user_name'  => $user,
                    'created_at' => $now
                ]);

                $existe = $db->table('pedidos_estado')->where('order_id', $c['shopify_order_id'])->countAllResults();
                if (!$existe) {
                    $db->table('pedidos_estado')->insert([
                        'order_id' => $c['shopify_order_id'],
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

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids)
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'pull() error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* =====================================================
      POST /confirmacion/return-all
    ===================================================== */
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

    /* =====================================================
      GET /confirmacion/detalles/{id}
      -> DEVUELVE imagenes_locales con auditoría si existe:
         { index: { url, modified_by, modified_at } }
      -> Soporta recibir id numérico, shopify_order_id o gid://shopify/Order/xxxx
    ===================================================== */
    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }
        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'ID inválido']);
        }

        try {
            // ✅ Normalizar gid://shopify/Order/123 => 123
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
                    ->orWhere('shopify_order_id', (string)$id) // por si viene tal cual sin normalizar
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
                    'id' => $shopifyId,
                    'name' => $pedido['numero'] ?? ('#' . $shopifyId),
                    'created_at' => $pedido['created_at'] ?? null,
                    'customer' => ['first_name' => $pedido['cliente'] ?? '', 'last_name' => ''],
                    'line_items' => [],
                ];
            }

            $imagenesLocales = $hasImgLocales ? json_decode($pedido['imagenes_locales'] ?? '{}', true) : [];
            $productImages   = $hasProdImages ? json_decode($pedido['product_images'] ?? '{}', true) : [];

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
                'product_images' => is_array($productImages) ? $productImages : [],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'detalles() error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* =====================================================
      POST /api/pedidos/imagenes/subir
      -> Guarda imagen en pedidos.imagenes_locales con auditoría:
         imagenes_locales[index] = { url, modified_by, modified_at }
      -> Recalcula estado automático
      -> Soporta order_id como gid://shopify/Order/xxxx
    ===================================================== */
    public function subirImagen()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false]);
        }

        $orderIdRaw = (string)$this->request->getPost('order_id');
        $orderId = $this->normalizeShopifyOrderId($orderIdRaw);

        $index   = (int) $this->request->getPost('line_index');
        $file    = $this->request->getFile('file');

        // Auditoría (viene del frontend; si no, fallback a sesión)
        $modifiedBy = trim((string) $this->request->getPost('modified_by'));
        $modifiedAt = trim((string) $this->request->getPost('modified_at'));
        if ($modifiedBy === '') $modifiedBy = session('nombre') ?? 'Sistema';
        if ($modifiedAt === '') $modifiedAt = date('c'); // ISO 8601

        if ($orderId === '' || !$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido']);
        }

        $dir = WRITEPATH . 'uploads/confirmacion';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = $file->getRandomName();
        $file->move($dir, $name);
        $url = base_url('writable/uploads/confirmacion/' . $name);

        $db = \Config\Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
        if (!$pedido) {
            // fallback: a veces el pedido se busca por id interno
            $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
        }
        if (!$pedido) return $this->response->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);

        // Asegurar id shopify final (para historial/estado)
        $shopifyId = (string)($pedido['shopify_order_id'] ?? $orderId);

        if ($hasImgLocales) {
            $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
            if (!is_array($imagenes)) $imagenes = [];

            // ✅ Guardar con auditoría
            $imagenes[$index] = [
                'url' => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
            ];

            $db->table('pedidos')
                ->where('id', (int)$pedido['id'])
                ->update(['imagenes_locales' => json_encode($imagenes, JSON_UNESCAPED_SLASHES)]);
        }

        // ✅ Recalcula estado
        $nuevoEstado = $this->validarEstadoAutomatico((int)$pedido['id'], $shopifyId);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
            'modified_by' => $modifiedBy,
            'modified_at' => $modifiedAt,
            'estado' => $nuevoEstado
        ]);
    }

    /* =====================================================
      (OPCIONAL PERO RECOMENDADO)
      POST /api/estado/guardar
      -> Soporta mantener_asignado=true para NO desasignar en "Faltan archivos"
      -> JS lo llama como guardarEstado()
      -> Soporta order_id como gid://shopify/Order/xxxx
    ===================================================== */
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

            $estado  = (string)($payload['estado'] ?? '');
            $mantener = (bool)($payload['mantener_asignado'] ?? false);

            if ($orderId === '' || $estado === '') {
                return $this->response->setStatusCode(400)->setJSON(['success' => false, 'ok' => false, 'message' => 'Payload inválido']);
            }

            $user = session('nombre') ?? 'Sistema';
            $now  = date('Y-m-d H:i:s');

            // Asegurar que exista pedido
            $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
            if (!$pedido) {
                // fallback: si te llega id interno
                $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
            }
            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['success' => false, 'ok' => false, 'message' => 'Pedido no encontrado']);
            }

            $shopifyId = (string)($pedido['shopify_order_id'] ?? $orderId);

            // Upsert pedidos_estado
            $existe = $db->table('pedidos_estado')->where('order_id', $shopifyId)->countAllResults();

            if ($existe) {
                $db->table('pedidos_estado')->where('order_id', $shopifyId)->update([
                    'estado' => $estado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user,
                ]);
            } else {
                $db->table('pedidos_estado')->insert([
                    'order_id' => $shopifyId,
                    'estado' => $estado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user,
                ]);
            }

            // Historial
            $db->table('pedidos_estado_historial')->insert([
                'order_id' => $shopifyId,
                'estado' => $estado,
                'user_name' => $user,
                'created_at' => $now
            ]);

            // ✅ Solo desasignar si Confirmado.
            // Si es "Faltan archivos" y mantener_asignado=true => NO tocar asignación.
            if (mb_strtolower(trim($estado)) === 'confirmado') {
                $db->table('pedidos')
                    ->where('id', (int)$pedido['id'])
                    ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
            } else {
                if (!$mantener) {
                    // Por defecto NO hacemos nada.
                }
            }

            return $this->response->setJSON(['success' => true, 'ok' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'guardarEstado() error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'ok' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* =====================================================
      Estado automático
      - Ahora soporta imagenes_locales como string (viejo) o objeto con auditoría (nuevo)
      - NO desasigna cuando es "Faltan archivos" (solo al Confirmar)
    ===================================================== */
    private function validarEstadoAutomatico(int $pedidoId, string $shopifyId): string
    {
        $db = \Config\Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasPedidoJson = in_array('pedido_json', $pFields, true);
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        if (!$hasPedidoJson || !$hasImgLocales) {
            return 'Por preparar';
        }

        $pedido = $db->table('pedidos')->where('id', $pedidoId)->get()->getRowArray();
        if (!$pedido) return 'Por preparar';

        $order = json_decode($pedido['pedido_json'] ?? '', true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        if (!is_array($imagenes)) $imagenes = [];

        $requeridas = 0;
        $cargadas   = 0;

        foreach (($order['line_items'] ?? []) as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;

                $val = $imagenes[$i] ?? null;
                $url = '';

                if (is_string($val)) {
                    $url = $val;
                } elseif (is_array($val)) {
                    $url = (string)($val['url'] ?? $val['value'] ?? '');
                }

                if (trim($url) !== '') $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas) ? 'Confirmado' : 'Faltan archivos';
        $now = date('Y-m-d H:i:s');
        $user = session('nombre') ?? 'Sistema';

        // Upsert pedidos_estado
        $existe = $db->table('pedidos_estado')->where('order_id', $shopifyId)->countAllResults();
        if ($existe) {
            $db->table('pedidos_estado')
                ->where('order_id', $shopifyId)
                ->update([
                    'estado' => $nuevoEstado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user
                ]);
        } else {
            $db->table('pedidos_estado')->insert([
                'order_id' => $shopifyId,
                'estado' => $nuevoEstado,
                'estado_updated_at' => $now,
                'estado_updated_by_name' => $user
            ]);
        }

        // Historial
        $db->table('pedidos_estado_historial')->insert([
            'order_id' => $shopifyId,
            'estado' => $nuevoEstado,
            'user_name' => $user,
            'created_at' => $now
        ]);

        // ✅ Solo liberar cuando queda confirmado
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

        if (str_contains($title, 'llavero') || str_contains($sku, 'llav')) return true;

        foreach (($item['properties'] ?? []) as $p) {
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', (string)($p['value'] ?? ''))) return true;
        }

        return false;
    }

    /**
     * ✅ Normaliza "gid://shopify/Order/123456" => "123456"
     * Si no coincide, devuelve el string original (trim)
     */
    private function normalizeShopifyOrderId($id): string
    {
        $s = trim((string)$id);
        if ($s === '') return '';
        if (preg_match('~/Order/(\d+)~i', $s, $m)) return (string)$m[1];
        return $s;
    }
}
