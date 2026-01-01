<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;

class ShopifyClient
{
    private string $shop;
    private string $token;
    private string $apiVersion;

    public function __construct()
    {
        $this->shop = trim((string) env('SHOPIFY_STORE_DOMAIN'));
        $this->token = trim((string) env('SHOPIFY_ADMIN_TOKEN'));
        $this->apiVersion = trim((string) env('SHOPIFY_API_VERSION')) ?: '2024-01';

        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
    }

    private function baseUrl(): string
    {
        return "https://{$this->shop}/admin/api/{$this->apiVersion}";
    }

    /**
     * Devuelve: [dataArray, headersArray]
     */
    public function get(string $path, array $query = []): array
    {
        /** @var CURLRequest $http */
        $http = service('curlrequest');

        $url = $this->baseUrl() . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $resp = $http->request('GET', $url, [
            'headers' => [
                'X-Shopify-Access-Token' => $this->token,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $body   = (string) $resp->getBody();

        // Headers normalizados
        $headers = [];
        foreach ($resp->getHeaders() as $k => $v) {
            $headers[strtolower($k)] = $resp->getHeaderLine($k);
        }

        if ($status < 200 || $status >= 300) {
            return [
                ['error' => true, 'status' => $status, 'body' => $body],
                $headers
            ];
        }

        return [json_decode($body, true) ?? [], $headers];
    }

    /**
     * Extrae page_info para next/prev desde el header Link.
     */
    public static function parseLinkPagination(?string $linkHeader): array
    {
        $out = ['next' => null, 'prev' => null];

        if (!$linkHeader) return $out;

        // Ej: <https://.../orders.json?limit=50&page_info=XXXX>; rel="next"
        foreach (explode(',', $linkHeader) as $part) {
            if (preg_match('/<([^>]+)>;\s*rel="([^"]+)"/', trim($part), $m)) {
                $url = $m[1];
                $rel = $m[2];
                $qs  = parse_url($url, PHP_URL_QUERY);
                parse_str($qs ?? '', $params);

                if (!empty($params['page_info'])) {
                    if ($rel === 'next') $out['next'] = $params['page_info'];
                    if ($rel === 'previous') $out['prev'] = $params['page_info'];
                }
            }
        }

        return $out;
    }
}
