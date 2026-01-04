<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Dashboard extends BaseController
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    public function __construct()
    {
        // 1) Primero intenta leer de app/Config/Shopify.php (lo ideal)
        $this->loadShopifyFromConfig();

        // 2) Si falta algo, intenta leer del archivo fuera del repo
        if (!$this->shop || !$this->token) {
            $this->loadShopifySecretsFromFile();
        }

        // 3) Si aún falta, intenta env() (por si acaso)
        if (!$this->shop || !$this->token) {
            $this->loadShopifyFromEnv();
        }

        // Normalizar shop y apiVersion
        $this->shop = trim($this->shop);
        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->shop = rtrim($this->shop, '/');

        $this->token = trim($this->token);
        $this->apiVersion = trim($this->apiVersion ?: '2025-10');
    }

    // =====================================================
    // CONFIG LOADERS
    // =====================================================

    private function loadShopifyFromConfig(): void
    {
        try {
            // En CI4 puedes usar config('Shopify')
            $cfg = config('Shopify');
            if (!$cfg) return;

            // Soporta distintas formas de declarar en Config/Shopify.php
            $this->shop       = (string) ($cfg->shop ?? $cfg->SHOP ?? $this->shop);
            $this->token      = (string) ($cfg->token ?? $cfg->TOKEN ?? $this->token);
            $this->apiVersion = (string) ($cfg->apiVersion ?? $cfg->version ?? $cfg->API_VERSION ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'loadShopifyFromConfig ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifyFromEnv(): void
    {
        try {
            $shop  = (string) env('SHOPIFY_STORE_DOMAIN');
            $token = (string) env('SHOPIFY_ADMIN_TOKEN');
            $ver   = (string) (env('SHOPIFY_API_VERSION') ?: '2025-10');

            if (!empty(trim($shop)))  $this->shop = $shop;
            if (!empty(trim($token))) $this->token = $token;
            if (!empty(trim($ver)))   $this->apiVersion = $ver;
        } catch (\Throwable $e) {
            log_message('error', 'loadShopifyFromEnv ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifySecretsFromFile(): void
    {
        try {
            // ✅ ruta fuera del repo
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

            $this->shop       = (string) ($cfg['shop'] ?? $this->shop);
            $this->token      = (string) ($cfg['token'] ?? $this->token);
            $this->apiVersion = (string) ($cfg['apiVersion'] ?? $cfg['version'] ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'loadShopifySecretsFromFile ERROR: ' . $e->getMessage());
        }
    }

    // =====================================================
    // SHOPIFY CURL GET
    // =====================================================

    /**
     * ✅ Request Shopify usando cURL NATIVO (evita bloqueos de hosting con curlrequest)
     * Devuelve: ['status'=>int, 'body'=>string, 'headers'=>array, 'error'=>string|null]
     */
    private function shopifyGet(string $fullUrl): array
    {
        $headers = [];

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                "Accept: application/json",
                "Content-Type: application/json",
            ],
            // Captura headers
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$headers) {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || strpos($headerLine, ':') === false) return $len;

                [$name, $value] = explode(':', $headerLine, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                // Shopify puede repetir headers; guardamos como string si es único
                if (!isset($headers[$name])) $headers[$name] = $value;
                else {
                    if (is_array($headers[$name])) $headers[$name][] = $value;
                    else $headers[$name] = [$headers[$name], $value];
                }
                return $len;
            },
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);

        curl_close($ch);

        return [
            'status'  => $status,
            'body'    => is_string($body) ? $body : '',
            'headers' => $headers,
            'error'   => $err ?: null,
        ];
    }

    private function parseLinkHeaderForPageInfo(?string $linkHeader): array
    {
        $next = null;
        $prev = null;

        if (!$linkHeader) return [$next, $prev];

        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="next"/', $linkHeader, $m)) {
            $next = urldecode($m[1]);
        }
        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="previous"/', $linkHeader, $m2)) {
            $prev = urldecode($m2[1]);
        }

        return [$next, $prev];
    }

    // =====================================================
    // ✅ ETIQUETAS (FIX DEL ERROR: método faltante)
    // =====================================================

    /**
     * Devuelve etiquetas disponibles para el usuario.
     * - Si existe tabla usuarios_etiquetas, trae las del usuario.
     * - Si no existe (o falla), devuelve defaults sin romper dashboard.
     */
    private function getEtiquetasUsuario(): array
    {
        $defaults = [
            'Por preparar',
            'Preparado',
            'Enviado',
            'Entregado',
            'Cancelado',
            'Devuelto',
        ];

        $userId = session()->get('user_id');
        if (!$userId) {
            return $defaults;
        }

        try {
            $db = \Config\Database::connect();

            // Si no tienes esa tabla, no rompas el dashboard
            // Cambia "usuarios_etiquetas" y columnas si tu BD usa otros nombres
            $tableExists = $db->query(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = ?
                   AND table_name = ?
                 LIMIT 1",
                [$db->getDatabase(), 'usuarios_etiquetas']
            )->getRowArray();

            if (empty($tableExists)) {
                return $defaults;
            }

            $rows = $db->table('usuarios_etiquetas')
                ->select('etiqueta')
                ->where('user_id', $userId)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            $etiquetas = [];
            foreach ($rows as $r) {
                $val = trim((string)($r['etiqueta'] ?? ''));
                if ($val !== '') $etiquetas[] = $val;
            }

            return !empty($etiquetas) ? $etiquetas : $defaults;
        } catch (\Throwable $e) {
            log_message('error', 'getEtiquetasUsuario ERROR: ' . $e->getMessage());
            return $defaults;
        }
    }

    // =====================================================
    // VISTA
    // =====================================================

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('dashboard', [
            'etiquetasPredeterminadas' => $this->getEtiquetasUsuario(),
        ]);
    }

    // =====================================================
    // ENDPOINTS pedidos
    // =====================================================

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

            if (!$this->shop || !$this->token) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Faltan credenciales Shopify (Config/Shopify.php o /home/u756064303/.secrets/shopify.php o env)',
                    'orders'  => [],
                    'count'   => 0,
                ])->setStatusCode(200);
            }

            // -----------------------------------------------------
            // 1) COUNT total pedidos (cache 5 min)
            // -----------------------------------------------------
            $cacheKey = 'shopify_orders_count_any';
            $totalOrders = cache($cacheKey);

            $countStatus = null;
            $countRaw = null;

            if ($totalOrders === null) {
                $countUrl = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/count.json?status=any";
                $countResp = $this->shopifyGet($countUrl);

                $countStatus = $countResp['status'];
                $countRaw = $countResp['body'];

                $countJson = json_decode($countRaw, true) ?: [];

                if ($countStatus >= 200 && $countStatus < 300) {
                    $totalOrders = (int) ($countJson['count'] ?? 0);
                    cache()->save($cacheKey, $totalOrders, 300);
                } else {
                    $totalOrders = 0;
                    log_message('error', 'SHOPIFY COUNT HTTP ' . $countStatus . ': ' . substr($countRaw ?? '', 0, 500));
                }
            }

            $totalPages = $totalOrders > 0 ? (int) ceil($totalOrders / $limit) : null;

            // -----------------------------------------------------
            // 2) ORDERS (50 en 50) con page_info
            // -----------------------------------------------------
            if ($pageInfo !== '') {
                $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
            } else {
                $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders.json?limit={$limit}&status=any&order=created_at%20desc";
            }

            $resp = $this->shopifyGet($url);

            $status = $resp['status'];
            $raw    = $resp['body'];
            $json   = json_decode($raw, true) ?: [];

            // Nota: si hay bloqueo del hosting, aquí suele venir status 0 + error
            if ($status === 0 || !empty($resp['error'])) {
                log_message('error', 'SHOPIFY CURL ERROR: ' . ($resp['error'] ?? 'unknown'));

                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Error conectando con Shopify (cURL)',
                    'orders'  => [],
                    'count'   => 0,
                    'status'  => $status,
                    'curl_error' => $resp['error'],
                    'shopify_body' => $debugEnabled ? $raw : null,
                ])->setStatusCode(200);
            }

            if ($status >= 400) {
                log_message('error', 'SHOPIFY ORDERS HTTP ' . $status . ': ' . substr($raw ?? '', 0, 500));

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

            // Link header para page_info
            $linkHeader = $resp['headers']['link'] ?? null;
            if (is_array($linkHeader)) $linkHeader = end($linkHeader);

            [$nextPageInfo, $prevPageInfo] = $this->parseLinkHeaderForPageInfo(is_string($linkHeader) ? $linkHeader : null);

            // -----------------------------------------------------
            // 3) Mapear formato dashboard
            // -----------------------------------------------------
            $orders = [];
            foreach ($ordersRaw as $o) {
                $orderId = $o['id'] ?? null;

                $numero = $o['name'] ?? ('#' . ($o['order_number'] ?? $orderId));
                $fecha  = isset($o['created_at']) ? substr((string)$o['created_at'], 0, 10) : '-';

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
                    // OJO: tu frontend espera HTML badge a veces; aquí lo dejamos simple
                    'estado'       => (!empty($o['tags']) ? 'Producción' : 'Por preparar'),
                    'etiquetas'    => $o['tags'] ?? '',
                    'articulos'    => $articulos,
                    'estado_envio' => $estado_envio ?: '-',
                    'forma_envio'  => $forma_envio ?: '-',
                    'last_status_change' => null,
                ];
            }

            // -----------------------------------------------------
// 4) Último cambio desde BD (NO ROMPER si algo falla)
// -----------------------------------------------------
try {
    $db = \Config\Database::connect();

    // ✅ verifica si existe la tabla pedidos_estado
    $dbName = $db->getDatabase();
    $tbl = $db->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = ? AND table_name = ?
         LIMIT 1",
        [$dbName, 'pedidos_estado']
    )->getRowArray();

    if (!empty($tbl)) {

        // ✅ detecta si la FK es pedido_id o order_id
        $hasPedidoId = $this->columnExists($db, 'pedidos_estado', 'pedido_id');
        $hasOrderId  = $this->columnExists($db, 'pedidos_estado', 'order_id');

        // ✅ detecta columnas opcionales
        $hasEstado    = $this->columnExists($db, 'pedidos_estado', 'estado');
        $hasUserName  = $this->columnExists($db, 'pedidos_estado', 'user_name');
        $hasCreatedAt = $this->columnExists($db, 'pedidos_estado', 'created_at');

        foreach ($orders as &$ord) {
            $orderId = $ord['id'] ?? null;
            if (!$orderId) continue;

            // Si no hay columna FK, no hacemos nada
            if (!$hasPedidoId && !$hasOrderId) {
                $ord['last_status_change'] = $ord['last_status_change'] ?? null;
                continue;
            }

            $q = $db->table('pedidos_estado');

            // select solo lo que existe
            if ($hasEstado)    $q->select('estado');
            if ($hasUserName)  $q->select('user_name');
            if ($hasCreatedAt) $q->select('created_at');

            if ($hasPedidoId) $q->where('pedido_id', $orderId);
            else              $q->where('order_id',  $orderId);

            if ($hasCreatedAt) $q->orderBy('created_at', 'DESC');
            else               $q->orderBy('id', 'DESC');

            $row = $q->limit(1)->get()->getRowArray();

            if ($row) {
                // Si existe estado, lo usamos como estado real
                if ($hasEstado && !empty($row['estado'])) {
                    $ord['estado'] = $row['estado'];
                }

                // last_status_change para el front
                $ord['last_status_change'] = [
                    'user_name'  => ($hasUserName && !empty($row['user_name'])) ? $row['user_name'] : 'Sistema',
                    'changed_at' => ($hasCreatedAt && !empty($row['created_at'])) ? $row['created_at'] : null,
                ];
            }
        }
        unset($ord);
    }

} catch (\Throwable $e) {
    // ✅ IMPORTANTÍSIMO: NO romper pedidos si BD falla
    log_message('error', 'Bloque pedidos_estado falló: ' . $e->getMessage());
}


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
                    'shop' => $this->shop,
                    'api_version' => $this->apiVersion,
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
                $u['last_seen_ts'] = $ts;
                $u['seconds_since_seen'] = ($ts > 0) ? ($now - $ts) : null;

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
