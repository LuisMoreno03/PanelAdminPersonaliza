<?php

namespace App\Services;

class ShopifyService
{
    public function getOrders($queryParams)
{
    $domain = getenv('SHOPIFY_STORE_DOMAIN');
    $token  = getenv('SHOPIFY_ADMIN_TOKEN');

    $url = "https://{$domain}/admin/api/2023-10/orders.json?" . $queryParams;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Shopify-Access-Token: {$token}",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return ["error" => curl_error($ch)];
    }

    curl_close($ch);

    return json_decode($response, true);
}


    // -----------------------------
    // EXTRAE next y previous
    // -----------------------------
    private function parseLinkHeaders($headers)
    {
        $links = [];

        if (preg_match_all('/<(.*?)>; rel="(.*?)"/', $headers, $matches)) {
            foreach ($matches[2] as $i => $rel) {
                $url = $matches[1][$i];

                // buscar page_info
                if (strpos($url, "page_info=") !== false) {
                    parse_str(parse_url($url, PHP_URL_QUERY), $query);

                    if (isset($query['page_info'])) {
                        $links[$rel] = $query['page_info'];
                    }
                }
            }
        }

        return $links;
    }
}
