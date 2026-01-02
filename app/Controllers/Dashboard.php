<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Dashboard extends BaseController
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2024-01';

    public function __construct()
    {
        // ✅ Cargar credenciales desde archivo fuera del repo
        $this->loadShopifySecretsFromFile();

        // Normalizar shop
        $this->shop = trim($this->shop);
        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->shop = rtrim($this->shop, '/');

        $this->token = trim($this->token);
        $this->apiVersion = trim($this->apiVersion ?: '2024-01');
    }

    private function loadShopifySecretsFromFile(): void
    {
        try {
            // ✅ ruta real (fuera del repo)
            $path = '/home/u756064303/.secrets/shopify.php';

            if (!is_file($path)) {
                log_message('error', "Secrets shopify.php no existe: {$path}");
                return;
            }

            $cfg = require $path;

            if (!is_array($cfg)) {
                log_message('error', "Secrets shopify.php inválido (no retorna array): {$path}");
                return;
            }

            $this->shop = (string)($cfg['shop'] ?? '');
            $this->token = (string)($cfg['token'] ?? '');
            $this->apiVersion = (string)($cfg['apiVersion'] ?? '2024-01');
        } catch (\Throwable $e) {
            log_message('error', 'loadShopifySecretsFromFile ERROR: ' . $e->getMessage());
        }
    }

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('dashboard');
    }

    public function pedidos()
    {
        return $this->pedidosPaginados();
    }

    public function filter()
    {
        return $this->pedidosPaginados();
    }

    private function pedidosPaginados(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        try {
            $pageInfo = (string) ($this->request->getGet('page_info') ?? '');
            $limit    = 50;

            $page = (int) ($this->request->getGet('page') ?? 1);
            if ($page < 1) $page = 1;

            $debug = (string) ($this->request->getGet('debug') ?? '');
            $debugEnabled = ($debug === '1' || $debug === 'true');

            // ✅ Credenciales desde shopify.php
            $shop  = $this->shop;
            $token = $this->token;
            $apiVersion = $this->apiVersion ?: '2024-01';

            if (!$shop || !$token) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Faltan credenciales Shopify en /home/u756064303/.secrets/shopify.php',
                    'orders'  => [],
                    'count'   => 0,
                ])->setStatusCode(200);
            }

            $client = \Config\Services::curlrequest([
                'timeout' => 25,
                'http_errors' => false,
            ]);

            // -----------------------------------------------------
            // 1) COUNT total pedidos (cache 5 min)
            // -----------------------------------------------------
            $cacheKey = 'shopify_orders_count_any';
            $totalOrders = cache($cacheKey);

            $countStatus = null;
            $countRaw = null;

            if ($totalOrders === null) {
                $countUrl = "https://{$shop}/admin/api/{$apiVersion}/orders/count.json?status=any";
                $countResp = $client->get($countUrl, [
                    'headers' => [
                        'X-Shopify-Access-Token' => $token,
                        'Accept' => 'application/json',
                    ],
                ]);

                $countStatus = $countResp->getStatusCode();
                $countRaw = (string) $countResp->getBody();
                $countJson = json_decode($countRaw, true) ?: [];

                if ($countStatus >= 200 && $countStatus < 300) {
                    $totalOrders = (int) ($countJson['count'] ?? 0);
                    cache()->save($cacheKey, $totalOrders, 300);
                } else {
                    $totalOrders = 0;
                    log_message('error', 'SHOPIFY COUNT HTTP ' . $countStatus . ': ' . $countRaw);
                }
            }

            $totalPages = $totalOrders > 0 ? (int) ceil($totalOrders / $limit) : null;

            // -----------------------------------------------------
            // 2) ORDERS (50 en 50)
            // -----------------------------------------------------
            if ($pageInfo !== '') {
                $url = "https://{$shop}/admin/api/{$apiVersion}/orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
            } else {
                $url = "https://{$shop}/admin/api/{$apiVersion}/orders.json?limit={$limit}&status=any&order=created_at%20desc";
            }

            $resp = $client->get($url, [
                'headers' => [
                    'X-Shopify-Access-Token' => $token,
                    'Accept' => 'application/json',
                ],
            ]);

            $status = $resp->getStatusCode();
            $raw    = (string) $resp->getBody();
            $json   = json_decode($raw, true) ?: [];

            if ($status >= 400) {
                log_message('error', 'SHOPIFY ORDERS HTTP ' . $status . ': ' . $raw);

                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Error consultando Shopify',
                    'orders'  => [],
                    'count'   => 0,
                    'status'  => $status,
                    'shopify_body' => $debugEnabled ? $raw : null,
                ])->setStatusCode(200);
            }

            if (!empty($json['errors'])) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Shopify devolvió errors',
                    'orders'  => [],
                    'count'   => 0,
                    'status'  => $status,
                    'shopify_errors' => $json['errors'],
                    'shopify_body' => $debugEnabled ? $raw : null,
                ])->setStatusCode(200);
            }

            $ordersRaw = $json['orders'] ?? [];

            // next/prev page_info (Link header)
            $linkHeader = $resp->getHeaderLine('Link');
            $nextPageInfo = null;
            $prevPageInfo = null;

            if ($linkHeader) {
                if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="next"/', $linkHeader, $m)) {
                    $nextPageInfo = urldecode($m[1]);
                }
                if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="previous"/', $linkHeader, $m2)) {
                    $prevPageInfo = urldecode($m2[1]);
                }
            }

            // -----------------------------------------------------
            // 3) Mapear formato dashboard
            // -----------------------------------------------------
            $orders = [];
            foreach ($ordersRaw as $o) {
                $orderId = $o['id'] ?? null;

                $numero = $o['name'] ?? ('#' . ($o['order_number'] ?? $orderId));
                $fecha  = isset($o['created_at']) ? substr($o['created_at'], 0, 10) : '-';

                $cliente = '-';
                if (!empty($o['customer'])) {
                    $cliente = trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''));
                    if ($cliente === '') $cliente = '-';
                }

                $total = isset($o['total_price']) ? ($o['total_price'] . ' €') : '-';
                $articulos = isset($o['line_items']) ? count($o['line_items']) : 0;

                $estado_envio = $o['fulfillment_status'] ?? '-';
                $forma_envio  = (!empty($o['shipping_lines'][0]['title'])) ? $o['shipping_lines'][0]['title'] : '-';

                $orders[] = [
                    'id'           => $orderId,
                    'numero'       => $numero,
                    'fecha'        => $fecha,
                    'cliente'      => $cliente,
                    'total'        => $total,
                    'estado'       => (!empty($o['tags']) ? 'Producción' : 'Por preparar'),
                    'etiquetas'    => $o['tags'] ?? '',
                    'articulos'    => $articulos,
                    'estado_envio' => $estado_envio ?: '-',
                    'forma_envio'  => $forma_envio ?: '-',
                    'last_status_change' => null,
                ];
            }

            // -----------------------------------------------------
            // 4) Último cambio desde BD
            // -----------------------------------------------------
            $db = \Config\Database::connect();

            foreach ($orders as &$ord) {
                $orderId = $ord['id'] ?? null;
                if (!$orderId) {
                    $ord['last_status_change'] = null;
                    continue;
                }

                $row = $db->table('pedidos_estado')
                    ->select('created_at, user_id')
                    ->where('id', $orderId)
                    ->orderBy('created_at', 'DESC')
                    ->limit(1)
                    ->get()
                    ->getRowArray();

                $userName = 'Sistema';
                if (!empty($row['user_id'])) {
                    $u = $db->table('users')->where('id', $row['user_id'])->get()->getRowArray();
                    if ($u && !empty($u['nombre'])) $userName = $u['nombre'];
                }

                $ord['last_status_change'] = [
                    'user_name'  => $userName,
                    'changed_at' => $row['created_at'] ?? null,
                ];
            }
            unset($ord);

            // -----------------------------------------------------
            // 5) Respuesta final + debug opcional
            // -----------------------------------------------------
            $payload = [
                'success'        => true,
                'orders'         => $orders,
                'count'          => count($orders),
                'limit'          => $limit,
                'page'           => $page,
                'total_orders'   => $totalOrders,
                'total_pages'    => $totalPages,
                'next_page_info' => $nextPageInfo,
                'prev_page_info' => $prevPageInfo,
            ];

            if ($debugEnabled) {
                $payload['shopify_debug'] = [
                    'shop' => $shop,
                    'api_version' => $apiVersion,
                    'orders_url' => $url,
                    'orders_status' => $status,
                    'link' => $linkHeader,
                    'orders_returned' => count($ordersRaw),
                ];
            }

            return $this->response->setJSON($payload);

        } catch (\Throwable $e) {
            log_message('error', 'DASHBOARD PEDIDOS ERROR: ' . $e->getMessage());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno consultando pedidos',
                'orders'  => [],
                'count'   => 0,
            ])->setStatusCode(200);
        }
    }

    // =====================================================
    // PING (users.last_seen) - success para tu JS
    // =====================================================
    public function ping()
    {
        try {
            $userId = session('user_id');
            if (!$userId) {
                return $this->response->setJSON(['success' => false])->setStatusCode(200);
            }

            $db = \Config\Database::connect();
            if ($this->columnExists($db, 'users', 'last_seen')) {
                $db->table('users')->where('id', $userId)->update([
                    'last_seen' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->response->setJSON(['success' => true])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'PING ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['success' => false])->setStatusCode(200);
        }
    }

    // =====================================================
    // USUARIOS ESTADO - success, users[] con online
    // =====================================================
    public function usuariosEstado()
    {
        try {
            $db = \Config\Database::connect();

            if (!$this->columnExists($db, 'users', 'last_seen')) {
                return $this->response->setJSON([
                    'success' => true,
                    'online_count' => 0,
                    'offline_count' => 0,
                    'users' => [],
                ])->setStatusCode(200);
            }

            $usuarios = $db->table('users')
                ->select('id, nombre, role, last_seen')
                ->orderBy('nombre', 'ASC')
                ->get()
                ->getResultArray();

            $now = time();
            $onlineThreshold = 120;

            foreach ($usuarios as &$u) {
                $ts = $u['last_seen'] ? strtotime($u['last_seen']) : 0;
                $u['online'] = ($ts > 0 && ($now - $ts) <= $onlineThreshold);
            }
            unset($u);

            $onlineCount = 0;
            $offlineCount = 0;
            foreach ($usuarios as $u) {
                if (!empty($u['online'])) $onlineCount++;
                else $offlineCount++;
            }

            return $this->response->setJSON([
                'success' => true,
                'online_count' => $onlineCount,
                'offline_count' => $offlineCount,
                'users' => $usuarios,
            ])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'USUARIOS_ESTADO ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'success' => false,
                'online_count' => 0,
                'offline_count' => 0,
                'users' => [],
            ])->setStatusCode(200);
        }
    }

    private function columnExists($db, string $table, string $column): bool
    {
        try {
            $dbName = $db->getDatabase();

            $row = $db->query(
                "SELECT 1
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1",
                [$dbName, $table, $column]
            )->getRowArray();

            return !empty($row);
        } catch (\Throwable $e) {
            log_message('error', 'columnExists ERROR: ' . $e->getMessage());
            return false;
        }
    }
}
