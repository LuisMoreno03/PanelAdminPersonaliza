<?php

namespace App\Libraries;

class ShopifyService
{
    private $domain;
    private $token;

    public function __construct()
    {
        $this->domain = getenv("962f2d.myshopify.com");
        $this->token  = getenv("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");
    }

    public function getOrders($params = "")
    {
        $url = "https://{$this->domain}/admin/api/2024-01/orders.json?$params";

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            return ["error" => $error];
        }

        return json_decode($response, true);
    }
}
