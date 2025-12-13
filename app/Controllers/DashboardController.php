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
        if (!$this->request->isAJAX()) {
            return $this->response->setStatusCode(403);
        }

        $after = $this->request->getGet('cursor');

        $query = <<<GQL
        query getOrders(\$after: String) {
          orders(
            first: 250
            after: \$after
            sortKey: CREATED_AT
            reverse: true
          ) {
            edges {
              cursor
              node {
                id
                name
                createdAt
                tags
                fulfillmentStatus
                customer {
                  firstName
                }
                totalPriceSet {
                  shopMoney {
                    amount
                    currencyCode
                  }
                }
                lineItems(first: 20) {
                  edges {
                    node {
                      quantity
                    }
                  }
                }
                shippingLines(first: 3) {
                  edges {
                    node {
                      title
                    }
                  }
                }
              }
            }
            pageInfo {
              hasNextPage
            }
          }
        }
        GQL;

        $data = $this->graphQL($query, ['after' => $after]);

        $orders = [];
        $lastCursor = null;

        foreach ($data['data']['orders']['edges'] as $edge) {
            $o = $edge['node'];

            $orders[] = [
                'numero'       => $o['name'],
                'fecha'        => substr($o['createdAt'], 0, 10),
                'cliente'      => $o['customer']['firstName'] ?? 'Invitado',
                'total'        => $o['totalPriceSet']['shopMoney']['amount'] . ' ' .
                                  $o['totalPriceSet']['shopMoney']['currencyCode'],
                'estado'       => $o['fulfillmentStatus'] ?? '-',
                'etiquetas'    => implode(', ', $o['tags']),
                'articulos'    => array_sum(array_map(
                    fn($i) => $i['node']['quantity'],
                    $o['lineItems']['edges']
                )),
                'forma_envio'  => $o['shippingLines']['edges'][0]['node']['title'] ?? '-'
            ];

            $lastCursor = $edge['cursor'];
        }

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders,
            'cursor'  => $lastCursor,
            'hasNext' => $data['data']['orders']['pageInfo']['hasNextPage']
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
