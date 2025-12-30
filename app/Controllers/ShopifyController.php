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
        // Leer desde .env (si existe). Si está vacío, NO pisa lo hardcodeado.
        $envShop  = (string) env('SHOPIFY_SHOP');
        $envToken = (string) env('SHOPIFY_TOKEN');

        if (!empty(trim($envShop))) {
            $shop = trim($envShop);
            $shop = preg_replace('#^https?://#', '', $shop); // quita https://
            $shop = preg_replace('#/.*$#', '', $shop);       // quita cualquier /algo (ej /admin)
            $shop = rtrim($shop, '/');
            $this->shop = $shop;
        }

        if (!empty(trim($envToken))) {
            $this->token = trim($envToken);
        }

        // Normalización final por si quedó raro el hardcodeado
        $this->shop = trim($this->shop);
        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->shop = rtrim($this->shop, '/');
    }

    // ============================================================
    // 💡 MÉTODO GENERAL PARA TODAS LAS LLAMADAS A SHOPIFY (CON HEADERS)
    // ============================================================
    private function request($method, $endpoint, $data = null): array
    {
        if (empty($this->shop) || empty($this->token)) {
            return [
                "success" => false,
                "status"  => 500,
                "error"   => "Faltan credenciales Shopify (SHOPIFY_SHOP o SHOPIFY_TOKEN).",
                "headers" => "",
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

        $response     = curl_exec($curl);
        $error        = curl_error($curl);
        $status_code  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $header_size  = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        curl_close($curl);

        if ($response === false) {
            return [
                "success" => false,
                "status"  => $status_code ?: 0,
                "error"   => $error ?: "Unknown cURL error",
                "headers" => "",
                "data"    => null,
                "url"     => $url
            ];
        }

        $raw_headers = substr($response, 0, $header_size);
        $body        = substr($response, $header_size);

        $decoded = json_decode($body, true);

        return [
            "success" => $error ? false : true,
            "status"  => $status_code,
            "error"   => $error,
            "headers" => $raw_headers,
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

        if (preg_match('/<([^>]+)>;\s*rel="next"/', $linkLine, $n)) {
            $nextUrl = $n[1];

            $parts = parse_url($nextUrl);
            if (!isset($parts['query'])) return null;

            parse_str($parts['query'], $qs);

            return $qs['page_info'] ?? null;
        }

        return null;
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
            "data"           => $response["data"]
        ]);
    }

    // ============================================================
    // ✅ GET: Obtener TODOS los pedidos
    // ============================================================
    public function getAllOrders()
    {
        $limit = 250;
        $pageInfo = null;

        $allOrders = [];
        $loops = 0;

        do {
            $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

            if ($pageInfo) {
                $endpoint .= "&page_info=" . urlencode($pageInfo);
            }

            $response = $this->request("GET", $endpoint);

            if (!$response["success"] || $response["status"] >= 400) {
                return $this->response->setJSON([
                    "success" => false,
                    "status"  => $response["status"],
                    "error"   => $response["error"],
                    "url"     => $response["url"] ?? null,
                    "data"    => $response["data"]
                ]);
            }

            $orders = $response["data"]["orders"] ?? [];
            $allOrders = array_merge($allOrders, $orders);

            $pageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

            $loops++;
            if ($loops > 2000) break;

            usleep(150000);

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
            "hasToken" => !empty($this->token)
        ]);
    }

    // ============================================================
    // 👀 VISTA: Pedidos 50 en 50 (HTML)
    // ============================================================
    public function ordersView()
    {
        $limit     = 50;
        $page_info = $this->request->getGet('page_info');

        $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

        if ($page_info) {
            $endpoint .= "&page_info=" . urlencode($page_info);
        }

        $response = $this->request("GET", $endpoint);

        if (!$response["success"] || $response["status"] >= 400) {
            return $this->response->setStatusCode(500)->setBody(
                "Error Shopify: " . esc($response["error"] ?? 'Error desconocido') .
                "<br>URL: " . esc($response["url"] ?? '')
            );
        }

        $orders = $response["data"]["orders"] ?? [];
        $nextPageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

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
        } elseif (count($history) === 1) {
            $prevPageInfo = null;
        }

        return view('shopify/orders_list', [
            'orders'       => $orders,
            'nextPageInfo' => $nextPageInfo,
            'prevPageInfo' => $prevPageInfo,
            'isFirstPage'  => !$page_info
        ]);
    }
}
