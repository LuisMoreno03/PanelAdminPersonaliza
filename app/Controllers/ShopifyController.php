<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ShopifyController extends Controller
{
    // ✅ RECOMENDADO: mueve esto a .env (abajo te dejo ejemplo)
   private $shop;
    private $token;

    public function __construct()
    {
        $this->shop  = rtrim(env('SHOPIFY_SHOP'), '/');
    $this->token = env('SHOPIFY_TOKEN');
    }

    // ============================================================
    // 💡 MÉTODO GENERAL PARA TODAS LAS LLAMADAS A SHOPIFY (CON HEADERS)
    // ============================================================
    private function request($method, $endpoint, $data = null)
    {
        $endpoint = ltrim($endpoint, '/');

        $url = "https://{$this->shop}/admin/api/2024-01/{$endpoint}";

        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$this->token}"
        ];

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // ✅ Capturar headers + body
        curl_setopt($curl, CURLOPT_HEADER, true);

        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response     = curl_exec($curl);
        $error        = curl_error($curl);
        $status_code  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $header_size  = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        curl_close($curl);

        if ($response === false) {
            return [
                "success" => false,
                "status"  => $status_code ?: 0,
                "error"   => $error ?: "Unknown cURL error",
                "headers" => "",
                "data"    => null
            ];
        }

        $raw_headers = substr($response, 0, $header_size);
        $body        = substr($response, $header_size);

        return [
            "success" => $error ? false : true,
            "status"  => $status_code,
            "error"   => $error,
            "headers" => $raw_headers,
            "data"    => json_decode($body, true)
        ];
    }

    // ============================================================
    // 🔗 Extrae page_info del header Link (rel="next")
    // ============================================================
    private function getNextPageInfoFromHeaders(string $rawHeaders): ?string
    {
        if (!preg_match('/^Link:\s*(.+)$/mi', $rawHeaders, $m)) {
            return null;
        }

        $linkLine = $m[1];

        if (preg_match('/<([^>]+)>;\s*rel="next"/', $linkLine, $n)) {
            $nextUrl = $n[1];

            $parts = parse_url($nextUrl);
            if (!isset($parts['query'])) return null;

            parse_str($parts['query'], $qs);

            return $qs['page_info'] ?? null;
        }

        return null;
    }

    // ============================================================
    // 🔍 GET: Obtener pedidos paginados (1 página)
    // /shopify/getOrders?limit=250&page_info=xxxx
    // ============================================================
    public function getOrders()
    {
        $limit     = (int) ($this->request->getGet("limit") ?? 250);
        $page_info = $this->request->getGet("page_info");

        if ($limit <= 0 || $limit > 250) $limit = 250;

        $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

        if ($page_info) {
            $endpoint .= "&page_info=" . urlencode($page_info);
        }

        $response = $this->request("GET", $endpoint);

        // devolver también next_page_info para que el front lo use
        $nextPageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

        return $this->response->setJSON([
            "success"        => $response["success"],
            "status"         => $response["status"],
            "error"          => $response["error"],
            "next_page_info" => $nextPageInfo,
            "data"           => $response["data"]
        ]);
    }

    // ============================================================
    // ✅ GET: Obtener TODOS los pedidos (sin límite de 250)
    // /shopify/getAllOrders
    // ============================================================
    public function getAllOrders()
    {
        $limit = 250;
        $pageInfo = null;

        $allOrders = [];
        $loops = 0;

        do {
            $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

            if ($pageInfo) {
                $endpoint .= "&page_info=" . urlencode($pageInfo);
            }

            $response = $this->request("GET", $endpoint);

            if (!$response["success"] || $response["status"] >= 400) {
                return $this->response->setJSON([
                    "success" => false,
                    "status"  => $response["status"],
                    "error"   => $response["error"],
                    "data"    => $response["data"]
                ]);
            }

            $orders = $response["data"]["orders"] ?? [];
            $allOrders = array_merge($allOrders, $orders);

            $pageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

            $loops++;

            // freno de seguridad
            if ($loops > 2000) break;

            // micro pausa por rate limit
            usleep(150000);

        } while ($pageInfo);

        return $this->response->setJSON([
            "success" => true,
            "total"   => count($allOrders),
            "orders"  => $allOrders
        ]);
    }

    // ============================================================
    // 🛒 GET: Obtener un pedido por ID
    // ============================================================
    public function getOrder($id)
    {
        $response = $this->request("GET", "orders/{$id}.json");
        return $this->response->setJSON($response);
    }

    // ============================================================
    // ✏️ PUT: Actualizar etiquetas de un pedido
    // ============================================================
    public function updateOrderTags()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"] ?? null;
        $tags    = $json["tags"] ?? null;

        if (!$orderId) {
            return $this->response->setJSON([
                "success" => false,
                "error" => "Falta el campo id"
            ])->setStatusCode(400);
        }

        $payload = [
            "order" => [
                "id"   => $orderId,
                "tags" => $tags
            ]
        ];

        $response = $this->request("PUT", "orders/{$orderId}.json", $payload);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 📝 PUT: Cambiar cualquier propiedad del pedido
    // ============================================================
    public function updateOrder()
    {
        $json = $this->request->getJSON(true);

        $orderId = $json["id"] ?? null;

        if (!$orderId) {
            return $this->response->setJSON([
                "success" => false,
                "error" => "Falta el campo id"
            ])->setStatusCode(400);
        }

        $orderData = [
            "order" => $json
        ];

        $response = $this->request("PUT", "orders/{$orderId}.json", $orderData);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🛍️ GET: Productos
    // ============================================================
    public function getProducts()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 250);
        if ($limit <= 0 || $limit > 250) $limit = 250;

        $endpoint = "products.json?limit={$limit}";
        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🔎 GET: Producto por ID
    // ============================================================
    public function getProduct($id)
    {
        $response = $this->request("GET", "products/{$id}.json");
        return $this->response->setJSON($response);
    }

    // ============================================================
    // 💰 GET: Clientes
    // ============================================================
    public function getCustomers()
    {
        $limit = (int) ($this->request->getGet("limit") ?? 250);
        if ($limit <= 0 || $limit > 250) $limit = 250;

        $endpoint = "customers.json?limit={$limit}";
        $response = $this->request("GET", $endpoint);

        return $this->response->setJSON($response);
    }

    // ============================================================
    // 🔧 TEST: Ver si Shopify responde correctamente
    // ============================================================
    public function test()
    {
        return $this->response->setJSON([
            "message" => "Shopify API funcionando correctamente.",
            "shop" => $this->shop
        ]);
    }   

    public function ordersView()
{
    $limit     = 50;
    $page_info = $this->request->getGet('page_info');

    $endpoint = "orders.json?limit={$limit}&status=any&order=created_at%20desc";

    if ($page_info) {
        $endpoint .= "&page_info=" . urlencode($page_info);
    }

    $response = $this->request("GET", $endpoint);

    if (!$response["success"] || $response["status"] >= 400) {
        return $this->response->setStatusCode(500)->setBody(
            "Error Shopify: " . esc($response["error"] ?? 'Error desconocido')
        );
    }

    $orders = $response["data"]["orders"] ?? [];
    $nextPageInfo = $this->getNextPageInfoFromHeaders($response["headers"] ?? "");

    // (Opcional) guardar historial para volver atrás
    $history = session()->get('orders_page_history') ?? [];
    if (!$page_info) {
        $history = []; // si es primera página, resetea historial
    } else {
        // guarda la página actual en historial para "Anterior"
        $history[] = $page_info;
    }
    session()->set('orders_page_history', $history);

    // Para "Anterior": el anterior sería el penúltimo del historial
    $prevPageInfo = null;
    if (count($history) >= 2) {
        $prevPageInfo = $history[count($history) - 2];
    } elseif (count($history) === 1) {
        $prevPageInfo = null; // desde la página 2, el “anterior” sería la primera (sin page_info)
    }

    return view('shopify/orders_list', [
        'orders'       => $orders,
        'nextPageInfo' => $nextPageInfo,
        'prevPageInfo' => $prevPageInfo,
        'isFirstPage'  => !$page_info
    ]);
}

}
