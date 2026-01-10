<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;

class DashboardController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    // ✅ Estados permitidos (los nuevos del modal)
    private array $allowedEstados = [
        'Por preparar',
        'A medias',
        'Produccion',
        'Fabricando',
        'Enviado',
    ];

    public function __construct()
    {
        // 1) Config/Shopify.php
        $this->loadShopifyFromConfig();

        // 2) archivo fuera del repo
        if (!$this->shop || !$this->token) {
            $this->loadShopifySecretsFromFile();
        }

        // 3) env() (fallback)
        if (!$this->shop || !$this->token) {
            $this->loadShopifyFromEnv();
        }

        // Normalizar
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
            $cfg = config('Shopify');
            if (!$cfg) return;

            $this->shop       = (string) ($cfg->shop ?? $cfg->SHOP ?? $this->shop);
            $this->token      = (string) ($cfg->token ?? $cfg->TOKEN ?? $this->token);
            $this->apiVersion = (string) ($cfg->apiVersion ?? $cfg->version ?? $cfg->API_VERSION ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'DashboardController loadShopifyFromConfig ERROR: ' . $e->getMessage());
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
            log_message('error', 'DashboardController loadShopifyFromEnv ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifySecretsFromFile(): void
    {
        try {
            $path = '/home/u756064303/.secrets/shopify.php';
            if (!is_file($path)) return;

            $cfg = require $path;
            if (!is_array($cfg)) return;

            $this->shop       = (string) ($cfg['shop'] ?? $this->shop);
            $this->token      = (string) ($cfg['token'] ?? $this->token);
            $this->apiVersion = (string) ($cfg['apiVersion'] ?? $cfg['version'] ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'DashboardController loadShopifySecretsFromFile ERROR: ' . $e->getMessage());
        }
    }

    // =====================================================
    // HELPERS
    // =====================================================

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
            return false;
        }
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

    private function curlShopify(string $url, string $method = 'GET', ?array $payload = null): array
    {
        $headers = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                "Accept: application/json",
                "Content-Type: application/json",
                "X-Shopify-Access-Token: {$this->token}",
            ],
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$headers) {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || strpos($headerLine, ':') === false) return $len;

                [$name, $value] = explode(':', $headerLine, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if (!isset($headers[$name])) $headers[$name] = $value;
                else {
                    if (is_array($headers[$name])) $headers[$name][] = $value;
                    else $headers[$name] = [$headers[$name], $value];
                }
                return $len;
            },
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

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

    // ============================================================
    // ✅ NORMALIZAR ESTADOS (viejos -> nuevos)
    // ============================================================

    private function normalizeEstado(?string $estado): string
    {
        $s = trim((string)($estado ?? ''));
        if ($s === '') return 'Por preparar';

        $lower = mb_strtolower($s);

        $map = [
            'por preparar' => 'Por preparar',
            'pendiente'    => 'Por preparar',

            'a medias'     => 'A medias',
            'amedias'      => 'A medias',

            'produccion'   => 'Produccion',
            'producción'   => 'Produccion',

            'fabricando'   => 'Fabricando',
            'preparado'    => 'Fabricando',

            'enviado'      => 'Enviado',
            'entregado'    => 'Enviado',

            'cancelado'    => 'Por preparar',
            'devuelto'     => 'Por preparar',
        ];

        if (isset($map[$lower])) return $map[$lower];

        foreach ($this->allowedEstados as $ok) {
            if (mb_strtolower($ok) === $lower) return $ok;
        }

        return 'Por preparar';
    }

    // ============================================================
    // ETIQUETAS/TAGS POR USUARIO
    // ============================================================

    private function getEtiquetasUsuario(): array
    {
        // Defaults reales de ETIQUETAS/TAGS (NO estados)
        $defaults = [
            'D.Diseño',
            'P.Produccion',
            'Cancelar pedido',
            'Reembolso completo',
            'No contesta 24h',
        ];

        $userId = session()->get('user_id');
        if (!$userId) return $defaults;

        try {
            $db = \Config\Database::connect();

            $tableExists = $db->query(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = ?
                   AND table_name = ?
                 LIMIT 1",
                [$db->getDatabase(), 'usuarios_etiquetas']
            )->getRowArray();

            if (empty($tableExists)) return $defaults;

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

    // ============================================================
    // VISTA PRINCIPAL
    // ============================================================

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('dashboard', [
            'etiquetasPredeterminadas' => $this->getEtiquetasUsuario(),
        ]);
    }

    // ============================================================
    // PEDIDOS (paginados)
    // ============================================================

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
                    'message' => 'Faltan credenciales Shopify',
                    'orders'  => [],
                    'count'   => 0,
                ])->setStatusCode(200);
            }

            // -----------------------------------------------------
            // 1) COUNT total pedidos (cache 5 min)
            // -----------------------------------------------------
            $cacheKey = 'shopify_orders_count_any';
            $totalOrders = cache($cacheKey);

            if ($totalOrders === null) {
                $countUrl = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/count.json?status=any";
                $countResp = $this->curlShopify($countUrl, 'GET');

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

            $resp = $this->curlShopify($url, 'GET');
            $status = $resp['status'];
            $raw    = $resp['body'];
            $json   = json_decode($raw, true) ?: [];

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
                    'estado'       => 'Por preparar',
                    'etiquetas'    => $o['tags'] ?? '',
                    'articulos'    => $articulos,
                    'estado_envio' => $estado_envio ?: '-',
                    'forma_envio'  => $forma_envio ?: '-',
                    'last_status_change' => null,
                ];
            }

            // -----------------------------------------------------
            // 4) Estado desde BD (OPTIMIZADO)
            //    (respeta tabla pedidos_estado con id)
            // -----------------------------------------------------
            try {
                $db = \Config\Database::connect();
                $dbName = $db->getDatabase();

                $tbl = $db->query(
                    "SELECT 1 FROM information_schema.tables
                     WHERE table_schema = ? AND table_name = ?
                     LIMIT 1",
                    [$dbName, 'pedidos_estado']
                )->getRowArray();

                if (!empty($tbl) && !empty($orders)) {

                    $hasId          = $this->columnExists($db, 'pedidos_estado', 'id');
                    $hasEstado      = $this->columnExists($db, 'pedidos_estado', 'estado');
                    $hasUserName    = $this->columnExists($db, 'pedidos_estado', 'user_name');
                    $hasUserId      = $this->columnExists($db, 'pedidos_estado', 'user_id');
                    $hasCreatedAt   = $this->columnExists($db, 'pedidos_estado', 'created_at');
                    $hasActualizado = $this->columnExists($db, 'pedidos_estado', 'actualizado');

                    if ($hasId && $hasEstado) {
                        $ids = [];
                        foreach ($orders as $o) {
                            if (!empty($o['id'])) $ids[] = (string)$o['id'];
                        }
                        $ids = array_values(array_unique($ids));

                        if (!empty($ids)) {
                            $select = ['id', 'estado'];
                            if ($hasUserName)    $select[] = 'user_name';
                            if ($hasUserId)      $select[] = 'user_id';
                            if ($hasActualizado) $select[] = 'actualizado';
                            if ($hasCreatedAt)   $select[] = 'created_at';

                            $rows = $db->table('pedidos_estado')
                                ->select(implode(',', $select))
                                ->whereIn('id', $ids)
                                ->get()
                                ->getResultArray();

                            $estadoById = [];
                            $userIdsNeeded = [];

                            foreach ($rows as $r) {
                                $rid = (string)($r['id'] ?? '');
                                if ($rid === '') continue;

                                $estadoById[$rid] = $r;

                                if (!$hasUserName && $hasUserId && !empty($r['user_id'])) {
                                    $userIdsNeeded[] = (int)$r['user_id'];
                                }
                            }

                            $usersById = [];
                            if (!empty($userIdsNeeded)) {
                                $userIdsNeeded = array_values(array_unique($userIdsNeeded));
                                try {
                                    $uRows = $db->table('users')
                                        ->select('id, nombre')
                                        ->whereIn('id', $userIdsNeeded)
                                        ->get()
                                        ->getResultArray();

                                    foreach ($uRows as $u) {
                                        $usersById[(int)$u['id']] = $u['nombre'] ?? null;
                                    }
                                } catch (\Throwable $e) {
                                    // no rompe
                                }
                            }

                            foreach ($orders as &$ord) {
                                $oid = (string)($ord['id'] ?? '');
                                if ($oid === '') continue;

                                $row = $estadoById[$oid] ?? null;
                                if (!$row) continue;

                                $ord['estado'] = !empty($row['estado'])
                                    ? $this->normalizeEstado((string)$row['estado'])
                                    : 'Por preparar';

                                $changedAt = null;
                                if ($hasActualizado && !empty($row['actualizado'])) $changedAt = $row['actualizado'];
                                elseif ($hasCreatedAt && !empty($row['created_at'])) $changedAt = $row['created_at'];

                                $uName = null;
                                if ($hasUserName && !empty($row['user_name'])) {
                                    $uName = $row['user_name'];
                                } elseif ($hasUserId && !empty($row['user_id'])) {
                                    $uid = (int)$row['user_id'];
                                    $uName = $usersById[$uid] ?? null;
                                }

                                $ord['last_status_change'] = [
                                    'user_name'  => $uName ?: 'Sistema',
                                    'changed_at' => $changedAt,
                                ];
                            }
                            unset($ord);
                        }
                    }
                }
            } catch (\Throwable $e) {
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

    // ============================================================
    // DETALLES DEL PEDIDO + IMÁGENES LOCALES
    // ============================================================

   public function detalles($orderId)
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON([
            'success' => false,
            'message' => 'No autenticado',
        ]);
    }

    $orderId = (string)$orderId;
    if ($orderId === '') {
        return $this->response->setJSON([
            'success' => false,
            'message' => 'ID inválido',
        ])->setStatusCode(422);
    }

    try {
        if (!$this->shop || !$this->token) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Faltan credenciales Shopify',
            ])->setStatusCode(200);
        }

        // --------------------------------------------------
        // 1) PEDIDO SHOPIFY
        // --------------------------------------------------
        $urlOrder = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/" . urlencode($orderId) . ".json";
        $resp = $this->curlShopify($urlOrder, 'GET');

        if ($resp['status'] >= 400 || $resp['status'] === 0) {
            log_message('error', 'DETALLES ORDER HTTP '.$resp['status'].': '.$resp['body']);
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error consultando pedido en Shopify',
                'status'  => $resp['status'],
            ])->setStatusCode(200);
        }

        $json = json_decode($resp['body'], true) ?: [];
        $order = $json['order'] ?? null;

        if (!$order) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Pedido no encontrado',
            ])->setStatusCode(200);
        }

        // --------------------------------------------------
        // 2) IMÁGENES DE PRODUCTOS (SHOPIFY)
        // --------------------------------------------------
        $lineItems = $order['line_items'] ?? [];
        $productIds = [];

        foreach ($lineItems as $li) {
            if (!empty($li['product_id'])) {
                $productIds[(string)$li['product_id']] = true;
            }
        }
        $productIds = array_keys($productIds);

        $productImages = []; // product_id => url

        foreach ($productIds as $pid) {
            $urlProd = "https://{$this->shop}/admin/api/{$this->apiVersion}/products/{$pid}.json?fields=id,image,images";
            $rP = $this->curlShopify($urlProd, 'GET');
            if ($rP['status'] >= 400 || $rP['status'] === 0) continue;

            $jP = json_decode($rP['body'], true) ?: [];
            $p  = $jP['product'] ?? null;
            if (!$p) continue;

            $img = '';
            if (!empty($p['image']['src'])) {
                $img = $p['image']['src'];
            } elseif (!empty($p['images'][0]['src'])) {
                $img = $p['images'][0]['src'];
            }

            if ($img) {
                $productImages[(string)$pid] = $img;
            }
        }

        // --------------------------------------------------
        // 3) IMÁGENES LOCALES (BD)  ✅ NO DEPENDE DE MODEL
        // --------------------------------------------------
        $imagenesLocales = [];

        try {
            $db = \Config\Database::connect();

            $rows = $db->table('pedido_imagenes')
                ->select('line_index, local_url')
                ->where('order_id', (int)$orderId)
                ->get()
                ->getResultArray();

            foreach ($rows as $r) {
                $idx = (int)($r['line_index'] ?? -1);
                $url = trim((string)($r['local_url'] ?? ''));
                if ($idx >= 0 && $url !== '') {
                    $imagenesLocales[$idx] = $url;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'DETALLES imagenesLocales ERROR: '.$e->getMessage());
        }

        // --------------------------------------------------
        // RESPUESTA FINAL
        // --------------------------------------------------
        return $this->response->setJSON([
            'success'          => true,
            'order'            => $order,
            'product_images'   => $productImages,
            'imagenes_locales' => $imagenesLocales,
        ]);

    } catch (\Throwable $e) {
        log_message(
            'error',
            'DETALLES ERROR: '.$e->getMessage().' :: '.$e->getFile().':'.$e->getLine()
        );

        return $this->response->setJSON([
            'success' => false,
            'message' => 'Error interno cargando detalles',
        ])->setStatusCode(200);
    }
}

    // ============================================================
    // BADGE ESTADO
    // ============================================================

    private function badgeEstado(string $estado): string
    {
        $estado = $this->normalizeEstado($estado);

        $estilos = [
            "Por preparar" => "bg-slate-100 text-slate-800 border border-slate-300",
            "A medias"     => "bg-amber-100 text-amber-900 border border-amber-200",
            "Produccion"   => "bg-purple-100 text-purple-900 border border-purple-200",
            "Fabricando"   => "bg-blue-100 text-blue-900 border border-blue-200",
            "Enviado"      => "bg-emerald-100 text-emerald-900 border border-emerald-200",
        ];

        $clase = $estilos[$estado] ?? "bg-gray-100 text-gray-800 border border-gray-300";
        $estadoEsc = htmlspecialchars($estado, ENT_QUOTES, 'UTF-8');

        return '<span class="px-3 py-1 rounded-full text-xs font-extrabold tracking-wide ' . $clase . '">' . $estadoEsc . '</span>';
    }

    // ============================================================
    // PING / USUARIOS ESTADO
    // ============================================================

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

    public function usuariosEstado()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setJSON(['success' => false])->setStatusCode(401);
            }

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

    // ============================================================
    // ETIQUETAS DISPONIBLES
    // ============================================================

    public function etiquetasDisponibles()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'ok' => false,
                'diseno' => [],
                'produccion' => [],
                'message' => 'No autenticado',
            ])->setStatusCode(401);
        }

        try {
            $userId = (int) (session('user_id') ?? 0);
            $rol    = strtolower(trim((string) (session('role') ?? '')));

            $db = \Config\Database::connect();
            $builder = $db->table('user_tags')->select('tag');

            if ($rol !== 'admin') {
                $builder->where('user_id', $userId);
            }

            $rows = $builder->orderBy('tag', 'ASC')->get()->getResultArray();

            $diseno = [];
            $produccion = [];

            foreach ($rows as $r) {
                $t = (string) ($r['tag'] ?? '');
                if ($t === '') continue;

                if (stripos($t, 'D.') === 0) $diseno[] = $t;
                if (stripos($t, 'P.') === 0) $produccion[] = $t;
            }

            return $this->response->setJSON([
                'ok' => true,
                'diseno' => array_values(array_unique($diseno)),
                'produccion' => array_values(array_unique($produccion)),
            ])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'ETIQUETAS DISPONIBLES ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'diseno' => [],
                'produccion' => [],
            ])->setStatusCode(200);
        }
    }

    // ============================================================
    // GUARDAR ETIQUETAS (Shopify PUT) - unificado
    // ============================================================

    public function guardarEtiquetas()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $orderId = trim((string)($data['id'] ?? ''));
        $tagsRaw = trim((string)($data['tags'] ?? ($data['etiquetas'] ?? '')));

        if ($orderId === '') {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'ID inválido',
            ])->setStatusCode(200);
        }

        if (!$this->shop || !$this->token) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Faltan credenciales Shopify',
            ])->setStatusCode(200);
        }

        // limitar a 6 tags como ya tenías en DashboardController
        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
        $tags = array_slice($tags, 0, 6);

        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/{$orderId}.json";

        $resp = $this->curlShopify($url, 'PUT', [
            'order' => [
                'id'   => (int)$orderId,
                'tags' => implode(', ', $tags),
            ]
        ]);

        if ($resp['status'] >= 400 || $resp['status'] === 0) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Shopify HTTP ' . $resp['status'],
                'shopify_body' => substr((string)$resp['body'], 0, 500),
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Etiquetas guardadas',
            'tags'    => $tags,
        ])->setStatusCode(200);
    }

    // ============================================================
    // SUBIR IMAGEN LOCAL DEL PRODUCTO (mantengo tu versión)
    // ============================================================

    public function subirImagenProducto()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false, 'message' => 'No autenticado'])->setStatusCode(401);
        }

        $orderId = (string) $this->request->getPost("order_id");
        $index   = (string) $this->request->getPost("line_index");
        $file    = $this->request->getFile("file");

        if ($orderId === '' || $index === '') {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Faltan order_id o line_index"
            ])->setStatusCode(422);
        }

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Archivo inválido"
            ])->setStatusCode(422);
        }

        $orderIdInt = (int)$orderId;
        $idxInt = (int)$index;

        $folder = FCPATH . "uploads/pedidos/{$orderIdInt}/";
        if (!is_dir($folder)) {
            @mkdir($folder, 0777, true);
        }

        $ext = $file->getExtension();
        $newName = "item_{$idxInt}." . $ext;

        $file->move($folder, $newName, true);

        $url = base_url("uploads/pedidos/{$orderIdInt}/{$newName}");

        try {
            $db = \Config\Database::connect();
            $db->table('pedido_imagenes')->replace([
                'order_id'   => $orderIdInt,
                'line_index' => $idxInt,
                'local_url'  => $url,
                'status'     => 'ready',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'pedido_imagenes replace ERROR: '.$e->getMessage());
        }

        return $this->response->setJSON([
            "success" => true,
            "url"     => $url
        ]);
    }

    // ============================================================
    // HELPERS IMAGENES (los tuyos)
    // ============================================================

    private function extractImageUrlsFromLineItem(array $item): array
    {
        $urls = [];

        $props = $item['properties'] ?? [];
        if (is_array($props)) {
            foreach ($props as $p) {
                $val = (string)($p['value'] ?? '');
                if ($val === '') continue;

                if (preg_match('#^https?://#i', $val) && preg_match('#\.(png|jpe?g|webp|gif|svg)(\?.*)?$#i', $val)) {
                    $urls[] = $val;
                }

                if (str_starts_with($val, 'data:image/')) {
                    $urls[] = $val;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function buildModifiedImage(string $src, string $destAbsPath): bool
    {
        try {
            $bytes = null;

            if (str_starts_with($src, 'data:image/')) {
                $parts = explode(',', $src, 2);
                $bytes = base64_decode($parts[1] ?? '', true);
            } else {
                $client = \Config\Services::curlrequest(['timeout' => 30, 'http_errors' => false]);
                $r = $client->get($src);
                if ($r->getStatusCode() >= 400) return false;
                $bytes = $r->getBody();
            }

            if (!$bytes) return false;

            $tmp = WRITEPATH . 'cache/img_' . uniqid() . '.bin';
            file_put_contents($tmp, $bytes);

            $image = \Config\Services::image();
            $image->withFile($tmp)
                ->resize(1200, 1200, true, 'width')
                ->convert(IMAGETYPE_WEBP)
                ->save($destAbsPath, 85);

            @unlink($tmp);
            return true;

        } catch (\Throwable $e) {
            log_message('error', 'buildModifiedImage: ' . $e->getMessage());
            return false;
        }
    }

    private function procesarImagenesYEstado(array &$order): void
    {
        $orderId = (int)($order['id'] ?? 0);
        if (!$orderId) return;

        $db = \Config\Database::connect();

        $lineItems = $order['line_items'] ?? [];
        if (!is_array($lineItems)) $lineItems = [];

        $totalRequeridas = 0;
        $totalListas = 0;

        $baseDir = FCPATH . 'uploads/pedidos/' . $orderId . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

        $modelImg = new PedidoImagenModel();
        $imagenesLocales = $modelImg->getByOrder((int)$orderId);
        if (!is_array($imagenesLocales)) $imagenesLocales = [];

        foreach ($lineItems as $idx => &$item) {
            $idx = (int)$idx;

            $urls = $this->extractImageUrlsFromLineItem($item);
            if (!$urls) continue;

            $totalRequeridas++;
            $original = $urls[0];

            $row = $db->table('pedido_imagenes')
                ->where('order_id', $orderId)
                ->where('line_index', $idx)
                ->get()->getRowArray();

            $localUrl = $row['local_url'] ?? null;
            $status   = $row['status'] ?? 'missing';

            if ($localUrl && $status === 'ready') {
                $totalListas++;
                $imagenesLocales[$idx] = $localUrl;
                $item['local_image_url'] = $localUrl;
                continue;
            }

            $db->table('pedido_imagenes')->replace([
                'order_id'     => $orderId,
                'line_index'   => $idx,
                'original_url' => $original,
                'local_url'    => $localUrl,
                'status'       => 'processing',
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

            $fileName = "item_{$idx}.webp";
            $destAbs = $baseDir . $fileName;
            $destUrl = base_url("uploads/pedidos/{$orderId}/{$fileName}");

            $ok = $this->buildModifiedImage($original, $destAbs);

            if ($ok) {
                $totalListas++;
                $imagenesLocales[$idx] = $destUrl;
                $item['local_image_url'] = $destUrl;

                $db->table('pedido_imagenes')->replace([
                    'order_id'     => $orderId,
                    'line_index'   => $idx,
                    'original_url' => $original,
                    'local_url'    => $destUrl,
                    'status'       => 'ready',
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            } else {
                $db->table('pedido_imagenes')->replace([
                    'order_id'     => $orderId,
                    'line_index'   => $idx,
                    'original_url' => $original,
                    'local_url'    => null,
                    'status'       => 'error',
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }
        unset($item);

        $estadoAuto = null;
        if ($totalRequeridas > 0) {
            $estadoAuto = ($totalListas >= $totalRequeridas) ? 'Produccion' : 'A medias';
        }

        $order['imagenes_locales'] = $imagenesLocales;
        $order['auto_estado'] = $estadoAuto;
        $order['auto_images_required'] = $totalRequeridas;
        $order['auto_images_ready'] = $totalListas;

        if ($estadoAuto) {
            $this->guardarEstadoSistema($orderId, $estadoAuto);
        }
    }

    private function guardarEstadoSistema(int $orderId, string $estado): void
    {
        try {
            $db = \Config\Database::connect();

            $estado = $this->normalizeEstado($estado);

            $insert = [
                'estado'     => $estado,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($this->columnExists($db, 'pedidos_estado', 'order_id')) {
                $insert['order_id'] = $orderId;
            } elseif ($this->columnExists($db, 'pedidos_estado', 'pedido_id')) {
                $insert['pedido_id'] = $orderId;
            } else {
                $insert['id'] = $orderId;
            }

            if ($this->columnExists($db, 'pedidos_estado', 'user_id')) {
                $insert['user_id'] = null; // sistema
            }

            $db->table('pedidos_estado')->insert($insert);

        } catch (\Throwable $e) {
            log_message('error', 'guardarEstadoSistema: ' . $e->getMessage());
        }
    }
}
