<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        
        return "PRODUCCION EN PROCESO...";
    }

    public function filter()
    {
        return $this->response->setJSON(['success' => true]);
    }
}

    $pageInfo = $this->request->getGet('page_info');
    $limit = 50;

    // =====================================================
    // 1) Traer pedidos desde Shopify (sin usar ShopifyController)
    // =====================================================
    $shop  = getenv('SHOPIFY_STORE_DOMAIN') ?: env('SHOPIFY_STORE_DOMAIN');
    $token = getenv('SHOPIFY_ADMIN_TOKEN') ?: env('SHOPIFY_ADMIN_TOKEN');

    if (!$shop || !$token) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Faltan credenciales Shopify'
        ]);
    }

    $url = "https://{$shop}/admin/api/2025-01/orders.json?limit={$limit}&status=any&fields=id,name,order_number,created_at,total_price,customer,line_items,tags,fulfillment_status,shipping_lines";

    if ($pageInfo) {
        $url .= "&page_info=" . urlencode($pageInfo);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            "X-Shopify-Access-Token: {$token}",
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Error cURL Shopify',
            'error' => $err
        ]);
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersRaw = substr($resp, 0, $headerSize);
    $bodyRaw = substr($resp, $headerSize);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Error Shopify',
            'status' => $status,
            'body' => substr($bodyRaw, 0, 800)
        ]);
    }

    $json = json_decode($bodyRaw, true);
    $ordersRaw = $json['orders'] ?? [];

    // next page_info
    $nextPageInfo = null;
    if (preg_match('/<[^>]*page_info=([^&>]*)[^>]*>; rel="next"/', $headersRaw, $m)) {
        $nextPageInfo = urldecode($m[1]);
    }

    // =====================================================
    // 2) Mapear pedidos Shopify
    // =====================================================
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
            'estado'       => 'Por preparar', // se reemplaza por estado interno
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
            'count' => 0
        ]);
    }

    // =====================================================
    // 3) Leer ÚLTIMO estado interno desde BD (pedidos_estado)
    // =====================================================
    $db = \Config\Database::connect();

    // ⚠️ CAMBIA AQUÍ si tu columna no se llama "estado"
    $colEstado = 'estado';

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT pe.id, pe.{$colEstado} AS estado, pe.created_at, pe.user_id
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

    // =====================================================
    // 4) Asignar estado interno y FILTRAR internamente por "Preparado"
    // =====================================================
    $filtrados = [];
    foreach ($orders as $ord) {
        $orderId = (string)$ord['id'];
        $row = $ultimo[$orderId] ?? null;

        if ($row) {
            $ord['estado'] = $row['estado'] ?? $ord['estado'];

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

        // ✅ FILTRO INTERNO REAL
        if (($ord['estado'] ?? '') === 'Preparado') {
            $filtrados[] = $ord;
        }
    }

    return $this->response->setJSON([
        'success' => true,
        'orders' => $filtrados,
        'next_page_info' => $nextPageInfo,
        'count' => count($filtrados),
    ]);
