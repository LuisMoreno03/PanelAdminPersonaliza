<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2024-01';

    public function __construct()
    {
        $envShop  = (string) env('962f2d.myshopify.com'); // ej: 962f2d.myshopify.com
        $envToken = (string) env('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');  // ej: shpat_xxx
        $envVer   = (string) env('SHOPIFY_API_VERSION');  // opcional

        if (!empty(trim($envShop))) {
            $shop = trim($envShop);
            $shop = preg_replace('#^https?://#', '', $shop);
            $shop = preg_replace('#/.*$#', '', $shop);
            $shop = rtrim($shop, '/');
            $this->shop = $shop;
        }

        if (!empty(trim($envToken))) {
            $this->token = trim($envToken);
        }

        if (!empty(trim($envVer))) {
            $this->apiVersion = trim($envVer);
        }

        // Normalización final
        $this->shop = trim($this->shop);
        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->shop = rtrim($this->shop, '/');
    }

    // ============================
    // HTTP request (headers+body)
    // ============================
    private function requestRaw(string $method, string $endpoint, ?array $data = null): array
    {
        if (empty($this->shop) || empty($this->token)) {
            return [
                "success" => false,
                "status"  => 500,
                "error"   => "Faltan credenciales Shopify (SHOPIFY_STORE_DOMAIN o SHOPIFY_ADMIN_TOKEN).",
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
            "X-Shopify-Access-Token: {$this->token}",
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $resp = curl_exec($ch);
        $curlError   = curl_error($ch);
        $status_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

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
        $decoded     = json_decode($raw_body, true);

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

    // ============================
    // page_info (Link rel="next")
    // ============================
    private function extractNextPageInfo(string $rawHeaders): ?string
    {
        if (!preg_match('/^Link:\s*(.+)$/mi', $rawHeaders, $m)) return null;

        $linkLine = $m[1];
        $parts = explode(',', $linkLine);

        foreach ($parts as $p) {
            if (stripos($p, 'rel="next"') !== false) {
                if (preg_match('/<([^>]+)>/', $p, $u)) {
                    $url = $u[1];
                    $qs  = parse_url($url, PHP_URL_QUERY);
                    if (!$qs) return null;
                    parse_str($qs, $params);
                    return $params['page_info'] ?? null;
                }
            }
        }
        return null;
    }

    // ============================
    // ✅ Servicio: 1 página orders
    // ============================
    public function fetchOrdersPage(int $limit = 50, ?string $pageInfo = null): array
    {
        if ($limit <= 0 || $limit > 250) $limit = 50;

        // Primera página con filtros; siguientes SOLO limit+page_info
        if ($pageInfo) {
            $endpoint = "orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
        } else {
            $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";
        }

        // Reducir payload
        $endpoint .= (strpos($endpoint, '?') !== false ? '&' : '?') .
            "fields=id,name,order_number,created_at,total_price,tags,customer,line_items,fulfillment_status,shipping_lines";

        $resp = $this->requestRaw("GET", $endpoint);

        return [
            "success"        => (bool)($resp["success"] ?? false),
            "status"         => (int)($resp["status"] ?? 0),
            "error"          => $resp["error"] ?? null,
            "orders"         => $resp["data"]["orders"] ?? [],
            "next_page_info" => $this->extractNextPageInfo($resp["headers"] ?? ""),
            "url"            => $resp["url"] ?? null,
        ];
    }

    // Endpoint JSON para probar
    public function getOrders()
    {
        $limit     = (int) ($this->request->getGet("limit") ?? 50);
        $page_info = trim((string) ($this->request->getGet("page_info") ?? ''));

        $result = $this->fetchOrdersPage($limit, $page_info !== '' ? $page_info : null);

        return $this->response->setJSON($result);
    }

    public function getOrder($id)
    {
        $resp = $this->requestRaw("GET", "orders/{$id}.json");
        return $this->response->setJSON($resp);
    }

    public function updateOrderTags()
    {
        $json = $this->request->getJSON(true);
        $orderId = $json["id"] ?? null;
        $tags    = $json["tags"] ?? '';

        if (!$orderId) {
            return $this->response->setJSON([
                "success" => false,
                "error"   => "Falta el campo id"
            ])->setStatusCode(400);
        }

        $payload = [
            "order" => [
                "id"   => (int)$orderId,
                "tags" => (string)$tags
            ]
        ];

        $resp = $this->requestRaw("PUT", "orders/{$orderId}.json", $payload);

        return $this->response->setJSON([
            "success" => $resp["success"],
            "status"  => $resp["status"],
            "error"   => $resp["error"],
        ]);
    }

    public function test()
    {
        return $this->response->setJSON([
            "success"   => true,
            "shop"      => $this->shop,
            "hasToken"  => !empty($this->token),
            "version"   => $this->apiVersion
        ]);
    }
}
