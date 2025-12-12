<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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";

    // ============================================================
    // BADGE VISUAL DEL ESTADO
    // ============================================================
    private function badgeEstado($estado)
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
    // OBTENER ESTADO INTERNO
    // ============================================================
    private function obtenerEstadoInterno($orderId)
    {
        $db = \Config\Database::connect();
        $row = $db->table("pedidos_estado")->where("id", $orderId)->get()->getRow();
        return $row ? $row->estado : "Por preparar";
    }

    // ============================================================
    // CONSULTA SHOPIFY GENÉRICA (AJAX)
    // ============================================================
    private function queryShopify($params = "")
    {
        $url = "https://{$this->shop}/admin/api/2024-01/orders.json?$params";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
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
    // OBTENER PEDIDOS SHOPIFY (250 EN 250)
    // ============================================================
    private function getPedidosShopify($pageInfo = null)
    {
        $limit  = 250;
        $params = http_build_query([
    'limit'  => 250,
    'status' => 'any',
    'order'  => 'created_at desc'
]);


        if ($pageInfo) {
            $params .= "&page_info=$pageInfo";
        }

        return $this->queryShopify($params);
    }

    // ============================================================
    // GUARDAR PEDIDOS EN BD
    // ============================================================
    private function guardarPedidos(array $orders)
    {
        $db = \Config\Database::connect();
        $builder = $db->table("pedidos");

        foreach ($orders as $o) {
            $builder->replace([
                "id"                 => $o["id"],
                "numero"             => $o["name"],
                "cliente"            => $o["customer"]["first_name"] ?? "Desconocido",
                "total"              => $o["total_price"],
                "currency"           => $o["currency"],
                "financial_status"   => $o["financial_status"],
                "fulfillment_status" => $o["fulfillment_status"],
                "tags"               => $o["tags"] ?? "",
                "articulos"          => count($o["line_items"] ?? []),
                "forma_envio"        => $o["shipping_lines"][0]["title"] ?? "-",
                "created_at"         => date("Y-m-d H:i:s", strtotime($o["created_at"])),
                "updated_at"         => date("Y-m-d H:i:s", strtotime($o["updated_at"])),
                "synced_at"          => date("Y-m-d H:i:s"),
            ]);
        }
    }

    // ============================================================
    // SINCRONIZACIÓN INICIAL (250 x 250)
    // ============================================================
    public function syncPedidos()
    {
        $pageInfo = null;
        $total = 250;

        do {
            $response = $this->getPedidosShopify($pageInfo);

            if (!empty($response["orders"])) {
                $this->guardarPedidos($response["orders"]);
                $total += count($response["orders"]);
            }

            $pageInfo = $response["next"];
            usleep(600000); // evitar rate limit

        } while ($pageInfo);

        return $this->response->setJSON([
            "success" => true,
            "total_guardados" => $total
        ]);
    }

    // ============================================================
    // VISTA PRINCIPAL
    // ============================================================
    public function index()
    {
        return view('dashboard');
    }

    // ============================================================
    // GUARDAR ESTADO INTERNO
    // ============================================================
    public function guardarEstado()
    {
        $json = $this->request->getJSON(true);

        \Config\Database::connect()
            ->table("pedidos_estado")
            ->replace([
                "id"     => $json["id"],
                "estado" => $json["estado"]
            ]);

        return $this->response->setJSON(["success" => true]);
    }

    // ============================================================
    // GUARDAR ETIQUETAS EN SHOPIFY
    // ============================================================
    public function guardarEtiquetas()
    {
        $json = $this->request->getJSON(true);

        $url = "https://{$this->shop}/admin/api/2024-01/orders/{$json['id']}.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => "PUT",
            CURLOPT_POSTFIELDS     => json_encode([
                "order" => ["id" => $json["id"], "tags" => $json["tags"]]
            ]),
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ]
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        return $this->response->setJSON(["success" => true]);
    }

    // ============================================================
    // AJAX DASHBOARD (SIGUE USANDO SHOPIFY)
    // ============================================================
    public function filter()
{
    if (!$this->request->isAJAX()) {
        return $this->response->setStatusCode(403);
    }

    $pageInfo = $this->request->getGet("page_info");

    $query = [
        'limit'  => 250,
        'status' => 'any',
        'order'  => 'created_at desc'
    ];

    if ($pageInfo) {
        $query['page_info'] = $pageInfo;
    }

    $params = http_build_query([
    'limit'              => 250,
    'status'             => 'any',
    'financial_status'   => 'any',
    'fulfillment_status' => 'any',
    'order'              => 'created_at desc'
]);


    $response = $this->queryShopify($params);

    $resultado = [];

    foreach ($response["orders"] as $o) {

        $estadoInterno = $this->obtenerEstadoInterno($o["id"]);
        $badge = $this->badgeEstado($estadoInterno);

        $resultado[] = [
            "id"           => $o["id"],
            "numero"       => $o["name"],
            "fecha"        => substr($o["created_at"], 0, 10),
            "cliente"      => $o["customer"]["first_name"] ?? "Desconocido",
            "total"        => $o["total_price"] . " €",
            "estado"       => $badge,
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

}
