<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    public function __construct()
    {
        // Soporta varios nombres por compatibilidad
        $envShop  = (string) (env('SHOPIFY_SHOP') ?: env('SHOPIFY_DEFAULT_SHOP') ?: env('SHOPIFY_STORE_DOMAIN'));
        $envToken = (string) (env('SHOPIFY_ADMIN_TOKEN') ?: env('SHOPIFY_TOKEN'));
        $envVer   = (string) (env('SHOPIFY_API_VERSION') ?: '2025-10');

        $this->shop       = $this->normalizeShop($envShop);
        $this->token      = trim($envToken);
        $this->apiVersion = trim($envVer) ?: '2025-10';
    }

    private function normalizeShop(string $shop): string
    {
        $shop = trim($shop);
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = preg_replace('#/.*$#', '', $shop);
        return rtrim($shop, '/');
    }

    // ============================================================
    // ✅ Request general a Shopify (headers + raw + error real)
    // ============================================================
    private function shopifyRequest(string $method, string $endpoint, $data = null): array
    {
        if ($this->shop === '' || $this->token === '') {
            return [
                "success" => false,
                "status"  => 500,
                "error"   => "Faltan credenciales Shopify. Revisa .env: SHOPIFY_STORE_DOMAIN y SHOPIFY_ADMIN_TOKEN",
                "headers" => "",
                "raw"     => null,
                "data"    => null,
                "url"     => null
            ];
        }

        $endpoint = ltrim($endpoint, '/');
        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/{$endpoint}";

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-Shopify-Access-Token: {$this->token}",
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // Capturar headers + body
        curl_setopt($curl, CURLOPT_HEADER, true);

        // Evitar problemas en algunos hostings
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        // Para respuestas gzip/deflate
        curl_setopt($curl, CURLOPT_ENCODING, "");

        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);

        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
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

        $errorMsg = $curlError ?: null;
        if ($status_code >= 400) {
            if (is_array($decoded) && isset($decoded['errors'])) {
                $errorMsg = is_array($decoded['errors'])
                    ? json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE)
                    : (string) $decoded['errors'];
            } else {
                $errorMsg = $raw_body;
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
        if (!preg_match('/^Link:\s*(.+)$/mi', $rawHeaders, $m)) return null;
        if (!preg_match('/<([^>]+)>;\s*rel="next"/', $m[1], $n)) return null;

        $parts = parse_url($n[1]);
        if (!isset($parts['query'])) return null;

        parse_str($parts['query'], $qs);
        return $qs['page_info'] ?? null;
    }

    // ============================================================
    // ✅ TEST REAL: llama shop.json y valida token/version
    // ============================================================
    public function test()
    {
        $ping = $this->shopifyRequest("GET", "shop.json");

        return $this->response->setJSON([
            "config" => [
                "shop"        => $this->shop,
                "token_len"   => strlen($this->token),
                "api_version" => $this->apiVersion,
            ],
            "shopify" => [
                "success" => $ping["success"],
                "status"  => $ping["status"],
                "error"   => $ping["error"],
            ]
        ]);
    }

    // ============================================================
    // 🔍 GET: pedidos (1 página) - paginación correcta con page_info
    // ============================================================
    public function getOrders()
    {
        $limit     = (int) ($this->request->getGet("limit") ?? 50);
        $page_info = (string) ($this->request->getGet("page_info") ?? '');

        if ($limit <= 0 || $limit > 250) $limit = 50;

        if ($page_info !== '') {
            // IMPORTANTE: con page_info => SOLO limit + page_info
            $endpoint = "orders.json?limit={$limit}&page_info=" . urlencode($page_info);
        } else {
            $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";
        }

        $response = $this->shopifyRequest("GET", $endpoint);
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
    // ✅ GET: todos los pedidos (en lotes de 50)
    // ============================================================
    public function getAllOrders()
    {
        $limit = 50;
        $pageInfo = null;
        $allOrders = [];
        $loops = 0;

        do {
            if ($pageInfo) {
                $endpoint = "orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
            } else {
                $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";
            }

            $response = $this->shopifyRequest("GET", $endpoint);

            if (!$response["success"]) {
                return $this->response->setJSON([
                    "success" => false,
                    "status"  => $response["status"],
                    "error"   => $response["error"],
                    "url"     => $response["url"] ?? null,
                    "raw"     => $response["raw"] ?? null,
                ]);
            }

            $orders = $response["data"]["orders"] ?? [];
            $allOrders = array_merge($allOrders, $orders);

            $pageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

            $loops++;
            if ($loops > 5000) break;

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
        return $this->response->setJSON(
            $this->shopifyRequest("GET", "orders/{$id}.json")
        );
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

        return $this->response->setJSON(
            $this->shopifyRequest("PUT", "orders/{$orderId}.json", ["order" => $json])
        );
    }

    public function getProducts()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 50);
        if ($limit <= 0 || $limit > 250) $limit = 50;

        return $this->response->setJSON(
            $this->shopifyRequest("GET", "products.json?limit={$limit}")
        );
    }

    public function getCustomers()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 50);
        if ($limit <= 0 || $limit > 250) $limit = 50;

        return $this->response->setJSON(
            $this->shopifyRequest("GET", "customers.json?limit={$limit}")
        );
    }
}
