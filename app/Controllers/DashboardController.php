<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    private $shop  = "962f2d.myshopify.com";
    private $token = "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"; // ⚠️ mover a .env en prod

    /* ============================================================
       BADGE VISUAL
    ============================================================ */
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

        return "<span class='px-3 py-1 rounded-full text-xs font-bold {$clase}'>{$estado}</span>";
    }

    /* ============================================================
       ESTADO INTERNO
    ============================================================ */
    private function obtenerEstadoInterno($orderId): string
    {
        $row = \Config\Database::connect()
            ->table("pedidos_estado")
            ->where("id", $orderId)
            ->get()
            ->getRow();

        return $row ? $row->estado : "Por preparar";
    }

    /* ============================================================
       OBTENER PEDIDOS SHOPIFY (50)
    ============================================================ */
     public function filter()
{
    $maxPedidos = 200;
    $limitPorLlamada = 50;

    $todos = [];
    $pageInfo = null;

    while (count($todos) < $maxPedidos) {

        $params = "limit={$limitPorLlamada}&status=any&order=created_at desc";
        if ($pageInfo) {
            $params .= "&page_info={$pageInfo}";
        }

        $url = "https://{$this->shop}/admin/api/2024-01/orders.json?{$params}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ]
        ]);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $body    = substr($response, $headerSize);

        $data = json_decode($body, true);

        if (empty($data['orders'])) {
            break;
        }

        $todos = array_merge($todos, $data['orders']);

        // extraer next_page_info
        preg_match('/<([^>]+)>; rel="next"/', $headers, $next);

        if (!empty($next[1])) {
            parse_str(parse_url($next[1], PHP_URL_QUERY), $q);
            $pageInfo = $q['page_info'] ?? null;
        } else {
            break;
        }

        // evitar rate limit
        usleep(400000);
    }

    // cortar exacto a 200
    $todos = array_slice($todos, 0, $maxPedidos);

    return $this->response->setJSON([
        'success' => true,
        'count'   => count($todos),
        'orders'  => $todos
    ]);
}

/* ============================================================
       SYNC MANUAL / AUTOMÁTICO
    ============================================================ */
    public function sync()
    {
        $orders = $this->obtenerPedidosShopify();
        $this->guardarPedidos($orders);

        return $this->response->setJSON([
            'success'   => true,
            'guardados' => count($orders)
        ]);
    }

    /* ============================================================
       VISTA
    ============================================================ */
    public function index()
    {
        return view('dashboard');
    }
}
