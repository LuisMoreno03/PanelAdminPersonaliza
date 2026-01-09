<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\PedidoImagenModel;

class DashboardController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    // ✅ Estados nuevos permitidos
    private array $allowedEstados = [
        'Por preparar',
        'A medias',
        'Produccion',
        'Fabricando',
        'Enviado',
    ];

    public function __construct()
    {
        $this->loadShopifyFromConfig();

        if (!$this->shop || !$this->token) {
            $this->loadShopifySecretsFromFile();
        }

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
    // CONFIG LOADERS (igual que Dashboard.php)
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
            'produccion'   => 'Produccion',
            'produccion '  => 'Produccion',

            'fabricando'   => 'Fabricando',
            'preparado'    => 'Fabricando',

            'enviado'      => 'Enviado',
            'entregado'    => 'Enviado',

            // antiguos que ya no existen -> default
            'cancelado'    => 'Por preparar',
            'devuelto'     => 'Por preparar',
        ];

        if (isset($map[$lower])) return $map[$lower];

        // si ya viene alguno válido con otra capitalización
        foreach ($this->allowedEstados as $ok) {
            if (mb_strtolower($ok) === $lower) return $ok;
        }

        return 'Por preparar';
    }

    // ============================================================
    // ETIQUETAS SEGÚN ROL Y USUARIO (tu lógica, sin romper)
    // ============================================================
    private function getEtiquetasUsuario(): array
    {
        $db = \Config\Database::connect();
        $session = session();

        $userId = $session->get('user_id');
        if (!$userId) return ["General"];

        $usuario = $db->table('users')->where('id', $userId)->get()->getRow();
        if (!$usuario) return ["General"];

        $nombre = ucfirst((string) $usuario->nombre);
        $rol = strtolower((string) $usuario->role);

        if ($rol === "confirmacion") return ["D.$nombre"];
        if ($rol === "produccion") return ["D.$nombre", "P.$nombre"];

        if ($rol === "admin") {
            $usuarios = $db->table('users')->get()->getResult();
            $etiquetas = [];

            foreach ($usuarios as $u) {
                $nombreU = ucfirst((string) $u->nombre);
                $rolU = strtolower((string) $u->role);

                if ($rolU === "confirmacion") {
                    $etiquetas[] = "D.$nombreU";
                } elseif ($rolU === "produccion") {
                    $etiquetas[] = "D.$nombreU";
                    $etiquetas[] = "P.$nombreU";
                }
            }

            return array_values(array_unique($etiquetas));
        }

        return ["General"];
    }

    // ============================================================
    // VISTA PRINCIPAL
    // ============================================================
    public function index()
    {
        return view('dashboard', [
            "etiquetasPredeterminadas" => $this->getEtiquetasUsuario(),
        ]);
    }

    // ============================================================
    // DETALLES DEL PEDIDO + IMÁGENES LOCALES
    // ============================================================
    // ✅ Aquí: procesa imágenes y define estado
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

        // 1) Pedido
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

        // 2) product_images (por product_id)
        $lineItems = $order['line_items'] ?? [];
        $productIds = [];

        foreach ($lineItems as $li) {
            if (!empty($li['product_id'])) $productIds[(string)$li['product_id']] = true;
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
            if (!empty($p['image']['src'])) $img = $p['image']['src'];
            elseif (!empty($p['images'][0]['src'])) $img = $p['images'][0]['src'];

            if ($img) $productImages[(string)$pid] = $img;
        }

        // 3) imágenes locales desde BD (PedidoImagenModel)
        $modelImg = new PedidoImagenModel();
        // Espero que getByOrder te devuelva un array indexado por line_index => url
        $imagenesLocales = $modelImg->getByOrder((int)$orderId);
        if (!is_array($imagenesLocales)) $imagenesLocales = [];

        return $this->response->setJSON([
            'success'         => true,
            'order'           => $order,
            'product_images'  => $productImages,
            'imagenes_locales'=> $imagenesLocales,
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'DETALLES ERROR: '.$e->getMessage());
        return $this->response->setJSON([
            'success' => false,
            'message' => 'Error interno cargando detalles',
        ])->setStatusCode(200);
    }
}

    // ============================================================
    // ✅ BADGE DEL ESTADO (colores nuevos)
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
    // PING / USUARIOS ESTADO (no romper si falta last_seen)
    // ============================================================
    public function ping()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false])->setStatusCode(401);
        }

        $userId = session()->get('user_id');
        if (!$userId) {
            return $this->response->setJSON(['success' => false])->setStatusCode(401);
        }

        $db = \Config\Database::connect();
        if ($this->columnExists($db, 'users', 'last_seen')) {
            $db->table('users')->where('id', $userId)->update([
                'last_seen' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->response->setJSON(['success' => true]);
    }

    public function usuariosEstado()
    {
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
            ]);
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
        ]);
    }

    // ============================================================
    // ESTADO INTERNO (arreglado: id vs order_id/pedido_id)
    // ============================================================
    private function obtenerEstadoInterno($orderId): string
    {
        try {
            $db = \Config\Database::connect();

            $dbName = $db->getDatabase();
            $tbl = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?
                 LIMIT 1",
                [$dbName, 'pedidos_estado']
            )->getRowArray();

            if (empty($tbl)) return "Por preparar";

            $hasOrderId  = $this->columnExists($db, 'pedidos_estado', 'order_id');
            $hasPedidoId = $this->columnExists($db, 'pedidos_estado', 'pedido_id');
            $hasEstado   = $this->columnExists($db, 'pedidos_estado', 'estado');

            if (!$hasEstado) return "Por preparar";

            $q = $db->table('pedidos_estado')->select('estado');

            if ($hasOrderId)      $q->where('order_id', $orderId);
            elseif ($hasPedidoId) $q->where('pedido_id', $orderId);
            else                  $q->where('id', $orderId);

            $row = $q->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

            return $this->normalizeEstado($row['estado'] ?? "Por preparar");
        } catch (\Throwable $e) {
            return "Por preparar";
        }
    }

    // ============================================================
    // GUARDAR ESTADO (valida 5 estados nuevos)
    // ============================================================
    public function guardarEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false, 'message' => 'No autenticado'])->setStatusCode(401);
        }

        $json = $this->request->getJSON(true) ?? [];
        $orderId = (string)($json["id"] ?? '');
        $estado  = (string)($json["estado"] ?? '');

        $orderId = trim($orderId);
        $estado  = $this->normalizeEstado($estado);

        if ($orderId === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Falta id'])->setStatusCode(422);
        }

        // ✅ valida contra lista final
        if (!in_array($estado, $this->allowedEstados, true)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Estado inválido'])->setStatusCode(422);
        }

        $db = \Config\Database::connect();

        try {
            $dbName = $db->getDatabase();
            $tbl = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?
                 LIMIT 1",
                [$dbName, 'pedidos_estado']
            )->getRowArray();

            if (empty($tbl)) {
                return $this->response->setJSON(['success' => false, 'message' => 'No existe tabla pedidos_estado'])->setStatusCode(500);
            }

            $hasOrderId  = $this->columnExists($db, 'pedidos_estado', 'order_id');
            $hasPedidoId = $this->columnExists($db, 'pedidos_estado', 'pedido_id');

            $hasEstado   = $this->columnExists($db, 'pedidos_estado', 'estado');
            $hasUserId   = $this->columnExists($db, 'pedidos_estado', 'user_id');
            $hasCreated  = $this->columnExists($db, 'pedidos_estado', 'created_at');

            if ($hasEstado && ($hasOrderId || $hasPedidoId) && ($hasUserId || $hasCreated)) {
                $insert = [];

                if ($hasOrderId)  $insert['order_id'] = $orderId;
                if ($hasPedidoId) $insert['pedido_id'] = $orderId;

                $insert['estado'] = $estado;

                if ($hasUserId)  $insert['user_id'] = (int)(session()->get('user_id') ?? 0);
                if ($hasCreated) $insert['created_at'] = date('Y-m-d H:i:s');

                $db->table('pedidos_estado')->insert($insert);
            } else {
                $db->table("pedidos_estado")->replace([
                    "id" => $orderId,
                    "estado" => $estado
                ]);
            }

            return $this->response->setJSON(["success" => true, "estado" => $estado]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(["success" => false, "message" => $e->getMessage()])->setStatusCode(500);
        }
    }

    // ============================================================
    // GUARDAR ETIQUETAS (Shopify PUT) - lo dejo igual
    // ============================================================

    public function guardarEtiquetas()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ]);
        }

        $payload = $this->request->getJSON(true) ?? [];
        $orderId = (string)($payload['id'] ?? '');
        $tagsRaw = (string)($payload['etiquetas'] ?? '');

        if ($orderId === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Falta id del pedido'
            ]);
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
        $tags = array_slice($tags, 0, 6);

        $shop  = trim((string) env('SHOPIFY_STORE_DOMAIN'));
        $token = trim((string) env('SHOPIFY_ADMIN_TOKEN'));
        $apiVersion = '2024-01';

        if (!$shop || !$token) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Falta SHOPIFY_STORE_DOMAIN o SHOPIFY_ADMIN_TOKEN'
            ])->setStatusCode(200);
        }

        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = preg_replace('#/.*$#', '', $shop);

        $url = "https://{$shop}/admin/api/{$apiVersion}/orders/{$orderId}.json";

        $client = \Config\Services::curlrequest([
            'timeout' => 25,
            'http_errors' => false,
        ]);

        $resp = $client->put($url, [
            'headers' => [
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode([
                'order' => [
                    'id' => (int)$orderId,
                    'tags' => implode(', ', $tags),
                ]
            ]),
        ]);

        $status = $resp->getStatusCode();
        $raw = (string)$resp->getBody();

        if ($status >= 400) {
            log_message('error', 'SHOPIFY update tags error '.$status.': '.$raw);
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error guardando etiquetas en Shopify',
                'status' => $status
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Etiquetas guardadas',
            'tags' => $tags
        ]);
    }

    // ============================================================
    // LISTAR TODOS LOS PEDIDOS (con paginación REAL Link header)
    // ✅ ARREGLADO: devuelve estado TEXTO + badge opcional
    // ============================================================
    public function filter()
    {
        $allOrders = [];
        $limit = 250;

        $pageInfo = null;
        $loops = 0;

        do {
            $loops++;
            if ($loops > 30) break;

            if ($pageInfo) {
                $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
            } else {
                $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders.json?limit={$limit}&status=any&order=created_at%20desc";
            }

            $resp = $this->curlShopify($url, 'GET');
            if ($resp['status'] >= 400 || $resp['status'] === 0) break;

            $decoded = json_decode($resp['body'], true) ?: [];
            if (!isset($decoded["orders"])) break;

            $allOrders = array_merge($allOrders, $decoded["orders"]);

            $linkHeader = $resp['headers']['link'] ?? null;
            if (is_array($linkHeader)) $linkHeader = end($linkHeader);

            [$nextPageInfo, $prev] = $this->parseLinkHeaderForPageInfo(is_string($linkHeader) ? $linkHeader : null);
            $pageInfo = $nextPageInfo;

        } while ($pageInfo);

        $resultado = [];

        foreach ($allOrders as $o) {
            $estadoInterno = $this->obtenerEstadoInterno($o["id"]); // ✅ texto normalizado
            $badge         = $this->badgeEstado($estadoInterno);

            $resultado[] = [
                "id"           => $o["id"],
                "numero"       => $o["name"] ?? ("#" . ($o["order_number"] ?? $o["id"])),
                "fecha"        => isset($o["created_at"]) ? substr((string)$o["created_at"], 0, 10) : "-",
                "cliente"      => trim(($o["customer"]["first_name"] ?? "Desconocido") . ' ' . ($o["customer"]["last_name"] ?? "")),
                "total"        => ($o["total_price"] ?? "-") . " €",

                // ✅ IMPORTANTE: estado como TEXTO para tu dashboard.js
                "estado"       => $estadoInterno,
                "estado_raw"   => $estadoInterno,

                // (opcional) si quieres un badge HTML para otros usos
                "estado_badge" => $badge,

                "etiquetas"    => $o["tags"] ?? "-",
                "articulos"    => count($o["line_items"] ?? []),
                "estado_envio" => $o["fulfillment_status"] ?? "-",
                "forma_envio"  => $o["shipping_lines"][0]["title"] ?? "-"
            ];
        }

        return $this->response->setJSON([
            "success" => true,
            "orders"  => $resultado,
            "count"   => count($resultado)
        ]);
    }

    // ============================================================
    // SUBIR IMAGEN LOCAL DEL PRODUCTO
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

    // guardar/actualizar DB
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
        // no rompas el flujo si DB falla
        log_message('error', 'pedido_imagenes replace ERROR: '.$e->getMessage());
    }

    return $this->response->setJSON([
        "success" => true,
        "url"     => $url
    ]);
}

    private function extractImageUrlsFromLineItem(array $item): array
    {
        $urls = [];

        $props = $item['properties'] ?? [];
        if (is_array($props)) {
            foreach ($props as $p) {
                $val = (string)($p['value'] ?? '');
                if ($val === '') continue;

                // URL normal
                if (preg_match('#^https?://#i', $val) && preg_match('#\.(png|jpe?g|webp|gif|svg)(\?.*)?$#i', $val)) {
                    $urls[] = $val;
                }

                // base64
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
            // 1) obtener bytes (url o base64)
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

            // 2) guardo temporal
            $tmp = WRITEPATH . 'cache/img_' . uniqid() . '.bin';
            file_put_contents($tmp, $bytes);

            // 3) proceso (resize + webp)
            $image = \Config\Services::image();
            $image->withFile($tmp)
                ->resize(1200, 1200, true, 'width') // mantiene proporción
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

        // carpeta destino pública
        $baseDir = FCPATH . 'uploads/pedidos/' . $orderId . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

        // para devolver al frontend por índice
        $modelImg = new PedidoImagenModel();
        $imagenesLocales = $modelImg->getByOrder((int)$orderId);


        foreach ($lineItems as $idx => &$item) {
            $idx = (int)$idx;

            $urls = $this->extractImageUrlsFromLineItem($item);
            if (!$urls) {
                // esta línea NO requiere imagen
                continue;
            }

            $totalRequeridas++;

            $original = $urls[0]; // primera imagen que detecte

            // lookup en DB
            $row = $db->table('pedido_imagenes')
                ->where('order_id', $orderId)
                ->where('line_index', $idx)
                ->get()->getRowArray();

            $localUrl = $row['local_url'] ?? null;
            $status   = $row['status'] ?? 'missing';

            // si ya está lista
            if ($localUrl && $status === 'ready') {
                $totalListas++;
                $imagenesLocales[$idx] = $localUrl;
                $item['local_image_url'] = $localUrl;
                continue;
            }

            // marcar processing
            $db->table('pedido_imagenes')->replace([
                'order_id'     => $orderId,
                'line_index'   => $idx,
                'original_url' => $original,
                'local_url'    => $localUrl,
                'status'       => 'processing',
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

            // generar archivo
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

        // ✅ decidir estado automáticamente
        // - si hay requeridas y todas listas => Producción
        // - si hay requeridas y falta alguna => A medias
        // - si no hay requeridas => no tocar estado (o pon Por preparar, tú decides)
        $estadoAuto = null;

        if ($totalRequeridas > 0) {
            $estadoAuto = ($totalListas >= $totalRequeridas) ? 'Produccion' : 'A medias';
        }

        // guardar en el order para frontend
        $order['imagenes_locales'] = $imagenesLocales;
        $order['auto_estado'] = $estadoAuto;
        $order['auto_images_required'] = $totalRequeridas;
        $order['auto_images_ready'] = $totalListas;

        // Persistir estado en BD (tu tabla pedidos_estado)
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
