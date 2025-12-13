<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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";

    // ============================================================
    // LLAMADA A SHOPIFY (GENÉRICA)
    // ============================================================
    private function shopifyRequest($endpoint)
    {
        $url = "https://{$this->shop}/admin/api/2024-01/$endpoint";

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    // ============================================================
    // BADGES DE ESTADO
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

        return "<span class='px-3 py-1 rounded-full text-xs font-bold $clase'>$estado</span>";
    }

    // ============================================================
    // ESTADO INTERNO (BASE DE DATOS)
    // ============================================================
    private function obtenerEstadoInterno($orderId)
    {
        $db = \Config\Database::connect();

        $row = $db->table("pedidos_estado")
                 ->where("id", $orderId)
                 ->get()
                 ->getRow();

        return $row ? $row->estado : "Por preparar";
    }

    // ============================================================
    // VISTA PRINCIPAL
    // ============================================================
    public function index()
    {
        return view('dashboard');
    }

    // ============================================================
    // CARGAR PEDIDOS (Paginación Shopify con page_info)
    // ============================================================
    public function filter()
{
    $limit = 250; // 🔥 AHORA SON 250 POR PÁGINA

    $pageInfo = $this->request->getGet('page_info');

    $params = "limit={$limit}&status=any&order=created_at%20desc";

    if (!empty($pageInfo)) {
        $params .= "&page_info=" . urlencode($pageInfo);
    }

    $response = $this->queryShopify($params);

    if (!isset($response["orders"])) {
        return $this->response->setJSON([
            "success" => false,
            "message" => "Error al obtener pedidos desde Shopify",
            "raw"     => $response
        ]);
    }

    // Convertimos Shopify → Frontend
    $resultado = [];

    foreach ($response["orders"] as $o) {

        $estadoInterno = $this->obtenerEstadoInterno($o["id"]);
        $badge         = $this->badgeEstado($estadoInterno);

        $resultado[] = [
            "id"           => $o["id"],
            "numero"       => $o["name"],
            "fecha"        => substr($o["created_at"], 0, 10),
            "cliente"      => $o["customer"]["first_name"] ?? "Desconocido",
            "total"        => $o["total_price"] . " €",
            "estado"       => $badge,
            "etiquetas"    => $o["tags"] ?? "-",
            "articulos"    => count($o["line_items"] ?? []),
            "estado_envio" => $o["fulfillment_status"] ?? "-",
            "forma_envio"  => $o["shipping_lines"][0]["title"] ?? "-"
        ];
    }

    // EXTRAEMOS page_info DEL HEADER
    $nextPage = null;

    if (!empty($response["link"])) { 
        if (preg_match('/page_info=([^&>]+)/', $response["link"], $match)) {
            $nextPage = $match[1];
        }
    }

    return $this->response->setJSON([
        "success"        => true,
        "orders"         => $resultado,
        "count"          => count($resultado),
        "next_page_info" => $nextPage
    ]);
}

    // ============================================================
    // GUARDAR ESTADO INTERNO
    // ============================================================
    public function guardarEstado()
    {
        $json = $this->request->getJSON(true);

        $db = \Config\Database::connect();

        $db->table("pedidos_estado")->replace([
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

        $orderId = $json["id"];
        $tags    = $json["tags"];

        $endpoint = "orders/$orderId.json";

        $payload = [
            "order" => [
                "id"   => $orderId,
                "tags" => $tags
            ]
        ];

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ];

        $ch = curl_init("https://{$this->shop}/admin/api/2024-01/$endpoint");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => "PUT",
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $this->response->setJSON(["success" => true]);
    }
}
