<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion');
    }

    /** =====================================================
     * Helper: normaliza gid://shopify/Order/123 => 123
     * ===================================================== */
    private function normalizeShopifyOrderId($id): string
    {
        $s = trim((string)$id);
        if ($s === '') return '';
        if (preg_match('~/Order/(\d+)~i', $s, $m)) return (string)$m[1];
        return $s;
    }

    /** =====================================================
     * Helper: clave consistente del pedido para estados/historial
     * - si hay shopify_order_id => usarlo
     * - si no => usar id interno
     * ===================================================== */
    private function orderKeyFromPedido(array $pedido): string
    {
        $sid = trim((string)($pedido['shopify_order_id'] ?? ''));
        if ($sid !== '' && $sid !== '0') return $sid;
        return (string)($pedido['id'] ?? '');
    }

    /* =====================================================
      GET /confirmacion/my-queue
      -> SOLO pedidos asignados a mi user
      -> SOLO "Por preparar" O "Faltan archivos"
      -> SOLO NO ENVIADOS (unfulfilled)
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
            $hasShopifyId = in_array('shopify_order_id', $pFields, true);

            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);
            $hasPeUserName  = in_array('user_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            // ✅ orderKeySql portable según driver
            $driver = strtolower((string)($db->DBDriver ?? ''));

            if ($hasShopifyId) {
                if (str_contains($driver, 'mysql')) {
                    // MySQL/MariaDB: evita CAST AS CHAR; CONCAT fuerza string
                    $orderKeySql = "COALESCE(NULLIF(TRIM(p.shopify_order_id),''), CONCAT(p.id,''))";
                } else {
                    // PostgreSQL/SQLite/otros: usar TEXT
                    $orderKeySql = "COALESCE(NULLIF(TRIM(CAST(p.shopify_order_id AS TEXT)),''), CAST(p.id AS TEXT))";
                }
            } else {
                $orderKeySql = str_contains($driver, 'mysql')
                    ? "CONCAT(p.id,'')"
                    : "CAST(p.id AS TEXT)";
            }

            $q = $db->table('pedidos p')
                ->select(
                    "p.id, " .
                    ($hasShopifyId ? "p.shopify_order_id, " : "NULL as shopify_order_id, ") .
                    "p.numero, p.cliente, p.total, p.estado_envio, p.forma_envio, p.etiquetas, p.articulos, p.created_at, " .
                    "COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect",
                    false
                )
                ->join('pedidos_estado pe', "pe.order_id = $orderKeySql", 'left', false)
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por preparar','faltan archivos')", null, false)
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();

            // (Debug opcional mientras pruebas)
            // log_message('error', 'myQueue SQL: '.$q->getCompiledSelect(false));

            $rows = $q->orderBy('p.created_at', 'ASC')->get()->getResultArray();

            return $this->response->setJSON([
                'ok'   => true,
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }


    /* =====================================================
      POST /confirmacion/pull
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
            if ($count <= 0) $count = 5;

            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId = in_array('shopify_order_id', $pFields, true);
            if (!$hasShopifyId) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => 'La tabla pedidos no tiene la columna shopify_order_id.'
                ]);
            }

            $db->transStart();

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

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids)
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'pull() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage()
            ]);
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
            $productImages   = $hasProdImages ? json_decode($pedido['product_images'] ?? '{}', true) : [];

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
                'product_images' => is_array($productImages) ? $productImages : [],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'detalles() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function subirImagen()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false]);
        }

        $orderIdRaw = (string)$this->request->getPost('order_id');
        $orderId = $this->normalizeShopifyOrderId($orderIdRaw);

        $index   = (int) $this->request->getPost('line_index');
        $file    = $this->request->getFile('file');

        $modifiedBy = trim((string) $this->request->getPost('modified_by'));
        $modifiedAt = trim((string) $this->request->getPost('modified_at'));
        if ($modifiedBy === '') $modifiedBy = session('nombre') ?? 'Sistema';
        if ($modifiedAt === '') $modifiedAt = date('c');

        if ($orderId === '' || !$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido']);
        }

        $dir = WRITEPATH . 'uploads/confirmacion';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $name = $file->getRandomName();
        $file->move($dir, $name);
        $url = base_url('writable/uploads/confirmacion/' . $name);

        $db = \Config\Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
        if (!$pedido) $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
        if (!$pedido) return $this->response->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);

        $orderKey = $this->orderKeyFromPedido($pedido);

        if ($hasImgLocales) {
            $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
            if (!is_array($imagenes)) $imagenes = [];

            $imagenes[$index] = [
                'url' => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
            ];

            $db->table('pedidos')
                ->where('id', (int)$pedido['id'])
                ->update(['imagenes_locales' => json_encode($imagenes, JSON_UNESCAPED_SLASHES)]);
        }

        $nuevoEstado = $this->validarEstadoAutomatico((int)$pedido['id'], $orderKey);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
            'modified_by' => $modifiedBy,
            'modified_at' => $modifiedAt,
            'estado' => $nuevoEstado
        ]);
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

            $estado  = (string)($payload['estado'] ?? '');
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

            // ✅ usar SIEMPRE la misma clave para estados/historial
            $orderKey = $this->orderKeyFromPedido($pedido);

            // Upsert pedidos_estado
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

            // Historial
            $db->table('pedidos_estado_historial')->insert([
                'order_id' => $orderKey,
                'estado' => $estado,
                'user_name' => $user,
                'created_at' => $now
            ]);

            $estadoLower = mb_strtolower(trim($estado));

            // ✅ Confirmado => desasignar
            if ($estadoLower === 'confirmado') {
                $db->table('pedidos')
                    ->where('id', (int)$pedido['id'])
                    ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
            }

            // ✅ Cancelado => desasignar SIEMPRE (para que se quite de tu lista)
            if ($estadoLower === 'cancelado') {
                $db->table('pedidos')
                    ->where('id', (int)$pedido['id'])
                    ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
            }

            // "Faltan archivos" con mantener_asignado=true => no tocar asignación (ok)
            // Otros estados: por defecto no hacemos nada.

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

                if (is_string($val)) $url = $val;
                elseif (is_array($val)) $url = (string)($val['url'] ?? $val['value'] ?? '');

                if (trim($url) !== '') $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas) ? 'Confirmado' : 'Faltan archivos';
        $now = date('Y-m-d H:i:s');
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

        if (str_contains($title, 'llavero') || str_contains($sku, 'llav')) return true;

        foreach (($item['properties'] ?? []) as $p) {
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', (string)($p['value'] ?? ''))) return true;
        }

        return false;
    }
}
