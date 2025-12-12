<?php

namespace App\Controllers;
use App\Controllers\DashboardController;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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"; // ⚠️ no expongas el token en prod

    // ============================================================
    // BADGE VISUAL DEL ESTADO
    // ============================================================
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

    // ============================================================
    // ESTADO INTERNO
    // ============================================================
    private function obtenerEstadoInterno($orderId): string
    {
        $row = \Config\Database::connect()
            ->table("pedidos_estado")
            ->where("id", $orderId)
            ->get()
            ->getRow();

        return $row ? $row->estado : "Por preparar";
    }

    private function getPedidosShopify50()
{
    $url = "https://{$this->shop}/admin/api/2024-01/orders.json?limit=50&status=any&order=created_at desc";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-Shopify-Access-Token: {$this->token}",
            "Content-Type: application/json"
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    return $data['orders'] ?? [];
}

private function guardarPedidosBD(array $orders)
{
    $db = \Config\Database::connect();
    $builder = $db->table('pedidos');

    foreach ($orders as $o) {
        $builder->replace([
            'id'           => $o['id'],
            'numero'       => $o['name'],
            'fecha'        => substr($o['created_at'], 0, 10),
            'cliente'      => $o['customer']['first_name'] ?? 'Desconocido',
            'total'        => $o['total_price'],
            'estado_envio' => $o['fulfillment_status'] ?? '-',
            'forma_envio'  => $o['shipping_lines'][0]['title'] ?? '-',
            'etiquetas'    => $o['tags'] ?? '',
            'articulos'    => count($o['line_items'] ?? []),
            'created_at'   => date('Y-m-d H:i:s', strtotime($o['created_at'])),
            'synced_at'    => date('Y-m-d H:i:s')
        ]);
    }
}


    // ============================================================
    // QUERY SHOPIFY (GENÉRICA)
    // ============================================================
    private function queryShopify(string $params): array
    {
        $url = "https://{$this->shop}/admin/api/2024-01/orders.json?$params";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30
        ]);

        $response   = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $body    = substr($response, $headerSize);

        $data = json_decode($body, true);

        preg_match('/<([^>]+)>; rel="next"/', $headers, $next);
        preg_match('/<([^>]+)>; rel="previous"/', $headers, $prev);

        $nextPage = null;
        $prevPage = null;

        if (!empty($next[1])) {
            parse_str(parse_url($next[1], PHP_URL_QUERY), $p);
            $nextPage = $p['page_info'] ?? null;
        }

        if (!empty($prev[1])) {
            parse_str(parse_url($prev[1], PHP_URL_QUERY), $p);
            $prevPage = $p['page_info'] ?? null;
        }

        return [
            "orders"   => $data["orders"] ?? [],
            "next"     => $nextPage,
            "previous" => $prevPage
        ];
    }

    // ============================================================
    // QUERY BASE SHOPIFY (UNIFICADA)
    // ============================================================
    private function buildShopifyQuery(?string $pageInfo = null): string
    {
        $query = [
            'limit'              => 250,
            'status'             => 'any',
            'financial_status'   => 'any',
            'fulfillment_status' => 'any',
            'order'              => 'created_at desc'
        ];

        if ($pageInfo) {
            $query['page_info'] = $pageInfo;
        }

        return http_build_query($query);
    }

    // ============================================================
    // SYNC 250 x 250 → BD
    // ============================================================
    public function syncPedidos()
{
    $orders = $this->getPedidosShopify50();
    $this->guardarPedidosBD($orders);

    return $this->response->setJSON([
        'success' => true,
        'guardados' => count($orders)
    ]);
}

    // ============================================================
    // DASHBOARD AJAX (SHOPIFY DIRECTO)
    // ============================================================
    public function filter()
    {
        if (!$this->request->isAJAX()) {
            return $this->response->setStatusCode(403);
        }

        $pageInfo = $this->request->getGet("page_info");

        $params   = $this->buildShopifyQuery($pageInfo);
        $response = $this->queryShopify($params);

        $resultado = [];

        foreach ($response["orders"] as $o) {
            $estadoInterno = $this->obtenerEstadoInterno($o["id"]);

            $resultado[] = [
                "id"           => $o["id"],
                "numero"       => $o["name"],
                "fecha"        => substr($o["created_at"], 0, 10),
                "cliente"      => $o["customer"]["first_name"] ?? "Desconocido",
                "total"        => $o["total_price"] . " €",
                "estado"       => $this->badgeEstado($estadoInterno),
                "estado_raw"   => $estadoInterno,
                "etiquetas"    => $o["tags"] ?? "-",
                "articulos"    => count($o["line_items"] ?? []),
                "estado_envio" => $o["fulfillment_status"] ?? "-",
                "forma_envio"  => $o["shipping_lines"][0]["title"] ?? "-"
            ];
        }

        return $this->response->setJSON([
            "success"        => true,
            "orders"         => $resultado,
            "next_page_info" => $response["next"],
            "prev_page_info" => $response["previous"],
            "count"          => count($resultado)
        ]);
    }

    // ============================================================
    // VISTA
    // ============================================================
    public function index()
    {
        return view('dashboard');
    }
}
