<?php

namespace App\Controllers;

use Config\Database;

class DashboardController extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }
        return view('dashboard');
    }

    // ✅ Shopify 50 en 50
    public function filter()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ])->setStatusCode(401);
        }

        $limit    = (int) ($this->request->getGet('limit') ?? 50);
        if ($limit <= 0 || $limit > 250) $limit = 50;

        $pageInfo = trim((string) ($this->request->getGet('page_info') ?? ''));

        $shopify = new \App\Controllers\ShopifyController();
        $result  = $shopify->fetchOrdersPage($limit, $pageInfo !== '' ? $pageInfo : null);

        // 👇 IMPORTANTE: si Shopify está bloqueado, aquí te saldrá claro
        if (empty($result['success'])) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $result['error'] ?? 'Error consultando Shopify',
                'status'  => $result['status'] ?? null,
                'url'     => $result['url'] ?? null,
            ])->setStatusCode(500);
        }

        $ordersRaw    = $result['orders'] ?? [];
        $nextPageInfo = $result['next_page_info'] ?? null;

        // Si no hay pedidos, devolvemos igual el debug mínimo
        if (empty($ordersRaw)) {
            return $this->response->setJSON([
                'success'        => true,
                'orders'         => [],
                'next_page_info' => $nextPageInfo,
                'count'          => 0,
                'debug'          => [
                    'hint' => 'Si Shopify da orders vacío: revisa scopes read_orders o si hay pedidos en la tienda.',
                ]
            ]);
        }

        // Mapeo al formato que tu dashboard usa
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
                'last_status_change' => null,
            ];
        }

        // (Opcional) Traer último cambio desde BD como en tu versión
        $db = Database::connect();
        foreach ($orders as &$ord) {
            $oid = $ord['id'] ?? null;
            if (!$oid) continue;

            $row = $db->table('pedidos_estado')
                ->select('created_at, user_id')
                ->where('id', $oid)
                ->orderBy('created_at', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();

            if (!$row) continue;

            $userName = 'Sistema';
            if (!empty($row['user_id'])) {
                $u = $db->table('users')->where('id', $row['user_id'])->get()->getRowArray();
                if ($u && !empty($u['nombre'])) $userName = $u['nombre'];
            }

            $ord['last_status_change'] = [
                'user_name'  => $userName,
                'changed_at' => $row['created_at'] ?? null,
            ];
        }
        unset($ord);

        return $this->response->setJSON([
            'success'        => true,
            'orders'         => $orders,
            'next_page_info' => $nextPageInfo,
            'count'          => count($orders),
        ]);
    }
}
