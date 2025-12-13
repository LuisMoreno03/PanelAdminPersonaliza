<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private string $shop  = '962f2d.myshopify.com';
    private string $token = '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'; // mover a .env

    /* ============================================================
       GRAPHQL REQUEST
    ============================================================ */
    private function graphQL(string $query, array $variables = [])
    {
        $ch = curl_init("https://{$this->shop}/admin/api/2024-01/graphql.json");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'query' => $query,
                'variables' => $variables
            ])
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /* ============================================================
       AJAX – PEDIDOS (TODOS)
    ============================================================ */
    public function filter()
{
    $cursor = $this->request->getGet('cursor');

    $query = <<<GQL
    query (\$cursor: String) {
      orders(first: 250, after: \$cursor, reverse: true) {
        edges {
          cursor
          node {
            id
            name
            createdAt
            totalPriceSet {
              shopMoney {
                amount
                currencyCode
              }
            }
            customer {
              firstName
            }
            fulfillmentStatus
            tags
            lineItems(first: 50) {
              edges { node { id } }
            }
            shippingLines(first: 1) {
              edges { node { title } }
            }
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
    GQL;

    $payload = json_encode([
        'query' => $query,
        'variables' => [
            'cursor' => $cursor
        ]
    ]);

    $ch = curl_init("https://{$this->shop}/admin/api/2024-01/graphql.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $orders = [];

    foreach ($data['data']['orders']['edges'] as $edge) {
        $o = $edge['node'];

        $orders[] = [
            'numero' => $o['name'],
            'fecha' => substr($o['createdAt'], 0, 10),
            'cliente' => $o['customer']['firstName'] ?? '—',
            'total' => $o['totalPriceSet']['shopMoney']['amount'] . ' ' .
                       $o['totalPriceSet']['shopMoney']['currencyCode'],
            'estado' => $this->badgeEstado('Por preparar'),
            'etiquetas' => $o['tags'] ?: '-',
            'articulos' => count($o['lineItems']['edges']),
            'estado_envio' => $o['fulfillmentStatus'] ?? '-',
            'forma_envio' => $o['shippingLines']['edges'][0]['node']['title'] ?? '-'
        ];
    }

    return $this->response->setJSON([
        'orders' => $orders,
        'next_cursor' => $data['data']['orders']['pageInfo']['endCursor'],
        'has_next' => $data['data']['orders']['pageInfo']['hasNextPage']
    ]);
}


    /* ============================================================
       VISTA
    ============================================================ */
    public function index()
    {
        return view('dashboard');
    }
}
