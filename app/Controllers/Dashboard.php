<?php

namespace App\Controllers;

use Config\Database;

class Dashboard extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard');
        }

        return view('dashboard');
    }

    public function filter()
{
    if (!session()->get('logged_in')) {
        return $this->response->setJSON([
            'success' => false,
            'message' => 'No autenticado'
        ])->setStatusCode(401);
    }

    $pageInfo = $this->request->getGet('page_info');

    // 1) Pedir a Shopify
    $shopify = new \App\Controllers\ShopifyController();

    $_GET['limit'] = 50;
    if ($pageInfo) $_GET['page_info'] = $pageInfo;

    $shopifyResp = $shopify->getOrders();
    $payload = json_decode($shopifyResp->getBody(), true);

    if (!$payload || empty($payload['success'])) {
        return $this->response->setJSON([
            'success' => false,
            'message' => $payload['error'] ?? 'Error consultando Shopify',
            'debug'   => $payload ?? null,
        ])->setStatusCode(500);
    }

    $ordersRaw     = $payload['orders'] ?? [];
    $nextPageInfo  = $payload['next_page_info'] ?? null;

    // 2) Mapear al formato del dashboard
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

        $total = isset($o['total_price']) ? ($o['total_price'] . ' €') : '-';
        $articulos = isset($o['line_items']) ? count($o['line_items']) : 0;

        $estado_envio = $o['fulfillment_status'] ?? '-';
        $forma_envio  = (!empty($o['shipping_lines'][0]['title'])) ? $o['shipping_lines'][0]['title'] : '-';

        $orders[] = [
            'id'           => $orderId,
            'numero'       => $numero,
            'fecha'        => $fecha,
            'cliente'      => $cliente,
            'total'        => $total,
            'estado'       => (!empty($o['tags']) ? 'Producción' : 'Por preparar'),
            'etiquetas'    => $o['tags'] ?? '',
            'articulos'    => $articulos,
            'estado_envio' => $estado_envio ?: '-',
            'forma_envio'  => $forma_envio ?: '-',
            'last_status_change' => null, // se setea abajo
        ];
    }

        // 3) Traer último cambio desde BD
        $db = \Config\Database::connect();

        foreach ($orders as &$ord) {

            $orderId = $ord['id'] ?? null; // ID Shopify
            if (!$orderId) {
                $ord['last_status_change'] = null;
                continue;
            }

            $row = $db->table('pedidos_estado')
                ->select('created_at, user_id')
                ->where('id', $orderId)   // 👈 CLAVE
                ->orderBy('created_at', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();

            $userName = 'Sistema';
            if (!empty($row['user_id'])) {
                $u = $db->table('users')->where('id', $row['user_id'])->get()->getRowArray();
                if ($u) $userName = $u['nombre'];
            }

            $ord['last_status_change'] = [
                'user_name'  => $userName,
                'changed_at' => $row['created_at'] ?? null,
            ];
        }
        unset($ord);


    // 4) Responder
    return $this->response->setJSON([
        'success'        => true,
        'orders'         => $orders,
        'next_page_info' => $nextPageInfo,
        'count'          => count($orders),
    ]);
}

}
