<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Usuario extends BaseController
{
    // GET /usuarios (VISTA)
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/login');
        }

        return view('usuarios/index');
    }

    // GET /api/usuarios (JSON)
    public function apiIndex(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $db = \Config\Database::connect();

        $users = $db->table('users')
            ->select('id, nombre, role, email, created_at')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'users' => $users,
            'count' => count($users),
        ]);
    }

    // (tu crear() y tags() se quedan igual)
}
