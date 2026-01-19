<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    /* =====================================================
      INDEX
    ===================================================== */
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

            // ✅ Detectar columnas reales para no romper
            $hasEstadoEnvio = $db->fieldExists('estado_envio', 'pedidos');
            $hasFulfillment = $db->fieldExists('fulfillment_status', 'pedidos');

            // pedidos_estado columnas posibles
            $hasPeUserName = $db->fieldExists('user_name', 'pedidos_estado');
            $hasPeUpdatedBy = $db->fieldExists('estado_updated_by_name', 'pedidos_estado');

            // ✅ Elegir columna correcta para "estado_por"
            if ($hasPeUpdatedBy) {
                $estadoPorSelect = 'pe.estado_updated_by_name as estado_por';
            } elseif ($hasPeUserName) {
                $estadoPorSelect = 'pe.user_name as estado_por';
            } else {
                $estadoPorSelect = 'NULL as estado_por';
            }

            $q = $db->table('pedidos p')
                ->select("p.*, COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect", false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar');

            // ✅ FILTRO: SOLO UNFULFILLED (si existe la columna)
            if ($hasEstadoEnvio) {
                $q->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio))", 'unfulfilled')
                ->groupEnd();
            } elseif ($hasFulfillment) {
                $q->groupStart()
                    ->where('p.fulfillment_status IS NULL', null, false)
                    ->orWhere("LOWER(TRIM(p.fulfillment_status))", 'unfulfilled')
                ->groupEnd();
            }

            $rows = $q->orderBy('p.created_at', 'ASC')
                ->get()
                ->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage());

            // ✅ para que lo veas en Network > Response
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

            // ✅ detectar columnas SIN fieldExists (más estable)
            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasFulfillment = in_array('fulfillment_status', $fields, true);
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);

            $q = $db->table('pedidos p')
                ->select('p.id, p.shopify_order_id')
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar')
                ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)');

            // ✅ SOLO pedidos NO enviados (Shopify: NULL / '' / unfulfilled)
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
            log_message('error', 'pull() error: {msg} in {file}:{line}', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
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
        if ($userId <= 0) {
            return $this->response->setJSON(['ok' => false]);
        }

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

        // 1️⃣ Buscar pedido por ID interno o Shopify ID
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

        // 2️⃣ Usar SIEMPRE el JSON guardado (el mismo del dashboard)
        $orderJson = json_decode($pedido['pedido_json'] ?? '', true);

        if (
            empty($orderJson) ||
            (
                empty($orderJson['line_items']) &&
                empty($orderJson['lineItems'])
            )
        ) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Pedido sin productos'
            ]);
        }

        // 3️⃣ Cargar imágenes locales y de producto
        $imagenesLocales = json_decode($pedido['imagenes_locales'] ?? '[]', true);
        $productImages  = json_decode($pedido['product_images'] ?? '{}', true);

        return $this->response->setJSON([
            'success' => true,
            'order' => $orderJson,              // 👈 PEDIDO COMPLETO
            'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
            'product_images' => is_array($productImages) ? $productImages : [],
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'Confirmacion detalles ERROR: ' . $e->getMessage());

        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Error interno cargando detalles'
        ]);
    }
}


    /* =====================================================
      🔥 POST /api/pedidos/imagenes/subir
      (USADO POR CONFIRMACIÓN)
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
            return $this->response->setJSON(['success' => false]);
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
            return $this->response->setJSON(['success' => false]);
        }

        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        $imagenes[$index] = $url;

        $db->table('pedidos')
            ->where('shopify_order_id', $orderId)
            ->update(['imagenes_locales' => json_encode($imagenes)]);

        // 🔁 VALIDAR ESTADO AUTOMÁTICO
        $this->validarEstadoAutomatico($pedido['id'], $pedido['shopify_order_id']);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url
        ]);
    }

    /* =====================================================
      🧠 ESTADO AUTOMÁTICO REAL
    ===================================================== */
    private function validarEstadoAutomatico($pedidoId, $shopifyId)
    {
        $db = \Config\Database::connect();

        $pedido = $db->table('pedidos')
            ->where('id', $pedidoId)
            ->get()
            ->getRowArray();

        if (!$pedido) return;

        $order = json_decode($pedido['pedido_json'], true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);

        $requeridas = 0;
        $cargadas   = 0;

        foreach ($order['line_items'] as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;
                if (!empty($imagenes[$i])) {
                    $cargadas++;
                }
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas)
            ? 'Confirmado'
            : 'Faltan archivos';

        // Guardar estado
        $db->table('pedidos_estado')
            ->where('order_id', $shopifyId)
            ->update([
                'estado' => $nuevoEstado,
                'estado_updated_at' => date('Y-m-d H:i:s'),
                'estado_updated_by_name' => session('nombre') ?? 'Sistema'
            ]);

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
      REGLAS IMAGEN
    ===================================================== */
    private function requiereImagen(array $item): bool
    {
        $title = strtolower($item['title'] ?? '');
        $sku   = strtolower($item['sku'] ?? '');

        if (str_contains($title, 'llavero') || str_contains($sku, 'llav')) {
            return true;
        }

        foreach ($item['properties'] ?? [] as $p) {
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', (string)($p['value'] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
