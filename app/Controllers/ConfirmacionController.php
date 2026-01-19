<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ConfirmacionController extends Controller
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }
        return view('confirmacion');
    }

    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON(['success' => false]);
        }

        $limit = (int)($this->request->getGet('limit') ?? 10);
        if ($limit <= 0) $limit = 10;

        $db = \Config\Database::connect();

        $orders = $db->table('pedidos_estado pe')
            ->select("
                pe.order_id AS id,
                pe.estado,
                p.numero,
                DATE(p.created_at) AS fecha,
                p.cliente,
                CONCAT(p.total, ' €') AS total
            ")
            ->join('pedidos p', 'p.shopify_order_id = pe.order_id')
            ->where('pe.estado', 'Por preparar')
            ->where('p.estado_envio IS NULL', null, false)
            // 🔥 Express primero
            ->orderBy("p.etiquetas LIKE '%Express%'", 'DESC', false)
            ->orderBy('p.created_at', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders
        ]);
    }

    public function pull()
    {
        // Placeholder: luego conectas Shopify
        return $this->response->setJSON(['success' => true]);
    }
}
