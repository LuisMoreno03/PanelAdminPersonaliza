<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    // Ajusta esto a tu config real
    private string $shopDomain  = 'TU-TIENDA.myshopify.com';
    private string $accessToken = 'TU_ADMIN_ACCESS_TOKEN';
    private string $apiVersion  = '2024-10';

    public function getOrders(int $limit = 50, ?string $pageInfo = null)
    {
        try {
            $query = [
                'status' => 'any',
                'limit'  => $limit,
                // IMPORTANTE: fields reduce payload y acelera
                'fields' => 'id,name,order_number,created_at,total_price,tags,customer,line_items,fulfillment_status,shipping_lines',
            ];

            if ($pageInfo) {
                // Para page_info NO mezcles otros filtros distintos (Shopify puede ignorar o fallar)
                $query['page_info'] = $pageInfo;
            }

            $url = "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/orders.json?" . http_build_query($query);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,  // 👈 necesitamos headers para Link
                CURLOPT_HTTPHEADER     => [
                    "X-Shopify-Access-Token: {$this->accessToken}",
                    "Content-Type: application/json",
                ],
                CURLOPT_TIMEOUT        => 30,
            ]);

            $raw = curl_exec($ch);

            if ($raw === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return $this->response->setJSON([
                    'success' => false,
                    'error'   => "cURL error: {$err}"
                ])->setStatusCode(500);
            }

            $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $headerStr = substr($raw, 0, $hdrSize);
            $bodyStr   = substr($raw, $hdrSize);

            $body = json_decode($bodyStr, true);

            if ($status < 200 || $status >= 300) {
                return $this->response->setJSON([
                    'success' => false,
                    'error'   => $body['errors'] ?? 'Error Shopify',
                    'status'  => $status,
                ])->setStatusCode(500);
            }

            $nextPageInfo = $this->extractNextPageInfo($headerStr);

            return $this->response->setJSON([
                'success'        => true,
                'orders'         => $body['orders'] ?? [],
                'next_page_info' => $nextPageInfo,
            ]);

        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'success' => false,
                'error'   => $e->getMessage(),
            ])->setStatusCode(500);
        }
    }

    private function extractNextPageInfo(string $headers): ?string
    {
        // Busca el header Link: <...page_info=XYZ...>; rel="next"
        // Shopify devuelve algo tipo:
        // Link: <https://.../orders.json?limit=50&page_info=abcdef>; rel="next", <...>; rel="previous"
        if (!preg_match('/^Link:\s*(.+)$/mi', $headers, $m)) {
            return null;
        }

        $linkLine = $m[1];

        // Encuentra el segmento rel="next"
        // Captura la URL dentro de < >
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
}
