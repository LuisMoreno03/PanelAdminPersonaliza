<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

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
    public function detalles($orderId)
    {
        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/{$orderId}.json";
        $resp = $this->curlShopify($url, 'GET');

        if ($resp['status'] === 0 || !empty($resp['error'])) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Error conectando con Shopify (cURL)",
                "curl_error" => $resp['error'],
            ]);
        }

        $data = json_decode($resp['body'], true) ?: [];
        if (!isset($data["order"])) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Shopify no devolvió el pedido",
                "raw"     => $data
            ]);
        }

        $order = $data["order"];

        // leer imágenes locales
        $folder = FCPATH . "uploads/pedidos/$orderId/";
        $imagenesLocales = [];

        if (is_dir($folder)) {
            foreach (scandir($folder) as $archivo) {
                if ($archivo === "." || $archivo === "..") continue;
                $parts = explode(".", $archivo);
                $index = intval($parts[0]);
                $imagenesLocales[$index] = base_url("uploads/pedidos/$orderId/$archivo");
            }
        }

        return $this->response->setJSON([
            "success"          => true,
            "order"            => $order,
            "imagenes_locales" => $imagenesLocales
        ]);
    }

    // ============================================================
    // BADGE DEL ESTADO
    // ============================================================
    private function badgeEstado(string $estado): string
    {
        $estilos = [
            "Por preparar" => "bg-yellow-100 text-yellow-800 border border-yellow-300",
            "Preparado"    => "bg-green-100 text-green-800 border border-green-300",
            "Enviado"      => "bg-blue-100 text-blue-800 border border-blue-300",
            "Entregado"    => "bg-emerald-100 text-emerald-800 border border-emerald-300",
            "Cancelado"    => "bg-red-100 text-red-800 border border-red-300",
            "Devuelto"     => "bg-purple-100 text-purple-800 border border-purple-300",
        ];

        $clase = $estilos[$estado] ?? "bg-gray-100 text-gray-800 border border-gray-300";
        $estadoEsc = htmlspecialchars($estado, ENT_QUOTES, 'UTF-8');

        return '<span class="px-3 py-1 rounded-full text-xs font-bold tracking-wide ' . $clase . '">' . $estadoEsc . '</span>';
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

            // si no existe tabla, no romper
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
            else                  $q->where('id', $orderId); // último fallback

            $row = $q->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

            return $row['estado'] ?? "Por preparar";
        } catch (\Throwable $e) {
            return "Por preparar";
        }
    }

    // ============================================================
    // GUARDAR ESTADO (robusto: histórico si se puede)
    // ============================================================
    public function guardarEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false, 'message' => 'No autenticado'])->setStatusCode(401);
        }

        $json = $this->request->getJSON(true) ?? [];
        $orderId = (string)($json["id"] ?? '');
        $estado  = (string)($json["estado"] ?? '');

        if ($orderId === '' || $estado === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Faltan parámetros'])->setStatusCode(422);
        }

        $db = \Config\Database::connect();

        // si no existe tabla, no romper
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

            // si hay columnas “histórico”, insertamos
            if ($hasEstado && ($hasOrderId || $hasPedidoId) && ($hasUserId || $hasCreated)) {
                $insert = [];
                if ($hasOrderId)  $insert['order_id'] = $orderId;
                if ($hasPedidoId) $insert['pedido_id'] = $orderId; // por si tu esquema usa esa

                $insert['estado'] = $estado;

                if ($hasUserId)  $insert['user_id'] = (int)(session()->get('user_id') ?? 0);
                if ($hasCreated) $insert['created_at'] = date('Y-m-d H:i:s');

                $db->table('pedidos_estado')->insert($insert);
            } else {
                // fallback simple (tu replace original)
                $db->table("pedidos_estado")->replace([
                    "id" => $orderId,
                    "estado" => $estado
                ]);
            }

            return $this->response->setJSON(["success" => true]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(["success" => false, "message" => $e->getMessage()])->setStatusCode(500);
        }
    }

    // ============================================================
    // GUARDAR ETIQUETAS (Shopify PUT)
    // ============================================================
    public function guardarEtiquetas()
    {
        $json = $this->request->getJSON(true) ?? [];
        $orderId = (string)($json["id"] ?? '');
        $tags    = (string)($json["tags"] ?? '');

        if ($orderId === '') {
            return $this->response->setJSON(["success" => false, "message" => "Falta id"])->setStatusCode(422);
        }

        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/{$orderId}.json";

        $data = [
            "order" => [
                "id"   => $orderId,
                "tags" => $tags
            ]
        ];

        $resp = $this->curlShopify($url, 'PUT', $data);

        $decoded = json_decode($resp['body'], true) ?: [];

        if (!isset($decoded["order"])) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Error actualizando etiquetas",
                "status"  => $resp['status'],
                "raw"     => $resp['body']
            ]);
        }

        return $this->response->setJSON([
            "success" => true,
            "message" => "OK"
        ]);
    }

    // ============================================================
    // LISTAR TODOS LOS PEDIDOS (con paginación REAL Link header)
    // ============================================================
    public function filter()
    {
        $allOrders = [];
        $limit = 250;

        $pageInfo = null;
        $loops = 0;

        do {
            $loops++;
            if ($loops > 30) break; // safety

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
            $estadoInterno = $this->obtenerEstadoInterno($o["id"]);
            $badge         = $this->badgeEstado($estadoInterno);

            $resultado[] = [
                "id"           => $o["id"],
                "numero"       => $o["name"] ?? ("#" . ($o["order_number"] ?? $o["id"])),
                "fecha"        => isset($o["created_at"]) ? substr((string)$o["created_at"], 0, 10) : "-",
                "cliente"      => $o["customer"]["first_name"] ?? "Desconocido",
                "total"        => ($o["total_price"] ?? "-") . " €",
                "estado"       => $badge,
                "estado_raw"   => $estadoInterno,
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
        $orderId = $this->request->getPost("orderId");
        $index   = $this->request->getPost("index");
        $file    = $this->request->getFile("file");

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Archivo inválido"
            ]);
        }

        $folder = FCPATH . "uploads/pedidos/$orderId/";
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $ext = $file->getExtension();
        $newName = $index . "." . $ext;

        $file->move($folder, $newName, true);

        $url = base_url("uploads/pedidos/$orderId/$newName");

        return $this->response->setJSON([
            "success" => true,
            "url"     => $url
        ]);
    }
}
