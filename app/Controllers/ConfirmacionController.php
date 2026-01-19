<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\PedidosEstadoModel;

class ConfirmacionController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    public function __construct()
    {
        // 🔹 Reutilizamos EXACTAMENTE la misma lógica que Dashboard
        $this->loadShopifyFromConfig();
        if (!$this->shop || !$this->token) {
            $this->loadShopifySecretsFromFile();
        }
        if (!$this->shop || !$this->token) {
            $this->loadShopifyFromEnv();
        }

        $this->shop = preg_replace('#^https?://#', '', trim($this->shop));
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->token = trim($this->token);
    }

    /* =====================================================
     * CONFIG LOADERS (copiados del Dashboard)
     * ===================================================== */

    private function loadShopifyFromConfig(): void
    {
        try {
            $cfg = config('Shopify');
            if (!$cfg) return;
            $this->shop  = (string) ($cfg->shop ?? $this->shop);
            $this->token = (string) ($cfg->token ?? $this->token);
            $this->apiVersion = (string) ($cfg->apiVersion ?? $this->apiVersion);
        } catch (\Throwable $e) {}
    }

    private function loadShopifyFromEnv(): void
    {
        $this->shop  = env('SHOPIFY_STORE_DOMAIN') ?: $this->shop;
        $this->token = env('SHOPIFY_ADMIN_TOKEN') ?: $this->token;
    }

    private function loadShopifySecretsFromFile(): void
    {
        $path = '/home/u756064303/.secrets/shopify.php';
        if (!is_file($path)) return;
        $cfg = require $path;
        if (!is_array($cfg)) return;

        $this->shop  = (string) ($cfg['shop'] ?? $this->shop);
        $this->token = (string) ($cfg['token'] ?? $this->token);
        $this->apiVersion = (string) ($cfg['apiVersion'] ?? $this->apiVersion);
    }

    /* =====================================================
     * CURL SHOPIFY
     * ===================================================== */

    private function curlShopify(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Content-Type: application/json",
                "X-Shopify-Access-Token: {$this->token}",
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
        ];
    }

    /* =====================================================
     * VISTA
     * ===================================================== */

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('confirmacion');
    }

    /* =====================================================
     * MI COLA (Por preparar)
     * ===================================================== */

    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false]);
        }

        $limit = (int) ($this->request->getGet('limit') ?? 10);
        $userId = session('user_id');

        $db = \Config\Database::connect();

        $rows = $db->table('pedidos p')
            ->select("
                p.shopify_order_id AS id,
                p.numero,
                p.cliente,
                p.total,
                p.created_at AS fecha,
                pe.estado
            ")
            ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
            ->where('pe.estado', 'Por preparar')
            ->where('p.assigned_to_user_id', $userId)
            ->orderBy("INSTR(LOWER(p.forma_envio), 'express') DESC")
            ->orderBy('p.created_at', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $rows,
        ]);
    }

    /* =====================================================
     * PULL DESDE SHOPIFY
     * ===================================================== */

    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false]);
        }

        $userId = session('user_id');
        $limit  = 10;

        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders.json"
             . "?financial_status=paid"
             . "&fulfillment_status=unfulfilled"
             . "&limit=50"
             . "&order=created_at%20asc";

        $resp = $this->curlShopify($url);
        if ($resp['status'] >= 400) {
            return $this->response->setJSON(['success' => false]);
        }

        $json = json_decode($resp['body'], true);
        $orders = $json['orders'] ?? [];

        $db = \Config\Database::connect();
        $estadoModel = new PedidosEstadoModel();
        $inserted = 0;

        foreach ($orders as $o) {
            if ($inserted >= $limit) break;

            $shopifyId = (string) $o['id'];

            // ❌ Ya existe estado → saltar
            if ($estadoModel->getEstadoPedido($shopifyId)) {
                continue;
            }

            // ❌ Ya asignado
            $exists = $db->table('pedidos')
                ->where('shopify_order_id', $shopifyId)
                ->where('assigned_to_user_id IS NOT NULL', null, false)
                ->get()->getRowArray();
            if ($exists) continue;

            $cliente = '-';
            if (!empty($o['customer'])) {
                $cliente = trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''));
            }

            // 1️⃣ Guardar / actualizar pedido base
            $db->table('pedidos')->insert([
                'shopify_order_id' => $shopifyId,
                'numero'           => $o['name'] ?? '',
                'cliente'          => $cliente,
                'total'            => $o['total_price'] ?? 0,
                'forma_envio'      => $o['shipping_lines'][0]['title'] ?? '',
                'assigned_to_user_id' => $userId,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);

            // 2️⃣ Estado inicial
            $estadoModel->setEstadoPedido(
                $shopifyId,
                'Por preparar',
                $userId,
                session('nombre') ?? 'Usuario'
            );

            $inserted++;
        }

        return $this->response->setJSON([
            'success'  => true,
            'inserted' => $inserted,
        ]);
    }
}
