<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\PedidoEstadoModel;

class DashboardController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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";

    // ============================================================
    // GENERAR ETIQUETAS SEGÚN ROL Y USUARIO
    // ============================================================
    private function getEtiquetasUsuario()
    {
        $db = \Config\Database::connect();
        $session = session();

        $userId = $session->get('user_id');

        if (!$userId) {
            return ["Sin usuario"];
        }

        $usuario = $db->table('users')->where('id', $userId)->get()->getRow();
        if (!$usuario) {
            return ["Sin usuario"];
        }

        $nombre = ucfirst($usuario->nombre);
        $rol = strtolower($usuario->role);

        if ($rol === "confirmacion") {
            return ["D.$nombre"];
        }

        if ($rol === "produccion") {
            return [
                "D.$nombre",
                "P.$nombre"
            ];
        }

        if ($rol === "admin") {
            $usuarios = $db->table('users')->get()->getResult();
            $etiquetas = [];

            foreach ($usuarios as $u) {
                $nombreU = ucfirst($u->nombre);
                $rolU = strtolower($u->role);

                if ($rolU === "confirmacion") {
                    $etiquetas[] = "D.$nombreU";
                }
                if ($rolU === "produccion") {
                    $etiquetas[] = "D.$nombreU";
                    $etiquetas[] = "P.$nombreU";
                }
            }

            return $etiquetas;
        }

        return ["General"];
    }


    // ============================================================
    // DETALLES DEL PEDIDO + IMÁGENES LOCALES
    // ============================================================
    public function detalles($orderId)
    {
        $url = "https://{$this->shop}/admin/api/2024-01/orders/{$orderId}.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "X-Shopify-Access-Token: {$this->token}"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "No se recibió respuesta de Shopify"
            ]);
        }

        $data = json_decode($response, true);

        if (!isset($data["order"])) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Shopify no devolvió el pedido",
                "raw"     => $data
            ]);
        }

        $order = $data["order"];

        // ====================================================
        // LEER IMÁGENES GUARDADAS LOCALMENTE
        // ====================================================
        $folder = FCPATH . "uploads/pedidos/$orderId/";
        $imagenesLocales = [];

        if (is_dir($folder)) {
            foreach (scandir($folder) as $archivo) {

                if ($archivo === "." || $archivo === "..") continue;

                // archivo -> ejemplo: 0.jpg
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
// PING (marca al usuario como activo)
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
    $db->table('users')->where('id', $userId)->update([
        'last_seen' => date('Y-m-d H:i:s'),
    ]);

    return $this->response->setJSON(['success' => true]);
}

// ============================================================
// LISTA USUARIOS + ONLINE/OFFLINE
// ============================================================
public function usuariosEstado()
{
    if (!session()->get('logged_in')) {
        return $this->response->setJSON(['success' => false])->setStatusCode(401);
    }

    $db = \Config\Database::connect();

    $usuarios = $db->table('users')
        ->select('id, nombre, role, last_seen')
        ->orderBy('nombre', 'ASC')
        ->get()
        ->getResultArray();

    $now = time();
    $onlineThreshold = 120; // 2 minutos

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
    ]);
}


    // ============================================================
    // ESTADO INTERNO
    // ============================================================
    private function obtenerEstadoInterno($orderId)
    {
        $db = \Config\Database::connect();

        $row = $db->table("pedidos_estado")
                 ->where("id", $orderId)
                 ->get()
                 ->getRow();

        return $row->estado ?? "Por preparar";
    }


    // ============================================================
    // CONSULTAR SHOPIFY
    // ============================================================
    private function queryShopify($params = "")
    {
        $url = "https://{$this->shop}/admin/api/2024-01/orders.json?$params";

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return ["success" => false, "message" => curl_error($ch)];
        }

        curl_close($ch);
        return json_decode($response, true);
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
    // GUARDAR ESTADO
    // ============================================================
    public function guardarEstado()
    {
        $json = $this->request->getJSON(true);
        $id = $json["id"];
        $estado = $json["estado"];

        $db = \Config\Database::connect();

        $db->table("pedidos_estado")->replace([
            "id" => $id,
            "estado" => $estado
        ]);

        return $this->response->setJSON(["success" => true]);
    }


    // ============================================================
    // GUARDAR ETIQUETAS
    // ============================================================
    public function guardarEtiquetas()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"];
        $tags    = $json["tags"];

        $url = "https://{$this->shop}/admin/api/2024-01/orders/{$orderId}.json";

        $data = [
            "order" => [
                "id"   => $orderId,
                "tags" => $tags
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => "PUT",
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if (!isset($decoded["order"])) {
            return $this->response->setJSON([
                "success" => false,
                "message" => "Error actualizando etiquetas",
                "raw"     => $response
            ]);
        }

        return $this->response->setJSON([
            "success" => true,
            "message" => "OK"
        ]);
    }


    // ============================================================
    // LISTAR TODOS LOS PEDIDOS
    // ============================================================
    public function filter()
{
    if (!session()->get('logged_in')) {
        return $this->response->setJSON([
            'success' => false,
            'message' => 'No autenticado'
        ])->setStatusCode(401);
    }

    $limit    = (int) ($this->request->getGet('limit') ?? 50);
    if ($limit <= 0 || $limit > 250) $limit = 50;

    $pageInfo = trim((string) ($this->request->getGet('page_info') ?? ''));

    // ✅ Llamada REAL a Shopify (sin usar $_GET)
    $shopify = new \App\Controllers\ShopifyController();
    $result  = $shopify->fetchOrdersPage($limit, $pageInfo ?: null);

    // 🔥 Debug si viene vacío (te ayuda a detectar permisos/token)
    if (empty($result['success'])) {
        return $this->response->setJSON([
            'success' => false,
            'message' => $result['error'] ?? 'Error Shopify',
            'debug'   => $result,
        ])->setStatusCode(500);
    }

    // ✅ OJO: aquí es "orders", NO data.orders
    $ordersRaw    = $result['orders'] ?? [];
    $nextPageInfo = $result['next_page_info'] ?? null;

    // Si Shopify devuelve 200 pero vacío, devolvemos debug también
    if (empty($ordersRaw)) {
        return $this->response->setJSON([
            'success'        => true,
            'orders'         => [],
            'next_page_info' => $nextPageInfo,
            'count'          => 0,
            'debug'          => [
                'shopify_url' => $result['url'] ?? null,
                'hint'        => 'Si /shopify/getOrders también devuelve vacío, es token/scope o tienda sin pedidos.'
            ]
        ]);
    }

    // Map simple (igual que tu formato)
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

        $total     = isset($o['total_price']) ? ($o['total_price'] . ' €') : '-';
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

    return $this->response->setJSON([
        'success'        => true,
        'orders'         => $orders,
        'next_page_info' => $nextPageInfo,
        'count'          => count($orders),
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

        // Crear carpeta del pedido
        $folder = FCPATH . "uploads/pedidos/$orderId/";
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        // Extensión real del archivo
        $ext = $file->getExtension();

        // Nombre fijo por índice del producto
        $newName = $index . "." . $ext;

        // Guardar / sobreescribir
        $file->move($folder, $newName, true);

        $url = base_url("uploads/pedidos/$orderId/$newName");

        return $this->response->setJSON([
            "success" => true,
            "url"     => $url
        ]);
    }
}

