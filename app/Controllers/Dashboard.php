<?php

namespace App\Controllers;

use Config\Database;

class Dashboard extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard'); // (ojo: parece typo, quizá era /dashboard/login)
        }

        return view('dashboard');
    }

    /**
     * Endpoint que consume dashboard.js:
     * GET /dashboard/filter?page_info=XXXX
     *
     * Devuelve:
     * - success
     * - orders (ya formateados para la tabla)
     * - next_page_info
     * - count
     */
    public function filter()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ])->setStatusCode(401);
        }

        $pageInfo = $this->request->getGet('page_info');

        // ====== 1) Pedir a Shopify (vía tu ShopifyController) ======
        // Puedes llamar al controller directamente (rápido y práctico en tu caso)
        $shopify = new \App\Controllers\ShopifyController();

        // 50 por página para la vista
        $_GET['limit'] = 50;
        if ($pageInfo) $_GET['page_info'] = $pageInfo;

        $shopifyResp = $shopify->getOrders();
        $payload = json_decode($shopifyResp->getBody(), true);

        if (!$payload || empty($payload['success'])) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $payload['error'] ?? 'Error consultando Shopify',
                'debug'   => $payload ?? null,
            ])->setStatusCode(500);
        }

        $ordersRaw = $payload['data']['orders'] ?? [];
        $nextPageInfo = $payload['next_page_info'] ?? null;

        // ====== 2) Mapear a la estructura que tu tabla usa (p.numero, p.fecha, etc.) ======
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

            $total = isset($o['total_price']) ? ($o['total_price'] . ' €') : '-';

            // Tu estado viene de tu sistema (BD) o de Shopify?
            // Por defecto uso financial_status/fulfillment_status como fallback:
            $estado = $o['tags'] ?? '—'; // si en tu sistema lo devuelves de otra forma, ajusta aquí
            // IMPORTANTE: si ya tienes estado propio en tu BD, lo ideal es traerlo desde la tabla pedidos.

            // Artículos
            $articulos = isset($o['line_items']) ? count($o['line_items']) : 0;

            // Estado/forma entrega (ajústalo a tu lógica real)
            $estado_envio = $o['fulfillment_status'] ?? '-';
            $forma_envio  = $o['shipping_lines'][0]['title'] ?? '-';

            $orders[] = [
                'id'           => $orderId,
                'numero'       => $numero,
                'fecha'        => $fecha,
                'cliente'      => $cliente,
                'total'        => $total,
                'estado'       => ($o['tags'] ? 'Producción' : 'Por preparar'), // ejemplo
                'renderLastChange'=> $id,
                'etiquetas'    => $o['tags'] ?? '',
                'articulos'    => $articulos,
                'estado_envio' => $estado_envio ?: '-',
                'forma_envio'  => $forma_envio ?: '-',
                // last_status_change se agregará abajo
            ];
        }

        // ====== 3) AQUÍ VA EL BLOQUE: traer último cambio desde BD ======
        $db = Database::connect();

        foreach ($orders as &$o) {
            $orderId = $o['id'] ?? null;

            if (!$orderId) {
                $o['last_status_change'] = null;
                continue;
            }

            $row = $db->table('pedidos_estado pe')
                ->select('pe.created_at as changed_at, u.nombre as user_name')
                ->join('users u', 'u.id = pe.user_id', 'left')
                ->where('pe.pedido_id', $orderId)
                ->orderBy('pe.created_at', 'DESC')
                ->get()
                ->getRowArray();

            $o['last_status_change'] = [
                'user_name'  => $row['user_name'] ?? '—',
                'changed_at' => $row['changed_at'] ?? null,
            ];
        }
        unset($o);

        // ====== 4) Responder al frontend ======
        return $this->response->setJSON([
            'success'        => true,
            'orders'         => $orders,
            'next_page_info' => $nextPageInfo,
            'count'          => count($orders),
        ]);
    }
}
