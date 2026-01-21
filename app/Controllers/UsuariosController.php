<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;
use App\Models\PedidosEstadoModel;

class UsuariosController extends Controller
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
        // 1) Config/Shopify.php $estadoModel
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
    // CONFIG LOADERS  dashboard
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
            log_message('error', 'DRepetirController loadShopifyFromConfig ERROR: ' . $e->getMessage());
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
            log_message('error', 'UsuariosController loadShopifyFromEnv ERROR: ' . $e->getMessage());
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
            log_message('error', 'UsuariosController loadShopifySecretsFromFile ERROR: ' . $e->getMessage());
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
            // base
            'por preparar'     => 'Por preparar',
            'pendiente'        => 'Por preparar',

            // faltan archivos
            'faltan archivos'  => 'Faltan archivos',
            'faltan archivo'   => 'Faltan archivos',
            'archivos faltan'  => 'Faltan archivos',
            'sin archivos'     => 'Faltan archivos',

            // confirmado
            'confirmado'       => 'Confirmado',
            'confirmada'       => 'Confirmado',

            // diseñado
            'diseñado'         => 'Diseñado',
            'disenado'         => 'Diseñado',
            'diseño'           => 'Diseñado',
            'ddiseño'          => 'Diseñado',
            'd.diseño'         => 'Diseñado',

            // por producir
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

            // enviado
            'enviado'          => 'Enviado',
            'entregado'        => 'Enviado',

            // repetir
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

    }   catch (\Throwable $e) {
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

        return view('usuarios');
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
        if (!$this->shop || !$this->token) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Faltan credenciales Shopify',
                'orders'  => [],
                'count'   => 0,
            ])->setStatusCode(200);
        }

        $limit = 50;

        $page = (int)($this->request->getGet('page') ?? 1);
        if ($page < 1) $page = 1;

        $offset = ($page - 1) * $limit;

        // ✅ 1) IDs desde BD SOLO estado Repetir
        $estadoModel = new PedidosEstadoModel();
        $totalOrders = (int)$estadoModel->countByEstado('Repetir');
        $idsPage     = $estadoModel->getOrderIdsByEstado('Repetir', $limit, $offset);

        if (!$idsPage) {
            return $this->response->setJSON([
                'success' => true,
                'orders'  => [],
                'count'   => 0,
                'limit'   => $limit,
                'page'    => $page,
                'total_orders' => $totalOrders,
                'total_pages'  => (int)ceil($totalOrders / $limit),
                'next_page_info' => null,
                'prev_page_info' => null,
            ])->setStatusCode(200);
        }

        // ✅ 2) Pedir a Shopify solo esos IDs
        $idsStr = implode(',', array_map('strval', $idsPage));
        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders.json?status=any&ids={$idsStr}";
        $resp = $this->curlShopify($url, 'GET');

        $status = $resp['status'];
        $raw    = $resp['body'];
        $json   = json_decode($raw, true) ?: [];

        if ($status === 0 || !empty($resp['error']) || $status >= 400) {
            log_message('error', 'SHOPIFY repetir ids HTTP ' . $status . ': ' . substr($raw ?? '', 0, 500));
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error consultando Shopify (ids)',
                'orders'  => [],
                'count'   => 0,
                'status'  => $status,

            ])->setStatusCode(200);

        }

   // ✅ 3) Mapear al formato del panel
        
   $ordersRaw = $json['orders'] ?? [];
        
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
            // ✅ Reordenar lo que devuelve Shopify según idsPage (paginación estable)  implode
        $pos = array_flip(array_map('strval', $idsPage));

        usort($ordersRaw, function($a, $b) use ($pos) {
        $ia = $pos[(string)($a['id'] ?? '')] ?? PHP_INT_MAX;
        $ib = $pos[(string)($b['id'] ?? '')] ?? PHP_INT_MAX;
        return $ia <=> $ib;
}); 

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
                'estado'       => 'Repetir',
                'etiquetas'    => $o['tags'] ?? '',
                'articulos'    => $articulos,
                'estado_envio' => $estado_envio ?: '-',
                'forma_envio'  => $forma_envio ?: '-',
                'last_status_change' => null,
            ];
        }

        // ✅ 4) último cambio desde BD
        try {
            $ids = array_values(array_unique(array_filter(array_map(fn($x) => (string)($x['id'] ?? ''), $orders))));
            if ($ids) {
                $map = $estadoModel->getEstadosForOrderIds($ids);
                foreach ($orders as &$ord) {
                    $oid = (string)($ord['id'] ?? '');
                    if ($oid && isset($map[$oid])) {
                        $row = $map[$oid];
                        $ord['last_status_change'] = [
                            'user_name'  => $row['estado_updated_by_name'] ?? 'Sistema',
                            'changed_at' => $row['estado_updated_at'] ?? null,
                        ];
                    }
                }
                unset($ord);
            }
        } catch (\Throwable $e) {
            log_message('error', 'last_status_change repetir: ' . $e->getMessage());
        }

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders,
            'count'   => count($orders),
            'limit'   => $limit,
            'page'    => $page,
            'total_orders' => $totalOrders,
            'total_pages'  => (int)ceil($totalOrders / $limit),
            'next_page_info' => null,
            'prev_page_info' => null,
        ])->setStatusCode(200);

    } catch (\Throwable $e) {
        log_message('error', 'REPETIR PEDIDOS ERROR: ' . $e->getMessage());
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

        $orderId = (int)($data['order_id'] ?? ($data['id'] ?? 0));
        $estado  = $this->normalizeEstado((string)($data['estado'] ?? ''));

        if (!$orderId) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'order_id inválido',
            ])->setStatusCode(200);
        }

        if (!in_array($estado, $this->allowedEstados, true)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Estado no permitido',
            ])->setStatusCode(200);
        }

        try {
            $userId = session('user_id');
            $userName = session('nombre') ?? session('user_name') ?? session('name') ?? 'Usuario';

            $model = new PedidosEstadoModel();
            $ok = $model->setEstadoPedido($orderId, $estado, $userId ? (int)$userId : null, (string)$userName);

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

            // 1) PEDIDO SHOPIFY
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

            // 2) IMÁGENES DE PRODUCTOS (SHOPIFY)
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

            // 3) IMÁGENES LOCALES (BD)
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

            return $this->response->setJSON([
                'success'          => true,
                'order'            => $order,
                'product_images'   => $productImages,
                'imagenes_locales' => $imagenesLocales,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'DETALLES ERROR: '.$e->getMessage().' :: '.$e->getFile().':'.$e->getLine());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno cargando detalles',
            ])->setStatusCode(200);
        }
    }

    



    public function cambiarClave(): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'No autenticado',
            'csrf' => csrf_hash(),
        ]);
    }

    $userId = (int) (session()->get('user_id') ?? 0);
    if ($userId <= 0) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'Sesión inválida (sin user_id).',
            'csrf' => csrf_hash(),
        ]);
    }

    $data = $this->request->getJSON(true);
    if (!is_array($data)) $data = [];

    $currentPassword = trim((string)($data['currentPassword'] ?? ''));
    $newPassword     = trim((string)($data['newPassword'] ?? ''));

    if ($currentPassword === '' || $newPassword === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'Completa todos los campos.',
            'csrf' => csrf_hash(),
        ]);
    }

    if (strlen($newPassword) < 8) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'La nueva clave debe tener al menos 8 caracteres.',
            'csrf' => csrf_hash(),
        ]);
    }

    if ($currentPassword === $newPassword) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'La nueva clave no puede ser igual a la actual.',
            'csrf' => csrf_hash(),
        ]);
    }

    try {
        $db = \Config\Database::connect();

        // ✅ AJUSTA AQUÍ: tabla + campo de password
        $table = $db->table('usuarios');

        // Opción 1 (recomendada): password_hash
        $user = $table->select('id, password_hash')
                      ->where('id', $userId)
                      ->get()
                      ->getRowArray();

        // --- Si tu campo se llama "password" en vez de password_hash, usa esto:
        // $user = $table->select('id, password')
        //               ->where('id', $userId)
        //               ->get()
        //               ->getRowArray();

        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok' => false,
                'message' => 'Usuario no encontrado.',
                'csrf' => csrf_hash(),
            ]);
        }

        $hashActual = (string)($user['password_hash'] ?? '');
        // --- Si usas "password":
        // $hashActual = (string)($user['password'] ?? '');

        if ($hashActual === '' || !password_verify($currentPassword, $hashActual)) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'message' => 'La clave actual no es correcta.',
                'csrf' => csrf_hash(),
            ]);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // ✅ Guarda en BD (tiempo real)
        $ok = $table->where('id', $userId)->update([
            'password_hash' => $newHash,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        // --- Si tu campo es "password":
        // $ok = $table->where('id', $userId)->update([
        //     'password' => $newHash,
        //     'password_changed_at' => date('Y-m-d H:i:s'),
        // ]);

        if (!$ok) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => 'No se pudo guardar la nueva clave.',
                'csrf' => csrf_hash(),
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'message' => 'Clave actualizada correctamente.',
            'csrf' => csrf_hash(),
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'cambiarClave ERROR: ' . $e->getMessage());

        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'message' => 'Error interno actualizando clave.',
            'csrf' => csrf_hash(),
        ]);
    }
}

}
