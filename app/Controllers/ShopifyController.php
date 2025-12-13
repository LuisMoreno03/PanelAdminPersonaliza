<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    private string $shop  = "962f2d.myshopify.com";
    private string $token = "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"; // mover a .env

    /* ============================================================
       CLIENTE GRAPHQL SHOPIFY
    ============================================================ */
    private function graphQL(string $query, array $variables = []): array
    {
        $ch = curl_init("https://{$this->shop}/admin/api/2024-01/graphql.json");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'query'     => $query,
                'variables' => $variables
            ])
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /* ============================================================
       OBTENER MUCHOS PEDIDOS (GRAPHQL)
       ?limit=200
       ?status=unfulfilled
    ============================================================ */
    public function getOrders()
    {
        $maxPedidos = (int) ($this->request->getGet('limit') ?? 200);
        $status     = $this->request->getGet('status') ?? 'any';

        $todos  = [];
        $cursor = null;

        // Filtro GraphQL
        $queryFilter = match ($status) {
            'unfulfilled' => 'fulfillment_status:unfulfilled',
            'fulfilled'   => 'fulfillment_status:fulfilled',
            default       => 'status:any'
        };

        $query = <<<'GRAPHQL'
query ($cursor: String, $filter: String!) {
  orders(
    first: 100,
    after: $cursor,
    query: $filter
  ) {
    edges {
      cursor
      node {
        id
        name
        createdAt
        fulfillmentStatus
        tags
        customer {
          firstName
        }
        totalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
        }
        shippingLines(first: 1) {
          edges {
            node {
              title
            }
          }
        }
        lineItems(first: 50) {
          edges {
            node {
              id
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
GRAPHQL;

        do {
            $res = $this->graphQL($query, [
                'cursor' => $cursor,
                'filter' => $queryFilter
            ]);

            $edges = $res['data']['orders']['edges'] ?? [];

            foreach ($edges as $edge) {
                $o = $edge['node'];

                $todos[] = [
                    'id'           => $o['id'],
                    'numero'       => $o['name'],
                    'fecha'        => substr($o['createdAt'], 0, 10),
                    'cliente'      => $o['customer']['firstName'] ?? 'Desconocido',
                    'total'        => $o['totalPriceSet']['shopMoney']['amount'] . ' ' .
                                      $o['totalPriceSet']['shopMoney']['currencyCode'],
                    'estado_envio' => $o['fulfillmentStatus'] ?? '-',
                    'forma_envio'  => $o['shippingLines']['edges'][0]['node']['title'] ?? '-',
                    'etiquetas'    => $o['tags'],
                    'articulos'    => count($o['lineItems']['edges'])
                ];

                $cursor = $edge['cursor'];

                if (count($todos) >= $maxPedidos) {
                    break 2;
                }
            }

            $hasNext = $res['data']['orders']['pageInfo']['hasNextPage'] ?? false;

            // proteger rate limit
            usleep(300000);

        } while ($hasNext);

        return $this->response->setJSON([
            'success' => true,
            'count'   => count($todos),
            'orders'  => $todos
        ]);
    }
}
