<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private string $shop  = '962f2d.myshopify.com';
    private string $token = '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';

    public function index()
    {
        return view('dashboard');
    }

    public function orders()
    {
        $cursor = $this->request->getGet('cursor');

        $query = <<<GQL
        query (\$cursor: String) {
          orders(first: 250, after: \$cursor, sortKey: CREATED_AT, reverse: true) {
            edges {
              cursor
              node {
                id
                name
                createdAt
                tags
                fulfillmentStatus
                customer { firstName }
                totalPriceSet {
                  shopMoney {
                    amount
                    currencyCode
                  }
                }
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
            'variables' => ['cursor' => $cursor]
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

        $json = json_decode($response, true);

        $edges = $json['data']['orders']['edges'] ?? [];

        $orders = [];
        foreach ($edges as $edge) {
            $o = $edge['node'];
            $orders[] = [
                'pedido' => $o['name'],
                'fecha' => substr($o['createdAt'], 0, 10),
                'cliente' => $o['customer']['firstName'] ?? '—',
                'total' => $o['totalPriceSet']['shopMoney']['amount']
                         . ' ' . $o['totalPriceSet']['shopMoney']['currencyCode'],
                'articulos' => count($o['lineItems']['edges']),
                'envio' =>
                    $o['shippingLines']['edges'][0]['node']['title'] ?? '-'
            ];
        }

        return $this->response->setJSON([
            'orders' => $orders,
            'next_cursor' => $json['data']['orders']['pageInfo']['endCursor'],
            'has_next' => $json['data']['orders']['pageInfo']['hasNextPage']
        ]);
    }
}
