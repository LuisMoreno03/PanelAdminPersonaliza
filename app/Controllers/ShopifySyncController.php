<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifySyncController extends Controller
{
    private string $shop  = '962f2d.myshopify.com';
    private string $token = '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';

    public function syncAll()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('pedidos');

        $cursor = null;
        $total = 0;

        do {
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
                      shopMoney { amount currencyCode }
                    }
                    lineItems(first: 50) { edges { node { id } } }
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
            $orders = $json['data']['orders']['edges'];

            foreach ($orders as $edge) {
                $o = $edge['node'];

                $builder->replace([
                    'id' => str_replace('gid://shopify/Order/', '', $o['id']),
                    'numero' => $o['name'],
                    'cliente' => $o['customer']['firstName'] ?? '-',
                    'total' => $o['totalPriceSet']['shopMoney']['amount'],
                    'moneda' => $o['totalPriceSet']['shopMoney']['currencyCode'],
                    'estado_envio' => $o['fulfillmentStatus'],
                    'etiquetas' => $o['tags'],
                    'articulos' => count($o['lineItems']['edges']),
                    'forma_envio' => $o['shippingLines']['edges'][0]['node']['title'] ?? '-',
                    'created_at' => date('Y-m-d H:i:s', strtotime($o['createdAt'])),
                    'synced_at' => date('Y-m-d H:i:s'),
                ]);

                $total++;
            }

            $cursor = $json['data']['orders']['pageInfo']['endCursor'];
            $hasNext = $json['data']['orders']['pageInfo']['hasNextPage'];

            usleep(400000); // evitar rate limit

        } while ($hasNext);

        return $this->response->setJSON([
            'success' => true,
            'total_pedidos' => $total
        ]);
    }
}
