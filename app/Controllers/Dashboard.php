<?php

namespace App\Controllers;

use Config\Database;

class Dashboard extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dashboard');
        }
        return view('dashboard');
    }

    // ============================================================
    // LISTADO: Shopify paginado 50 en 50
    // ============================================================
    public function filter()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ])->setStatusCode(401);
        }

        $limit    = (int) ($this->request->getGet('limit') ?? 50);
        $limit    = ($limit > 0 && $limit <= 250) ? $limit : 50;
        $pageInfo = trim((string) ($this->request->getGet('page_info') ?? ''));

        $shopify = new \App\Controllers\ShopifyController();
        $result  = $shopify->fetchOrdersPage($limit, $pageInfo ?: null);

        if (empty($result['success'])) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $result['error'] ?? 'Error consultando Shopify',
                'debug'   => $result,
            ])->setStatusCode(500);
        }

        $ordersRaw    = $result['orders'] ?? [];
        $nextPageInfo = $result['next_page_info'] ?? null;

        $db = Database::connect();

        // Mapa de "último cambio" desde BD por orderId (más eficiente)
        $lastMap = [];
        if (!empty($ordersRaw)) {
            $ids = array_values(array_filter(array_map(fn($o) => $o['id'] ?? null, $ordersRaw)));
            if (!empty($ids)) {
                // Traemos última fila por id (simple: una query por id es más fácil, pero esto optimiza)
                foreach ($ids as $oid) {
                    $row = $db->table('pedidos_estado')
                        ->select('estado, etiquetas, created_at, user_id')
                        ->where('id', $oid)
                        ->orderBy('created_at', 'DESC')
                        ->limit(1)
                        ->get()
                        ->getRowArray();
                    if ($row) $lastMap[$oid] = $row;
                }
            }
        }

        // Mapear al formato del dashboard
        $orders = [];
        foreach ($ordersRaw as $o) {
            $orderId = $o['id'] ?? null;

            $numero = $o['name'] ?? ('#' . ($o['order_number'] ?? $orderId));
            $fecha  = isset($o['created_at']) ? substr($o['created_at'], 0, 10) : '-';

            $cliente = '-';
            if (!empty($o['customer'])) {
                $cliente = trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''));
                if ($cliente === '') $cliente = '-';
            }

            $total     = isset($o['total_price']) ? ($o['total_price'] . ' €') : '-';
            $articulos = isset($o['line_items']) ? count($o['line_items']) : 0;

            $estado_envio = $o['fulfillment_status'] ?? '-';
            $forma_envio  = (!empty($o['shipping_lines'][0]['title'])) ? $o['shipping_lines'][0]['title'] : '-';

            $tagsShopify = (string)($o['tags'] ?? '');

            // Si hay guardado local, manda el local; si no, usa Shopify
            $last = $orderId && isset($lastMap[$orderId]) ? $lastMap[$orderId] : null;

            $estadoFinal   = $last['estado'] ?? (!empty(trim($tagsShopify)) ? 'Producción' : 'Por preparar');
            $etiquetasFinal = $last['etiquetas'] ?? $tagsShopify;

            // user_name
            $userName = 'Sistema';
            if (!empty($last['user_id'])) {
                $u = $db->table('users')->where('id', $last['user_id'])->get()->getRowArray();
                if ($u && !empty($u['nombre'])) $userName = $u['nombre'];
            }

            $orders[] = [
                'id'           => $orderId,
                'numero'       => $numero,
                'fecha'        => $fecha,
                'cliente'      => $cliente,
                'total'        => $total,
                'estado'       => $estadoFinal,
                'etiquetas'    => $etiquetasFinal,
                'articulos'    => $articulos,
                'estado_envio' => $estado_envio ?: '-',
                'forma_envio'  => $forma_envio ?: '-',
                'last_status_change' => $last ? [
                    'user_name'  => $userName,
                    'changed_at' => $last['created_at'] ?? null,
                ] : null,
            ];
        }

        return $this->response->setJSON([
            'success'        => true,
            'orders'         => $orders,
            'next_page_info' => $nextPageInfo,
            'count'          => count($orders),
        ]);
    }

    // ============================================================
    // MODAL: Guardar estado (BD)
    // ============================================================
    public function saveEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false, 'message' => 'No autenticado'])->setStatusCode(401);
        }

        $json = $this->request->getJSON(true);
        $id = $json['id'] ?? null;
        $estado = trim((string)($json['estado'] ?? ''));

        if (!$id || $estado === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Falta id o estado'])->setStatusCode(400);
        }

        $db = Database::connect();

        $db->table('pedidos_estado')->insert([
            'id'        => $id,
            'estado'    => $estado,
            'etiquetas' => $json['etiquetas'] ?? null, // opcional
            'user_id'   => session()->get('user_id') ?? null,
            'created_at'=> date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON(['success' => true]);
    }

    // ============================================================
    // MODAL: Guardar etiquetas (BD + opcional Shopify)
    // ============================================================
    public function saveEtiquetas()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false, 'message' => 'No autenticado'])->setStatusCode(401);
        }

        $json = $this->request->getJSON(true);
        $id = $json['id'] ?? null;
        $tags = trim((string)($json['tags'] ?? ''));

        if (!$id) {
            return $this->response->setJSON(['success' => false, 'message' => 'Falta id'])->setStatusCode(400);
        }

        $db = Database::connect();

        // Guarda historial en BD
        $db->table('pedidos_estado')->insert([
            'id'        => $id,
            'estado'    => $json['estado'] ?? null, // opcional
            'etiquetas' => $tags,
            'user_id'   => session()->get('user_id') ?? null,
            'created_at'=> date('Y-m-d H:i:s'),
        ]);

        // Opcional: actualizar Shopify tags
        if (!empty($json['sync_shopify'])) {
            $shopify = new \App\Controllers\ShopifyController();
            // llamamos al método endpoint (pero sin request), mejor llamar directo a API:
            // aquí hacemos request por controller endpoint: usamos el método updateOrderTags por HTTP en JS normalmente
            // pero para que sea simple: llamamos al método privado NO se puede.
            // Alternativa: que el JS llame /shopify/updateOrderTags. (te lo dejo en dashboard.js)
        }

        return $this->response->setJSON(['success' => true]);
    }

    // ============================================================
    // MODAL: Detalle pedido (Shopify)
    // ============================================================
    public function getOrderDetail($id)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false, 'message' => 'No autenticado'])->setStatusCode(401);
        }

        $shopify = new \App\Controllers\ShopifyController();
        // usar requestRaw no es público, así que pedimos por endpoint de ShopifyController:
        // más simple: llama /shopify/getOrder/{id} desde JS. (te lo dejo en dashboard.js)
        return $this->response->setJSON([
            'success' => false,
            'message' => 'Usa /shopify/getOrder/{id} desde el JS para detalle'
        ])->setStatusCode(400);
    }
}
