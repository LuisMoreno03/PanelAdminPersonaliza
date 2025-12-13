<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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";

    // ============================================================
    // 💡 MÉTODO GENERAL PARA TODAS LAS LLAMADAS A SHOPIFY
    // ============================================================
    private function request($method, $endpoint, $data = null)
    {
        $url = "https://{$this->shop}/admin/api/2024-01/" . $endpoint;

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response     = curl_exec($curl);
        $error        = curl_error($curl);
        $status_code  = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [
            "success" => $error ? false : true,
            "status"  => $status_code,
            "error"   => $error,
            "data"    => json_decode($response, true)
        ];
    }

    // ============================================================
    // 🔍 GET: Obtener pedidos con límite / page_info / filtros
    // ============================================================
    public function getOrders()
    {
        $limit     = $this->request->getGet("limit") ?? 250;
        $page_info = $this->request->getGet("page_info");

        $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

        if ($page_info) {
            $endpoint .= "&page_info=" . urlencode($page_info);
        }

        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🛒 GET: Obtener un pedido por ID
    // ============================================================
    public function getOrder($id)
    {
        $response = $this->request("GET", "orders/{$id}.json");
        return $this->response->setJSON($response);
    }

    // ============================================================
    // ✏️ PUT: Actualizar etiquetas de un pedido
    // ============================================================
    public function updateOrderTags()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"];
        $tags    = $json["tags"];

        $payload = [
            "order" => [
                "id"   => $orderId,
                "tags" => $tags
            ]
        ];

        $response = $this->request("PUT", "orders/{$orderId}.json", $payload);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 📝 PUT: Cambiar cualquier propiedad del pedido
    // ============================================================
    public function updateOrder()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"];
        $orderData = [
            "order" => $json
        ];

        $response = $this->request("PUT", "orders/{$orderId}.json", $orderData);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🛍️ GET: Productos
    // ============================================================
    public function getProducts()
    {
        $limit = $this->request->getGet("limit") ?? 250;
        $endpoint = "products.json?limit={$limit}";

        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🔎 GET: Producto por ID
    // ============================================================
    public function getProduct($id)
    {
        $response = $this->request("GET", "products/{$id}.json");
        return $this->response->setJSON($response);
    }

    // ============================================================
    // 💰 GET: Clientes
    // ============================================================
    public function getCustomers()
    {
        $limit = $this->request->getGet("limit") ?? 250;
        $endpoint = "customers.json?limit={$limit}";

        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🔧 TEST: Ver si Shopify responde correctamente
    // ============================================================
    public function test()
    {
        return $this->response->setJSON([
            "message" => "Shopify API funcionando correctamente.",
            "shop" => $this->shop
        ]);
    }
}
