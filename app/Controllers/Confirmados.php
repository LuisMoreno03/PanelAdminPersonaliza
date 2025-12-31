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
    $pageInfo = $this->request->getGet('page_info'); // cursor
    $limit = 50;

    $shop  = getenv('SHOPIFY_STORE_DOMAIN') ?: env('SHOPIFY_STORE_DOMAIN');
    $token = getenv('SHOPIFY_ADMIN_TOKEN') ?: env('SHOPIFY_ADMIN_TOKEN');

    if (!$shop || !$token) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'error' => 'Faltan credenciales Shopify (SHOPIFY_STORE_DOMAIN / SHOPIFY_ADMIN_TOKEN).'
        ]);
    }

    // ✅ Query de Shopify (búsqueda)
    // Si tu tag real es otro, cambia aquí.
    $searchQuery = 'status:any (tag:Preparado OR tag:Preparados OR tag:preparado OR tag:PREPARADO)';

    $endpoint = "https://{$shop}/admin/api/2025-01/graphql.json";

    $afterPart = $pageInfo ? ', after: "' . addslashes($pageInfo) . '"' : '';

    $gql = <<<GQL
    {
      orders(first: {$limit}{$afterPart}, query: "{$searchQuery}") {
        edges {
          cursor
          node {
            id
            name
            createdAt
            totalPriceSet { shopMoney { amount currencyCode } }
            displayFulfillmentStatus
            tags
            lineItems(first: 1) { edges { node { id } } }
            customer { firstName lastName }
          }
        }
        pageInfo { hasNextPage }
      }
    }
    GQL;

    $payload = json_encode(['query' => $gql]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "X-Shopify-Access-Token: {$token}",
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'error' => 'cURL error',
            'details' => $err
        ]);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);

    if ($status >= 400 || !is_array($json)) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'error' => 'Error Shopify GraphQL',
            'status' => $status,
            'body' => substr($raw, 0, 1200),
        ]);
    }

    if (!empty($json['errors'])) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'error' => 'GraphQL errors',
            'errors' => $json['errors'],
        ]);
    }

    $edges = $json['data']['orders']['edges'] ?? [];
    $hasNext = $json['data']['orders']['pageInfo']['hasNextPage'] ?? false;

    $orders = [];
    $nextCursor = null;

    foreach ($edges as $edge) {
        $node = $edge['node'] ?? [];
        $nextCursor = $edge['cursor'] ?? $nextCursor;

        $cliente = '-';
        if (!empty($node['customer'])) {
            $fn = $node['customer']['firstName'] ?? '';
            $ln = $node['customer']['lastName'] ?? '';
            $cliente = trim($fn . ' ' . $ln) ?: '-';
        }

        $money = $node['totalPriceSet']['shopMoney'] ?? null;
        $total = $money ? ($money['amount'] . ' ' . $money['currencyCode']) : '-';

        $orders[] = [
            'id' => $node['id'] ?? '',
            'numero' => $node['name'] ?? '-',
            'fecha' => $node['createdAt'] ?? '-',
            'cliente' => $cliente,
            'total' => $total,
            'estado' => $node['displayFulfillmentStatus'] ?? '-',
            'etiquetas' => !empty($node['tags']) ? implode(',', $node['tags']) : '',
            'articulos' => 0, // si quieres conteo real, se puede pedir lineItems(first: 250) y contar
            'estado_envio' => $node['displayFulfillmentStatus'] ?? '',
            'forma_envio' => '',
        ];
    }

    return $this->response->setJSON([
        'success' => true,
        'orders' => $orders,
        'next_page_info' => ($hasNext ? $nextCursor : null),
    ]);
}
}
