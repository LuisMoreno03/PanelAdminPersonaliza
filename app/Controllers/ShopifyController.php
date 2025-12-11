<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    public function getOrders()
    {
        $shop = "962f2d.myshopify.com";  
        $accessToken = "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";

        // Shopify Admin REST API endpoint
        $url = "https://$shop/admin/api/2024-01/orders.json?status=any&limit=50";

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: $accessToken"
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $this->response
            ->setContentType('application/json')
            ->setBody($response);
    }
}
