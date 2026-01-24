<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\ShopModel;

class ShopifyAuth extends Controller
{
    public function install()
    {
        $shop = (string) $this->request->getGet('shop');
        $shop = $this->normalizeShop($shop);

        if (!$this->isValidShop($shop)) {
            return $this->response->setStatusCode(400)->setBody('Shop inválido');
        }

        $apiKey   = (string) env('SHOPIFY_API_KEY');
        $scopes   = (string) env('SHOPIFY_SCOPES') ?: 'read_orders,write_orders';
        $redirect = base_url('shopify/callback');

        // state anti-CSRF
        $state = bin2hex(random_bytes(16));
        session()->set('shopify_oauth_state', $state);

        $url = "https://{$shop}/admin/oauth/authorize"
            . "?client_id=" . urlencode($apiKey)
            . "&scope=" . urlencode($scopes)
            . "&redirect_uri=" . urlencode($redirect)
            . "&state=" . urlencode($state);

        return redirect()->to($url);
    }

    public function callback()
    {
        $params = $this->request->getGet();

        $shop  = $this->normalizeShop($params['shop'] ?? '');
        $code  = $params['code'] ?? null;
        $hmac  = $params['hmac'] ?? null;
        $state = $params['state'] ?? null;

        if (!$shop || !$code || !$hmac || !$state) {
            return $this->response->setStatusCode(400)->setBody('Faltan parámetros');
        }

        // 1) state
        $saved = session()->get('shopify_oauth_state');
        if (!$saved || !hash_equals($saved, $state)) {
            return $this->response->setStatusCode(401)->setBody('State inválido');
        }

        // 2) HMAC
        $secret = (string) env('SHOPIFY_API_SECRET');
        if (!$this->verifyHmac($params, $secret)) {
            return $this->response->setStatusCode(401)->setBody('HMAC inválido');
        }

        // 3) exchange code -> access_token
        $token = $this->exchangeToken($shop, (string) env('SHOPIFY_API_KEY'), $secret, $code);
        if (!$token) {
            return $this->response->setStatusCode(500)->setBody('No se pudo obtener access_token');
        }

        // 4) Guardar token en DB
        $model = new ShopModel();
        $model->upsertToken($shop, $token);

        // 5) Redirigir a tu panel (puede ser /dashboard)
        return redirect()->to(base_url('dashboard?shop=' . urlencode($shop)));
    }

    private function exchangeToken(string $shop, string $apiKey, string $secret, string $code): ?string
    {
        $url = "https://{$shop}/admin/oauth/access_token";
        $payload = [
            'client_id'     => $apiKey,
            'client_secret' => $secret,
            'code'          => $code,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200 || !$raw) return null;

        $data = json_decode($raw, true);
        return $data['access_token'] ?? null;
    }

    private function verifyHmac(array $params, string $secret): bool
    {
        $hmac = $params['hmac'] ?? '';
        unset($params['hmac'], $params['signature']);

        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $calc  = hash_hmac('sha256', $query, $secret);

        return hash_equals($calc, $hmac);
    }

    private function normalizeShop(string $shop): string
    {
        $shop = trim($shop);
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = preg_replace('#/.*$#', '', $shop);
        return rtrim($shop, '/');
    }

    private function isValidShop(string $shop): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop);
    }
}
