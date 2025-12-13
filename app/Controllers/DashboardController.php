<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private string $shop  = "962f2d.myshopify.com";
    private string $token = "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"; // mover a .env en prod

    /* ============================================================
       BADGE VISUAL
    ============================================================ */
    private function badgeEstado(string $estado): string
    {
        $estilos = [
            "Por preparar" => "bg-yellow-100 text-yellow-800 border border-yellow-300",
            "Preparado"    => "bg-green-100 text-green-800 border border-green-300",
            "Enviado"      => "bg-blue-100 text-blue-800 border border-blue-300",
            "Entregado"    => "bg-emerald-100 text-emerald-800 border border-emerald-300",
            "Cancelado"    => "bg-red-100 text-red-800 border border-red-300",
            "Devuelto"     => "bg-purple-100 text-purple-800 border border-purple-300",
        ];

        $clase = $estilos[$estado] ?? "bg-gray-100 text-gray-800 border border-gray-300";
        return "<span class='px-3 py-1 rounded-full text-xs font-bold {$clase}'>{$estado}</span>";
    }

    /* ============================================================
       ESTADO INTERNO (BD)
    ============================================================ */
    private function obtenerEstadoInterno($orderId): string
    {
        $row = \Config\Database::connect()
            ->table("pedidos_estado")
            ->where("id", $orderId)
            ->get()
            ->getRow();

        return $row ? $row->estado : "Por preparar";
    }

    /* ============================================================
       LLAMADA GRAPHQL A SHOPIFY
    ============================================================ */
    private function shopifyGraphQL(string $query, array $variables = []): array
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
                'query'     => $query,
                'variables' => $variables
            ])
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /* ============================================================
       DASHBOARD → TRAER MUCHOS PEDIDOS (GRAPHQL)
    ============================================================ */
    public function filter()
{
    $after = $this->request->getGet('after'); // cursor
    $first = 50;

    $query = [
        "query" => '
        query ($first: Int!, $after: String) {
          orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: true) {
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
                tags
                lineItems(first: 50) {
                  edges {
                    node { id }
                  }
                }
                fulfillmentStatus
                shippingLines(first: 1) {
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
        }',
        "variables" => [
            "first" => $first,
            "after" => $after
        ]
    ];

    $ch = curl_init("https://{$this->shop}/admin/api/2024-01/graphql.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ],
        CURLOPT_POSTFIELDS => json_encode($query)
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    $orders = [];
    $edges = $data['data']['orders']['edges'] ?? [];

    foreach ($edges as $edge) {
        $o = $edge['node'];

        $orders[] = [
            "id" => $o['id'],
            "numero" => $o['name'],
            "fecha" => substr($o['createdAt'], 0, 10),
            "cliente" => $o['customer']['firstName'] ?? 'Desconocido',
            "total" => $o['totalPriceSet']['shopMoney']['amount'] . " " . $o['totalPriceSet']['shopMoney']['currencyCode'],
            "articulos" => count($o['lineItems']['edges']),
            "estado_envio" => $o['fulfillmentStatus'] ?? '-',
            "forma_envio" => $o['shippingLines']['edges'][0]['node']['title'] ?? '-',
            "cursor" => $edge['cursor']
        ];
    }

    return $this->response->setJSON([
        "success" => true,
        "orders" => $orders,
        "nextCursor" => end($edges)['cursor'] ?? null,
        "hasNext" => $data['data']['orders']['pageInfo']['hasNextPage']
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
