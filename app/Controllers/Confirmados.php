<?php

namespace App\Controllers;

class Confirmados extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard');
        }

        return view('confirmados');
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

        // 1) Pedir a Shopify (igual que Dashboard)
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

        $ordersRaw    = $payload['data']['orders'] ?? [];
        $nextPageInfo = $payload['next_page_info'] ?? null;

        // 2) Mapear pedidos (igual que Dashboard)
        $orders = [];
        $ids = [];

        foreach ($ordersRaw as $o) {
            $orderId = $o['id'] ?? null;
            if (!$orderId) continue;

            $ids[] = (string)$orderId;

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
                'estado'       => 'Por preparar', // se reemplaza por estado interno real abajo
                'etiquetas'    => $o['tags'] ?? '',
                'articulos'    => $articulos,
                'estado_envio' => $estado_envio ?: '-',
                'forma_envio'  => $forma_envio ?: '-',
                'last_status_change' => null,
            ];
        }

        if (empty($orders)) {
            return $this->response->setJSON([
                'success' => true,
                'orders' => [],
                'next_page_info' => $nextPageInfo,
                'count' => 0,
            ]);
        }

        // 3) Traer ÚLTIMO estado interno desde BD para todos los IDs de esta página
        $db = \Config\Database::connect();

        // ⚠️ IMPORTANTE: aquí asumimos que la columna se llama "estado"
        // Si se llama distinto (ej: status, estado_pedido), cámbiala en pe.estado
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "
            SELECT pe.id, pe.estado, pe.created_at, pe.user_id
            FROM pedidos_estado pe
            INNER JOIN (
                SELECT id, MAX(created_at) AS mx
                FROM pedidos_estado
                WHERE id IN ($placeholders)
                GROUP BY id
            ) t ON t.id = pe.id AND t.mx = pe.created_at
        ";

        $rows = $db->query($sql, $ids)->getResultArray();

        $ultimo = [];
        foreach ($rows as $r) {
            $ultimo[(string)$r['id']] = $r;
        }

        // 4) Setear estado interno y filtrar SOLO "Preparado"
        $filtrados = [];

        foreach ($orders as $ord) {
            $orderId = (string)($ord['id'] ?? '');
            $row = $ultimo[$orderId] ?? null;

            if ($row) {
                $ord['estado'] = $row['estado'] ?? $ord['estado'];

                // usuario del cambio (igual que tu dashboard)
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

            // ✅ FILTRO REAL: solo "Preparado"
            if (($ord['estado'] ?? '') === 'Preparado') {
                $filtrados[] = $ord;
            }
        }

        return $this->response->setJSON([
            'success'        => true,
            'orders'         => $filtrados,
            'next_page_info' => $nextPageInfo,
            'count'          => count($filtrados),
        ]);
    }
}
