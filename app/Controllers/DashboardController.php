<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;
use App\Models\PedidosEstadoModel;

class DashboardController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    // ✅ Estados permitidos (los del modal)
    private array $allowedEstados = [
        'Por preparar',
        'Faltan archivos',
        'Confirmado',
        'Diseñado',
        'Por producir',
        'Enviado',
        'Repetir',
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

        // Normalizar dominio
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
            'por preparar'     => 'Por preparar',
            'pendiente'        => 'Por preparar',

            'faltan archivos'  => 'Faltan archivos',
            'faltan archivo'   => 'Faltan archivos',
            'archivos faltan'  => 'Faltan archivos',
            'sin archivos'     => 'Faltan archivos',

            'confirmado'       => 'Confirmado',
            'confirmada'       => 'Confirmado',

            'diseñado'         => 'Diseñado',
            'disenado'         => 'Diseñado',
            'diseño'           => 'Diseñado',
            'ddiseño'          => 'Diseñado',
            'd.diseño'         => 'Diseñado',

            'por producir'     => 'Por producir',
            'produccion'       => 'Por producir',
            'producción'       => 'Por producir',
            'p.produccion'     => 'Por producir',
            'p.producción'     => 'Por producir',
            'fabricando'       => 'Por producir',
            'en produccion'    => 'Por producir',
            'en producción'    => 'Por producir',
            'a medias'         => 'Por producir',
            'produccion '      => 'Por producir',

            'enviado'          => 'Enviado',
            'entregado'        => 'Enviado',

            'repetir'          => 'Repetir',
            'reimpresion'      => 'Repetir',
            'reimpresión'      => 'Repetir',
            'rehacer'          => 'Repetir',
        ];

        if (isset($map[$lower])) return $map[$lower];

        foreach ($this->allowedEstados as $ok) {
            if (mb_strtolower($ok) === $lower) return $ok;
        }

        return 'Por preparar';
    }

    private function moneyToDecimal($v): ?float
    {
        if ($v === null || $v === '') return null;
        $s = trim((string)$v);
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        return (float)$s;
    }

    private function isoToMysql(?string $iso): ?string
    {
        if (!$iso) return null;
        $ts = strtotime($iso);
        if (!$ts) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    private function syncPedidosToDb(array $ordersRaw, array &$syncDebug = null): void
    {
        if (empty($ordersRaw)) return;

        $syncDebug = $syncDebug ?? [
            'shopify_orders_returned' => count($ordersRaw),
            'inserted' => 0,
            'updated' => 0,
            'last_db_error' => null,
        ];

        try {
            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $sql = "
                INSERT INTO pedidos
                    (shopify_order_id, numero, cliente, total, etiquetas, articulos, estado_envio, forma_envio, created_at, synced_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    numero      = VALUES(numero),
                    cliente     = VALUES(cliente),
                    total       = VALUES(total),
                    etiquetas   = VALUES(etiquetas),
                    articulos   = VALUES(articulos),
                    estado_envio= VALUES(estado_envio),
                    forma_envio = VALUES(forma_envio),
                    synced_at   = VALUES(synced_at)
            ";

            foreach ($ordersRaw as $o) {
                $shopifyId = trim((string)($o['id'] ?? ''));
                if ($shopifyId === '') continue;

                $numero = (string)($o['name'] ?? '');

                $cliente = '-';
                if (!empty($o['customer'])) {
                    $cliente = trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''));
                    if ($cliente === '') $cliente = '-';
                }

                $totalDec   = $this->moneyToDecimal($o['total_price'] ?? null);
                $tags       = (string)($o['tags'] ?? '');
                $articulos  = (isset($o['line_items']) && is_array($o['line_items'])) ? count($o['line_items']) : 0;
                $estadoEnv  = (string)($o['fulfillment_status'] ?? '');
                $formaEnvio = (!empty($o['shipping_lines'][0]['title'])) ? (string)$o['shipping_lines'][0]['title'] : '';

                $createdAt = $this->isoToMysql($o['created_at'] ?? null);

                $exists = $db->query("SELECT id FROM pedidos WHERE shopify_order_id = ? LIMIT 1", [$shopifyId])->getRowArray();

                $ok = $db->query($sql, [
                    $shopifyId,
                    $numero,
                    $cliente,
                    $totalDec,
                    $tags,
                    (int)$articulos,
                    $estadoEnv !== '' ? $estadoEnv : null,
                    $formaEnvio !== '' ? $formaEnvio : null,
                    $createdAt,
                    $now,
                ]);

                if (!$ok) {
                    $err = $db->error();
                    $syncDebug['last_db_error'] = $err['message'] ?? 'Unknown DB error';
                } else {
                    if ($exists) $syncDebug['updated']++;
                    else $syncDebug['inserted']++;
                }
            }

        } catch (\Throwable $e) {
            $syncDebug['last_db_error'] = $e->getMessage();
            log_message('error', 'syncPedidosToDb ERROR: ' . $e->getMessage());
        }
    }

    // ============================================================
    // ETIQUETAS/TAGS POR USUARIO
    // ============================================================

    private function getEtiquetasUsuario(): array
    {
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
            $dbSyncDebug = null;
            $this->syncPedidosToDb($ordersRaw, $dbSyncDebug);

            $linkHeader = $resp['headers']['link'] ?? null;
            if (is_array($linkHeader)) $linkHeader = end($linkHeader);
            [$nextPageInfo, $prevPageInfo] = $this->parseLinkHeaderForPageInfo(is_string($linkHeader) ? $linkHeader : null);

            $orders = [];
            foreach ($ordersRaw as $o) {
                // ✅ FIX: asegurar string limpio
                $orderId = trim((string)($o['id'] ?? ''));

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

            // 4) OVERRIDE ESTADO desde BD (pedidos_estado.order_id)
            try {
                $ids = [];
                foreach ($orders as $ord) {
                    if (!empty($ord['id'])) $ids[] = (string)$ord['id'];
                }
                $ids = array_values(array_unique($ids));

                if (!empty($ids)) {
                    $estadoModel = new PedidosEstadoModel();
                    $map = $estadoModel->getEstadosForOrderIds($ids);

                    foreach ($orders as &$ord2) {
                        $oid = (string)($ord2['id'] ?? '');
                        if ($oid === '' || !isset($map[$oid])) continue;

                        $rowEstado = $map[$oid];

                        if (!empty($rowEstado['estado'])) {
                            $ord2['estado'] = $this->normalizeEstado((string)$rowEstado['estado']);
                        }

                        // ✅ FIX CLAVE: tu tabla guarda fecha en "actualizado"
                        // y estado_updated_at te sale NULL (lo vimos en tu screenshot).
                        $changedAt = $rowEstado['estado_updated_at'] ?? null;
                        if (!$changedAt && !empty($rowEstado['actualizado'])) {
                            $changedAt = $rowEstado['actualizado'];
                        }

                        $ord2['last_status_change'] = [
                            'user_name'  => $rowEstado['estado_updated_by_name'] ?? 'Sistema',
                            'changed_at' => $changedAt,
                        ];
                    }
                    unset($ord2);
                }
            } catch (\Throwable $e) {
                log_message('error', 'Override estado pedidos_estado falló: ' . $e->getMessage());
            }

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
                $payload['db_sync_debug'] = $dbSyncDebug;
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
    // GUARDAR ESTADO (endpoint para el modal)
    // ============================================================

    public function guardarEstadoPedido()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $orderId = trim((string)(
            $data['order_id'] ??
            $data['shopify_order_id'] ??
            $data['id'] ??
            ''
        ));

        if ($orderId === '' || $orderId === '0') {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'order_id inválido (vacío o 0). Revisa el frontend / payload.',
                'debug_received' => $data,
            ])->setStatusCode(200);
        }

        $estado = $this->normalizeEstado((string)($data['estado'] ?? ''));

        if (!in_array($estado, $this->allowedEstados, true)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Estado no permitido',
                'debug_estado_recibido' => $data['estado'] ?? null,
                'debug_estado_normalizado' => $estado,
            ])->setStatusCode(200);
        }

        try {
            $userId   = session('user_id');
            $userName = session('nombre') ?? session('user_name') ?? session('name') ?? 'Usuario';

            $model = new PedidosEstadoModel();

            $ok = $model->setEstadoPedido(
                $orderId,
                $estado,
                $userId ? (int)$userId : null,
                (string)$userName
            );

            // ✅ AQUI MISMO: guardar también en historial (solo si OK)
            if ($ok) {
                $db  = \Config\Database::connect();
                $now = date('Y-m-d H:i:s');

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'    => (string)$orderId, // ideal si ya lo cambiaste a VARCHAR(64)
                    'estado'      => $estado,
                    'user_id'     => $userId ? (int)$userId : null,
                    'user_name'   => (string)$userName,
                    'created_at'  => $now,
                    'pedido_json' => null,
                ]);
            }

            return $this->response->setJSON([
                'success'  => (bool)$ok,
                'message'  => $ok ? 'Estado guardado' : 'No se pudo guardar',
                'order_id' => $orderId,
                'estado'   => $estado,
            ])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'guardarEstadoPedido ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno guardando estado',
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

        $orderId = trim((string)$orderId);
        if ($orderId === '' || $orderId === '0') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'ID inválido',
            ]);
        }

        try {
            if (!$this->shop || !$this->token) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Faltan credenciales Shopify',
                ])->setStatusCode(200);
            }

            // 1) Traer pedido desde Shopify
            $urlOrder = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/" . urlencode($orderId) . ".json";
            $resp = $this->curlShopify($urlOrder, 'GET');

            if ($resp['status'] >= 400 || $resp['status'] === 0) {
                log_message('error', 'DETALLES ORDER HTTP ' . $resp['status'] . ': ' . ($resp['body'] ?? ''));
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Error consultando pedido en Shopify',
                    'status'  => $resp['status'],
                ])->setStatusCode(200);
            }

            $json  = json_decode($resp['body'], true) ?: [];
            $order = $json['order'] ?? null;

            if (!$order) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ])->setStatusCode(200);
            }

            // ✅ 2) OVERRIDE ESTADO desde BD (pedidos_estado)
            // Shopify no tiene tu "Por producir", esto es interno.
            try {
                $estadoModel = new \App\Models\PedidosEstadoModel();
                $rowEstado   = $estadoModel->getEstadoPedido((string)$orderId);

                if (!empty($rowEstado) && !empty($rowEstado['estado'])) {
                    $order['estado'] = $this->normalizeEstado((string)$rowEstado['estado']);

                    $changedAt = $rowEstado['estado_updated_at'] ?? null;
                    if (!$changedAt && !empty($rowEstado['actualizado'])) {
                        $changedAt = $rowEstado['actualizado'];
                    }

                    $order['last_status_change'] = [
                        'user_name'  => $rowEstado['estado_updated_by_name'] ?? 'Sistema',
                        'changed_at' => $changedAt,
                    ];
                }
            } catch (\Throwable $e) {
                log_message('error', 'DETALLES override estado ERROR: ' . $e->getMessage());
            }

            // 3) Product images
            $lineItems  = $order['line_items'] ?? [];
            $productIds = [];

            foreach ($lineItems as $li) {
                if (!empty($li['product_id'])) {
                    $productIds[(string)$li['product_id']] = true;
                }
            }
            $productIds = array_keys($productIds);

            $productImages = [];
            foreach ($productIds as $pid) {
                $urlProd = "https://{$this->shop}/admin/api/{$this->apiVersion}/products/{$pid}.json?fields=id,image,images";
                $rP = $this->curlShopify($urlProd, 'GET');
                if ($rP['status'] >= 400 || $rP['status'] === 0) continue;

                $jP = json_decode($rP['body'], true) ?: [];
                $p  = $jP['product'] ?? null;
                if (!$p) continue;

                $img = '';
                if (!empty($p['image']['src'])) $img = $p['image']['src'];
                elseif (!empty($p['images'][0]['src'])) $img = $p['images'][0]['src'];

                if ($img) $productImages[(string)$pid] = $img;
            }

            // 4) Imágenes locales
            $imagenesLocales = [];
            try {
                $db = \Config\Database::connect();

                $rows = $db->table('pedido_imagenes')
                    ->select('line_index, local_url')
                    ->where('order_id', (string)$orderId)
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
                log_message('error', 'DETALLES imagenesLocales ERROR: ' . $e->getMessage());
            }

            return $this->response->setJSON([
                'success'          => true,
                'order'            => $order, // ✅ ahora viene con estado override si existe
                'product_images'   => $productImages,
                'imagenes_locales' => $imagenesLocales,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'DETALLES ERROR: ' . $e->getMessage() . ' :: ' . $e->getFile() . ':' . $e->getLine());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno cargando detalles',
            ])->setStatusCode(200);
        }
    }


    // ============================================================
    // ENDPOINTS
    // ============================================================

    public function etiquetasDisponibles()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'etiquetas' => $this->getEtiquetasUsuario(),
        ]);
    }

    public function ping()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'time' => date('Y-m-d H:i:s'),
            'user' => session('nombre') ?? 'Usuario',
        ]);
    }

    public function usuariosEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'online' => [],
            'offline' => [],
        ]);
    }
}
