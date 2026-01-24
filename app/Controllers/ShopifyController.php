<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class ShopifyController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->bootstrapShopifyCredentials();
    }

    private function bootstrapShopifyCredentials(): void
    {
        $shop = $this->getEnvFirst([
            'SHOPIFY_SHOP',
            'SHOPIFY_DEFAULT_SHOP',
            'SHOPIFY_STORE_DOMAIN',
        ]);

        $token = $this->getEnvFirst([
            'SHOPIFY_ADMIN_TOKEN',
            'SHOPIFY_TOKEN',
            'SHOPIFY_ACCESS_TOKEN',
        ]);

        $ver = $this->getEnvFirst([
            'SHOPIFY_API_VERSION',
        ], '2025-10');

        $this->shop = $this->normalizeShop($shop);
        $this->token = trim($token);
        $this->apiVersion = trim($ver ?: '2025-10');
    }

    private function normalizeShop(string $shop): string
    {
        $shop = trim($shop);
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = preg_replace('#/.*$#', '', $shop);
        return rtrim($shop, '/');
    }

    /**
     * Lee primero desde env(), si no existe intenta getenv(),
     * y por último parsea el archivo .env directamente (fallback).
     */
    private function getEnvFirst(array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            $v = (string) env($k);
            if (trim($v) !== '') return $v;

            $v2 = (string) getenv($k);
            if (trim($v2) !== '') return $v2;
        }

        $map = $this->parseDotEnvFile();
        foreach ($keys as $k) {
            if (isset($map[$k]) && trim($map[$k]) !== '') return $map[$k];
        }

        return $default;
    }

    private function parseDotEnvFile(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $base = defined('ROOTPATH') ? ROOTPATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $path = $base . '.env';

        if (!is_file($path)) return $cache;

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return $cache;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            // Si no está entre comillas, corta comentario inline " # ..."
            if ($v !== '' && $v[0] !== '"' && $v[0] !== "'") {
                $v = preg_split('/\s+#/', $v, 2)[0] ?? $v;
                $v = trim($v);
            }

            // Quitar comillas externas
            $v = trim($v, "\"'");
            $cache[$k] = $v;
        }

        return $cache;
    }

    // ============================================================
    // ✅ Request Shopify (con headers + errores reales)
    // ============================================================
    private function shopifyRequest(string $method, string $endpoint, $data = null): array
    {
        if ($this->shop === '' || $this->token === '') {
            return [
                "success" => false,
                "status"  => 500,
                "error"   => "Faltan credenciales Shopify (.env): SHOPIFY_STORE_DOMAIN y/o SHOPIFY_ADMIN_TOKEN",
                "headers" => "",
                "raw"     => null,
                "data"    => null,
                "url"     => null
            ];
        }

        $endpoint = ltrim($endpoint, '/');
        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/{$endpoint}";

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}",
            "User-Agent: PanelAdminPersonaliza/1.0",
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Capturar headers + body
        curl_setopt($ch, CURLOPT_HEADER, true);

        // Hosting/Proxy: forzar HTTP/1.1
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($data !== null && $data !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $resp = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($resp === false) {
            return [
                "success" => false,
                "status"  => $status ?: 0,
                "error"   => $curlError ?: "Unknown cURL error",
                "headers" => "",
                "raw"     => null,
                "data"    => null,
                "url"     => $url
            ];
        }

        $rawHeaders = substr($resp, 0, $headerSize);
        $rawBody = substr($resp, $headerSize);

        $decoded = json_decode($rawBody, true);

        $errorMsg = $curlError ?: null;
        if ($status >= 400) {
            if (is_array($decoded) && isset($decoded['errors'])) {
                $errorMsg = is_array($decoded['errors'])
                    ? json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE)
                    : (string) $decoded['errors'];
            } else {
                $errorMsg = $rawBody;
            }
        }

        return [
            "success" => ($status >= 200 && $status < 300),
            "status"  => $status,
            "error"   => $errorMsg,
            "headers" => $rawHeaders,
            "raw"     => $rawBody,
            "data"    => $decoded,
            "url"     => $url
        ];
    }

    // ============================================================
    // 🔗 Extrae page_info (rel="next")
    // ============================================================
    private function getNextPageInfoFromHeaders(string $rawHeaders): ?string
    {
        if (!preg_match('/^Link:\s*(.+)$/mi', $rawHeaders, $m)) return null;
        if (!preg_match('/<([^>]+)>;\s*rel="next"/', $m[1], $n)) return null;

        $nextUrl = $n[1];
        $parts = parse_url($nextUrl);
        if (!isset($parts['query'])) return null;

        parse_str($parts['query'], $qs);
        return $qs['page_info'] ?? null;
    }

    // ============================================================
    // ✅ GET: Pedidos paginados (arreglado page_info)
    // ============================================================
    public function getOrders()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 50);
        $pageInfo = $this->request->getGet("page_info");

        if ($limit <= 0 || $limit > 250) $limit = 50;

        if ($pageInfo) {
            // Shopify exige SOLO limit + page_info
            $endpoint = "orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
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

    public function getOrder($id)
    {
        return $this->response->setJSON(
            $this->shopifyRequest("GET", "orders/{$id}.json")
        );
    }

    public function updateOrderTags()
    {
        $json = $this->request->getJSON(true);
        $orderId = $json["id"] ?? null;

        if (!$orderId) {
            return $this->response->setJSON(["success" => false, "error" => "Falta el campo id"])
                ->setStatusCode(400);
        }

        $payload = ["order" => ["id" => $orderId, "tags" => ($json["tags"] ?? "")]];
        return $this->response->setJSON(
            $this->shopifyRequest("PUT", "orders/{$orderId}.json", $payload)
        );
    }

    public function updateOrder()
    {
        $json = $this->request->getJSON(true);
        $orderId = $json["id"] ?? null;

        if (!$orderId) {
            return $this->response->setJSON(["success" => false, "error" => "Falta el campo id"])
                ->setStatusCode(400);
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

    // ✅ Debug real (para comprobar que SÍ toma .env)
    public function test()
    {
        return $this->response->setJSON([
            "shop"        => $this->shop,
            "token_len"   => strlen($this->token),
            "token_hint"  => $this->token ? (substr($this->token, 0, 6) . "..." . substr($this->token, -4)) : null,
            "apiVersion"  => $this->apiVersion,
        ]);
    }
}
