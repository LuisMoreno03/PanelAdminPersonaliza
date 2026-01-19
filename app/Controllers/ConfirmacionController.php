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
    ===================================================== */
    public function myQueue()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
            }

            $userId = (int) session('user_id');
            $db = \Config\Database::connect();

            // columnas de pedidos
            $pedidoFields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $pedidoFields, true);
            $hasFulfillment = in_array('fulfillment_status', $pedidoFields, true);

            // columnas de pedidos_estado
            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUserName = in_array('user_name', $peFields, true);
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            $q = $db->table('pedidos p')
                ->select("p.*, COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect", false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar');

            // ✅ FILTRO: SOLO UNFULFILLED (NULL / '' / unfulfilled)
            if ($hasEstadoEnvio) {
                $q->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();
            } elseif ($hasFulfillment) {
                $q->groupStart()
                    ->where('p.fulfillment_status IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.fulfillment_status,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'", null, false)
                ->groupEnd();
            }

            $rows = $q->orderBy('p.created_at', 'ASC')->get()->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
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
                return $this->response->setStatusCode(401)->setJSON([
                    'ok' => false,
                    'message' => 'No auth'
                ]);
            }

            $userId = (int) session('user_id');
            $user   = session('nombre') ?? 'Sistema';

            $payload = $this->request->getJSON(true) ?? [];
            $count   = (int) ($payload['count'] ?? 5);
            if ($count <= 0) $count = 5;

            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $pedidoFields = $db->getFieldNames('pedidos') ?? [];
            $hasFulfillment = in_array('fulfillment_status', $pedidoFields, true);
            $hasEstadoEnvio = in_array('estado_envio', $pedidoFields, true);

            $q = $db->table('pedidos p')
                ->select('p.id, p.shopify_order_id')
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar')
                ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)', null, false);

            // ✅ SOLO NO ENVIADOS (NULL / '' / unfulfilled)
            if ($hasFulfillment) {
                $q->groupStart()
                    ->where('p.fulfillment_status IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.fulfillment_status,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'", null, false)
                ->groupEnd();
            } elseif ($hasEstadoEnvio) {
                $q->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();
            } else {
                log_message('warning', 'pull(): no existe fulfillment_status ni estado_envio en pedidos');
            }

            $candidatos = $q->orderBy('p.created_at', 'ASC')
                ->limit($count)
                ->get()
                ->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'message' => 'Sin candidatos'
                ]);
            }

            $ids = array_column($candidatos, 'id');

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            foreach ($candidatos as $c) {
                if (empty($c['shopify_order_id'])) continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => $c['shopify_order_id'],
                    'estado'     => 'Por preparar',
                    'user_name'  => $user,
                    'created_at' => $now
                ]);
            }

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids)
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'pull() error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
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
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        return $this->response->setJSON(['ok' => true]);
    }

    /* =====================================================
      GET /confirmacion/detalles/{id}
    ===================================================== */
    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ]);
        }

        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'ID inválido'
            ]);
        }

        try {
            $db = \Config\Database::connect();

            $pedido = $db->table('pedidos')
                ->groupStart()
                    ->where('id', $id)
                    ->orWhere('shopify_order_id', $id)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado'
                ]);
            }

            $orderJson = json_decode($pedido['pedido_json'] ?? '', true);

            if (empty($orderJson)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Pedido sin JSON guardado'
                ]);
            }

            $imagenesLocales = json_decode($pedido['imagenes_locales'] ?? '[]', true);
            $productImages   = json_decode($pedido['product_images'] ?? '{}', true);

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
                'product_images' => is_array($productImages) ? $productImages : [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion detalles ERROR: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* =====================================================
      POST /api/pedidos/imagenes/subir
    ===================================================== */
    public function subirImagen()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false]);
        }

        $orderId = $this->request->getPost('order_id');
        $index   = (int) $this->request->getPost('line_index');
        $file    = $this->request->getFile('file');

        if (!$orderId || !$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido']);
        }

        $name = $file->getRandomName();
        $file->move(WRITEPATH . 'uploads/confirmacion', $name);

        $url = base_url('writable/uploads/confirmacion/' . $name);

        $db = \Config\Database::connect();
        $pedido = $db->table('pedidos')
            ->where('shopify_order_id', $orderId)
            ->get()
            ->getRowArray();

        if (!$pedido) {
            return $this->response->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);
        }

        $imagenes = json_decode($pedido['imagenes_locales'] ?? '[]', true);
        if (!is_array($imagenes)) $imagenes = [];
        $imagenes[$index] = $url;

        $db->table('pedidos')
            ->where('shopify_order_id', $orderId)
            ->update(['imagenes_locales' => json_encode($imagenes)]);

        $this->validarEstadoAutomatico((int)$pedido['id'], (string)$pedido['shopify_order_id']);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url
        ]);
    }

    /* =====================================================
      ESTADO AUTOMÁTICO (con UPSERT + soporta REST/GraphQL)
    ===================================================== */
    private function validarEstadoAutomatico(int $pedidoId, string $shopifyId)
    {
        $db = \Config\Database::connect();

        $pedido = $db->table('pedidos')->where('id', $pedidoId)->get()->getRowArray();
        if (!$pedido) return;

        $order = json_decode($pedido['pedido_json'] ?? '', true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '[]', true);
        if (!is_array($imagenes)) $imagenes = [];

        $items = [];

        // REST
        if (!empty($order['line_items']) && is_array($order['line_items'])) {
            $items = $order['line_items'];
        }
        // GraphQL
        elseif (!empty($order['lineItems']['edges']) && is_array($order['lineItems']['edges'])) {
            foreach ($order['lineItems']['edges'] as $edge) {
                if (!empty($edge['node'])) $items[] = $edge['node'];
            }
        }

        $requeridas = 0;
        $cargadas   = 0;

        foreach ($items as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;
                if (!empty($imagenes[$i])) $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas)
            ? 'Confirmado'
            : 'Faltan archivos';

        // ✅ UPSERT en pedidos_estado (si no existe fila, la crea)
        $existe = $db->table('pedidos_estado')
            ->where('order_id', $shopifyId)
            ->countAllResults();

        $dataEstado = [
            'order_id' => $shopifyId,
            'estado' => $nuevoEstado,
            'estado_updated_at' => date('Y-m-d H:i:s'),
            'estado_updated_by_name' => session('nombre') ?? 'Sistema'
        ];

        if ($existe) {
            $db->table('pedidos_estado')->where('order_id', $shopifyId)->update($dataEstado);
        } else {
            // si tu tabla NO tiene estas columnas extra, quítalas aquí.
            $db->table('pedidos_estado')->insert($dataEstado);
        }

        // Confirmado → liberar pedido
        if ($nuevoEstado === 'Confirmado') {
            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null
                ]);
        }
    }

    /* =====================================================
      REGLAS IMAGEN (soporta REST + GraphQL)
    ===================================================== */
    private function requiereImagen(array $item): bool
    {
        $title = strtolower((string)($item['title'] ?? ''));
        $sku   = strtolower((string)($item['sku'] ?? ''));

        if (str_contains($title, 'llavero') || str_contains($sku, 'llav')) {
            return true;
        }

        // REST: properties = array de ['name','value']
        if (!empty($item['properties']) && is_array($item['properties'])) {
            foreach ($item['properties'] as $p) {
                $val = (string)($p['value'] ?? '');
                if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', $val)) return true;
            }
        }

        // GraphQL: customAttributes = array de ['key','value']
        if (!empty($item['customAttributes']) && is_array($item['customAttributes'])) {
            foreach ($item['customAttributes'] as $p) {
                $val = (string)($p['value'] ?? '');
                if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', $val)) return true;
            }
        }

        return false;
    }
}
