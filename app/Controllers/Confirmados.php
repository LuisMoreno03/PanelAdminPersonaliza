<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class Confirmados extends BaseController
{
    public function index()
    {
        return view('confirmados');
    }

    public function filter()
{
    $pageInfo = $this->request->getGet('page_info');
    $limit = 50;

    $shop = env('SHOPIFY_STORE_DOMAIN');
    $token = env('SHOPIFY_ADMIN_TOKEN');

    if (!$shop || !$token) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'error' => 'Faltan credenciales Shopify (SHOPIFY_STORE_DOMAIN / SHOPIFY_ADMIN_TOKEN).'
        ]);
    }

    // ✅ Filtrar por etiqueta Preparado
    $query = 'status:any tag:Preparado';

    // URL base (primera carga)
    $url = "https://{$shop}/admin/api/2025-01/orders.json"
        . "?limit={$limit}"
        . "&status=any"
        . "&fields=id,name,created_at,total_price,currency,fulfillment_status,tags,line_items,customer"
        . "&query=" . urlencode($query);

    // Paginación (cuando viene page_info)
    if ($pageInfo) {
        $url = "https://{$shop}/admin/api/2025-01/orders.json"
            . "?limit={$limit}"
            . "&page_info=" . urlencode($pageInfo);
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
            'error' => 'cURL error',
            'details' => $err
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
            'error' => 'Error Shopify',
            'status' => $status,
            'body' => $bodyRaw,
        ]);
    }

    $json = json_decode($bodyRaw, true);
    if (!is_array($json)) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'error' => 'Respuesta Shopify inválida (no JSON)',
            'body' => substr($bodyRaw, 0, 500),
        ]);
    }

    $ordersRaw = $json['orders'] ?? [];

    // next page_info
    $nextPageInfo = null;
    if (preg_match('/<[^>]*page_info=([^&>]*)[^>]*>; rel="next"/', $headersRaw, $m)) {
        $nextPageInfo = urldecode($m[1]);
    }

    $orders = array_map(function ($o) {
        $cliente = '-';
        if (!empty($o['customer'])) {
            $fn = $o['customer']['first_name'] ?? '';
            $ln = $o['customer']['last_name'] ?? '';
            $cliente = trim($fn . ' ' . $ln) ?: '-';
        }

        return [
            'id' => $o['id'],
            'numero' => $o['name'] ?? '-',
            'fecha' => $o['created_at'] ?? '-',
            'cliente' => $cliente,
            'total' => ($o['total_price'] ?? '-') . ' ' . ($o['currency'] ?? ''),
            'estado' => $o['fulfillment_status'] ?? '-',
            'etiquetas' => $o['tags'] ?? '',
            'articulos' => isset($o['line_items']) ? count($o['line_items']) : 0,
            'estado_envio' => $o['fulfillment_status'] ?? '',
            'forma_envio' => '',
        ];
    }, $ordersRaw);

    return $this->response->setJSON([
        'success' => true,
        'orders' => $orders,
        'next_page_info' => $nextPageInfo,
    ]);
}

}
