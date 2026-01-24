<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    private string $shop = '962f2d.myshopify.com';
    private string $token = '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';
    private string $apiVersion = '2024-01';

    public function __construct()
{
    // Soporta ambos nombres (viejo/nuevo)
    $envShop  = (string) (env('SHOPIFY_SHOP') ?: env('SHOPIFY_DEFAULT_SHOP') ?: env('SHOPIFY_STORE_DOMAIN'));
    $envToken = (string) (env('SHOPIFY_TOKEN') ?: env('SHOPIFY_ADMIN_TOKEN'));
    $envVer   = (string) (env('SHOPIFY_API_VERSION') ?: '2025-10');

    // Normalizar shop
    $shop = trim($envShop);
    $shop = preg_replace('#^https?://#', '', $shop);
    $shop = preg_replace('#/.*$#', '', $shop);
    $shop = rtrim($shop, '/');

    $this->shop       = $shop ?: '';
    $this->token      = trim($envToken) ?: '';
    $this->apiVersion = trim($envVer) ?: '2025-10';
}


    // ============================================================
    // 💡 MÉTODO GENERAL PARA TODAS LAS LLAMADAS A SHOPIFY (CON HEADERS + ERROR REAL)
    // ============================================================
    private function request($method, $endpoint, $data = null): array
    {
        if (empty($this->shop) || empty($this->token)) {
            return [
                "success" => false,
                "status"  => 500,
                "error"   => "Faltan credenciales Shopify (SHOPIFY_SHOP o SHOPIFY_TOKEN).",
                "headers" => "",
                "raw"     => null,
                "data"    => null,
                "url"     => null
            ];
        }

        $endpoint = ltrim((string) $endpoint, '/');
        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/{$endpoint}";

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}",
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper((string) $method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // ✅ Capturar headers + body
        curl_setopt($curl, CURLOPT_HEADER, true);

        // ✅ Algunos hostings fallan con HTTP/2
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);

        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $resp        = curl_exec($curl);
        $curlError   = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        curl_close($curl);

        if ($resp === false) {
            return [
                "success" => false,
                "status"  => $status_code ?: 0,
                "error"   => $curlError ?: "Unknown cURL error",
                "headers" => "",
                "raw"     => null,
                "data"    => null,
                "url"     => $url
            ];
        }

        $raw_headers = substr($resp, 0, $header_size);
        $raw_body    = substr($resp, $header_size);

        $decoded = json_decode($raw_body, true);

        // ✅ Si Shopify devuelve 4xx/5xx, normalmente curlError viene vacío.
        // Sacamos el error real del body.
        $errorMsg = $curlError ?: null;
        if ($status_code >= 400) {
            if (is_array($decoded) && isset($decoded['errors'])) {
                $errorMsg = is_array($decoded['errors'])
                    ? json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE)
                    : (string) $decoded['errors'];
            } else {
                $errorMsg = $raw_body; // último recurso
            }
        }

        return [
            "success" => ($status_code >= 200 && $status_code < 300),
            "status"  => $status_code,
            "error"   => $errorMsg,
            "headers" => $raw_headers,
            "raw"     => $raw_body,
            "data"    => $decoded,
            "url"     => $url
        ];
    }

    // ============================================================
    // 🔗 Extrae page_info del header Link (rel="next")
    // ============================================================
    private function getNextPageInfoFromHeaders(string $rawHeaders): ?string
    {
        if (!preg_match('/^Link:\s*(.+)$/mi', $rawHeaders, $m)) {
            return null;
        }

        $linkLine = $m[1];

        if (!preg_match('/<([^>]+)>;\s*rel="next"/', $linkLine, $n)) {
            return null;
        }

        $nextUrl = $n[1];

        $parts = parse_url($nextUrl);
        if (!isset($parts['query'])) return null;

        parse_str($parts['query'], $qs);

        return $qs['page_info'] ?? null;
    }

    // ============================================================
    // 🔍 GET: Obtener pedidos paginados (1 página)
    // ============================================================
    public function getOrders()
    {
        $limit     = (int) ($this->request->getGet("limit") ?? 250);
        $page_info = $this->request->getGet("page_info");

        if ($limit <= 0 || $limit > 250) $limit = 250;

        $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

        if ($page_info) {
            $endpoint .= "&page_info=" . urlencode($page_info);
        }

        $response = $this->request("GET", $endpoint);

        $nextPageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

        return $this->response->setJSON([
            "success"        => $response["success"],
            "status"         => $response["status"],
            "error"          => $response["error"],
            "next_page_info" => $nextPageInfo,
            "url"            => $response["url"] ?? null,
            "raw"            => $response["raw"] ?? null,
            "data"           => $response["data"]
        ]);
    }

    // ============================================================
    // ✅ GET: Obtener TODOS los pedidos
    // ============================================================
    public function getAllOrders50()
{
    $limit    = 50;      // tamaño de lote
    $pageInfo = null;
    $allOrders = [];
    $loops     = 0;

    do {
        if ($pageInfo) {
            // Siguientes páginas: SOLO limit + page_info
            $endpoint = "orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
        } else {
            // Primera página: con filtros/orden que quieras
            $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";
        }

        $response = $this->request("GET", $endpoint);

        if (!$response["success"]) {
            return $this->response->setJSON([
                "success" => false,
                "status"  => $response["status"],
                "error"   => $response["error"],
                "url"     => $response["url"] ?? null,
                "raw"     => $response["raw"] ?? null,
                "data"    => $response["data"]
            ]);
        }

        $orders    = $response["data"]["orders"] ?? [];
        $allOrders = array_merge($allOrders, $orders);

        // Siguiente cursor desde el header Link
        $pageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

        $loops++;
        if ($loops > 5000) { // seguridad
            break;
        }

        usleep(150000); // 150ms para no golpear límites de rate
    } while ($pageInfo);

    return $this->response->setJSON([
        "success" => true,
        "total"   => count($allOrders),
        "orders"  => $allOrders
    ]);
}


    public function getOrder($id)
    {
        $response = $this->request("GET", "orders/{$id}.json");
        return $this->response->setJSON($response);
    }

    public function updateOrderTags()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"] ?? null;
        $tags    = $json["tags"] ?? null;

        if (!$orderId) {
            return $this->response->setJSON([
                "success" => false,
                "error" => "Falta el campo id"
            ])->setStatusCode(400);
        }

        $payload = [
            "order" => [
                "id"   => $orderId,
                "tags" => $tags
            ]
        ];

        $response = $this->request("PUT", "orders/{$orderId}.json", $payload);

        return $this->response->setJSON($response);
    }

    public function updateOrder()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"] ?? null;

        if (!$orderId) {
            return $this->response->setJSON([
                "success" => false,
                "error" => "Falta el campo id"
            ])->setStatusCode(400);
        }

        $orderData = [
            "order" => $json
        ];

        $response = $this->request("PUT", "orders/{$orderId}.json", $orderData);

        return $this->response->setJSON($response);
    }

    public function getProducts()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 250);
        if ($limit <= 0 || $limit > 250) $limit = 250;

        $endpoint = "products.json?limit={$limit}";
        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    public function getProduct($id)
    {
        $response = $this->request("GET", "products/{$id}.json");
        return $this->response->setJSON($response);
    }

    public function getCustomers()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 250);
        if ($limit <= 0 || $limit > 250) $limit = 250;

        $endpoint = "customers.json?limit={$limit}";
        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    public function test()
    {
        return $this->response->setJSON([
            "message"  => "Shopify API funcionando correctamente.",
            "shop"     => $this->shop,
            "hasToken" => !empty($this->token),
            "apiVersion" => $this->apiVersion
        ]);
    }

    // ============================================================
    // 👀 VISTA: Pedidos 50 en 50 (HTML)
    // ============================================================
    public function ordersView()
{
    $limit     = 50;
    $page_info = $this->request->getGet('page_info');

    // Primera página: puedes usar status/order.
    // Páginas siguientes (cuando hay page_info): SOLO limit + page_info.
    if ($page_info) {
        $endpoint = "orders.json?limit={$limit}&page_info=" . urlencode($page_info);
    } else {
        $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";
    }

    $response = $this->request("GET", $endpoint);

    if (!$response["success"]) {
        return $this->response->setStatusCode(500)->setBody(
            "Error Shopify (HTTP {$response['status']}): " . esc((string)($response["error"] ?? '')) .
            "<br><br><b>URL:</b> " . esc((string)($response["url"] ?? '')) .
            "<br><br><b>RAW:</b> <pre style='white-space:pre-wrap'>" . esc((string)($response["raw"] ?? '')) . "</pre>"
        );
    }

    $orders       = $response["data"]["orders"] ?? [];
    $nextPageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

    // Historial para poder ir hacia atrás (opcional)
    $history = session()->get('orders_page_history') ?? [];
    if (!$page_info) {
        $history = [];
    } else {
        $history[] = $page_info;
    }
    session()->set('orders_page_history', $history);

    $prevPageInfo = null;
    if (count($history) >= 2) {
        $prevPageInfo = $history[count($history) - 2];
    }

    return view('shopify/orders_list', [
        'orders'       => $orders,
        'nextPageInfo' => $nextPageInfo,
        'prevPageInfo' => $prevPageInfo,
        'isFirstPage'  => !$page_info
    ]);
}

}
