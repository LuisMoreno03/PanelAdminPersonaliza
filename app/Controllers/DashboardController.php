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
    private function obtenerPedidosShopify(): array
    {
        $url = "https://{$this->shop}/admin/api/2024-01/orders.json"
             . "?limit=50&status=any&order=created_at desc";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "X-Shopify-Access-Token: {$this->token}",
                "Content-Type: application/json"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['orders'] ?? [];
    }

    /* ============================================================
       GUARDAR PEDIDOS EN BD
    ============================================================ */
    private function guardarPedidos(array $orders): void
    {
        $db = \Config\Database::connect();
        $builder = $db->table('pedidos');

        foreach ($orders as $o) {
            $builder->replace([
                'id'          => $o['id'],
                'numero'      => $o['name'],
                'cliente'     => $o['customer']['first_name'] ?? 'Desconocido',
                'total'       => $o['total_price'],
                'estado_envio'=> $o['fulfillment_status'] ?? '-',
                'forma_envio' => $o['shipping_lines'][0]['title'] ?? '-',
                'etiquetas'   => $o['tags'] ?? '',
                'articulos'   => count($o['line_items'] ?? []),
                'created_at'  => date('Y-m-d H:i:s', strtotime($o['created_at'])),
                'synced_at'   => date('Y-m-d H:i:s')
            ]);
        }
    }

    
    /* ============================================================
       DASHBOARD AJAX (DESDE BD)
    ============================================================ */
     public function filter()
    {
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $db = \Config\Database::connect();

        $orders = $db->table('pedidos')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        $total = $db->table('pedidos')->countAll();

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders,
            'total'   => $total
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
