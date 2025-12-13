<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DashboardController extends Controller
{
    // ==============================
    // VISTA PRINCIPAL
    // ==============================
    public function index()
    {
        return view('dashboard');
    }

    // ==============================
    // AJAX → CARGA DESDE BD
    // ==============================
    public function filter()
    {
        $page = (int) ($this->request->getGet('page') ?? 1);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $db = \Config\Database::connect();

        $orders = $db->table('pedidos')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders,
            'total'   => $db->table('pedidos')->countAll()
        ]);
    }
}
