<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class ConfirmacionController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /* =====================================================
     * VISTA
     * ===================================================== */
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/login');
        }

        return view('confirmacion/index');
    }

    /* =====================================================
     * MI COLA (POR PREPARAR)
     * ===================================================== */
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false])->setStatusCode(401);
        }

        $limit = (int)($this->request->getGet('limit') ?? 10);
        if ($limit <= 0 || $limit > 50) $limit = 10;

        /*
         * Reglas:
         * - estado = Por preparar
         * - fulfillment = unfulfilled | null (Sin preparar)
         * - Express primero
         */

        $rows = $this->db->table('pedidos_estado pe')
            ->select("
                pe.order_id,
                pe.estado,
                pe.estado_updated_at,
                o.id,
                o.name            AS numero,
                DATE(o.created_at) AS fecha,
                o.customer_name   AS cliente,
                o.total_price     AS total,
                o.tags,
                o.fulfillment_status,
                o.shipping_title
            ")
            ->join('pedidos o', 'o.id = pe.order_id', 'left')
            ->where('LOWER(TRIM(pe.estado))', 'por preparar')
            ->groupStart()
                ->where('o.fulfillment_status IS NULL', null, false)
                ->orWhere('LOWER(o.fulfillment_status)', 'unfulfilled')
            ->groupEnd()
            // 🚀 EXPRESS PRIMERO
            ->orderBy("(LOWER(o.tags) LIKE '%express%' OR LOWER(o.shipping_title) LIKE '%express%')", 'DESC', false)
            // ⏱️ MÁS RECIENTES
            ->orderBy('COALESCE(pe.estado_updated_at, pe.actualizado)', 'DESC', false)
            ->limit($limit)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =====================================================
     * PULL DESDE SHOPIFY
     * ===================================================== */
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false])->setStatusCode(401);
        }

        /*
         * Aquí SOLO traes pedidos nuevos desde Shopify
         * que todavía NO estén en pedidos_estado
         * y los marcas como "Por preparar"
         */

        $estadoModel = new PedidosEstadoModel();
        $now = date('Y-m-d H:i:s');

        // ⚠️ ESTE ARRAY DEBE VENIR DE TU CACHE SHOPIFY
        // Aquí simulo pedidos ya sincronizados
        $orders = $this->db->table('pedidos')
            ->select('id')
            ->where('fulfillment_status IS NULL', null, false)
            ->get()
            ->getResultArray();

        $inserted = 0;

        foreach ($orders as $o) {
            $exists = $estadoModel->getEstadoPedido((string)$o['id']);
            if ($exists) continue;

            $estadoModel->setEstadoPedido(
                (string)$o['id'],
                'Por preparar',
                session('user_id'),
                session('nombre')
            );

            $inserted++;
        }

        return $this->response->setJSON([
            'success'  => true,
            'inserted'=> $inserted,
        ]);
    }
}
