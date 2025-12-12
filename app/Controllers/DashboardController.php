<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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";


    // ============================================================
    // BADGE VISUAL DEL ESTADO (MEJORADO / PROFESIONAL)
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

        return "
        <span class='px-3 py-1 rounded-full text-xs font-bold tracking-wide {$clase}'>
            {$estado}
        </span>";
    }


    // ============================================================
    // OBTENER ESTADO INTERNO (BASE DE DATOS)
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
    // LLAMAR A SHOPIFY
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
        CURLOPT_HEADER         => true, // 🔥 IMPORTANTE
        CURLOPT_TIMEOUT        => 15
    ]);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($response, 0, $headerSize);
    $body    = substr($response, $headerSize);

    $data = json_decode($body, true);

    // 🔹 Extraer cursores
    preg_match('/<([^>]+)>; rel="next"/', $headers, $next);
    preg_match('/<([^>]+)>; rel="previous"/', $headers, $prev);

    $nextPage = null;
    $prevPage = null;

    if (!empty($next[1])) {
        parse_str(parse_url($next[1], PHP_URL_QUERY), $params);
        $nextPage = $params['page_info'] ?? null;
    }

    if (!empty($prev[1])) {
        parse_str(parse_url($prev[1], PHP_URL_QUERY), $params);
        $prevPage = $params['page_info'] ?? null;
    }

    return [
        "orders"   => $data["orders"] ?? [],
        "next"     => $nextPage,
        "previous" => $prevPage
    ];
}



    // ============================================================
    // VISTA PRINCIPAL
    // ============================================================
    public function index()
    {
        return view('dashboard');
    }

    // ============================================================
    // GUARDAR MODAL 
    // ============================================================
    public function guardarEstado()
{
    $json = $this->request->getJSON(true);
    $id = $json["id"];
    $estado = $json["estado"];

    $db = \Config\Database::connect();

    // Inserta o actualiza
    $db->table("pedidos_estado")->replace([
        "id" => $id,
        "estado" => $estado
    ]);

    return $this->response->setJSON([
        "success" => true
    ]);
}
public function guardarEtiquetas()
{
    $json = $this->request->getJSON(true);

    $orderId = $json["id"];
    $tags    = $json["tags"];

    // 1. ACTUALIZAR EN SHOPIFY
    $url = "https://{$this->shop}/admin/api/2024-01/orders/{$orderId}.json";

    $data = [
        "order" => [
            "id"   => $orderId,
            "tags" => $tags
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => "PUT",
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            "X-Shopify-Access-Token: {$this->token}",
            "Content-Type: application/json"
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if (!isset($decoded["order"])) {
        return $this->response->setJSON([
            "success" => false,
            "message" => "Error actualizando etiquetas en Shopify",
            "raw" => $response
        ]);
    }

    // TODO OK
    return $this->response->setJSON([
        "success" => true,
        "message" => "Etiquetas actualizadas correctamente"
    ]);
}

    // ============================================================
    // API → TRAE 50 PEDIDOS SIEMPRE
    // ============================================================
   public function dashboard()
{
    $pedidoModel = new \App\Models\PedidoModel();

    $perPage = 50;

    $data['pedidos'] = $pedidoModel->paginate($perPage);
    $data['pager']   = $pedidoModel->pager;   // 🔥 ESTA LÍNEA ES CLAVE

    return view('dashboard', $data);
}


    public function filter($range = "todos")
{
    if (!$this->request->isAJAX()) {
        return $this->response->setStatusCode(403);
    }

    $pageInfo = $this->request->getGet("page_info");
    $limit = 50;

    $params = "limit=$limit&status=any&order=created_at desc";

    if ($pageInfo) {
        $params .= "&page_info=$pageInfo";
    }

    $response = $this->queryShopify($params);

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
    "debug_next"     => $response["next"] ? "HAY NEXT" : "NO NEXT",
    "debug_prev"     => $response["previous"] ? "HAY PREV" : "NO PREV",
    "count"          => count($resultado)
]);

}


}

