<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;

class EstadoController extends Controller
{
    public function guardar()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ])->setStatusCode(401);
        }

        $json = $this->request->getJSON(true);

        $pedidoId = $json['id'] ?? null;        // <- ID real Shopify (numérico grande)
        $estado   = $json['estado'] ?? null;

        if (!$pedidoId || !$estado) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Faltan campos (id, estado)'
            ])->setStatusCode(400);
        }

        $db = Database::connect();

        // ✅ tu id de usuario (ajusta el nombre si tu sesión usa otro)
        $userId = session()->get('user_id'); 
        if (!$userId) {
            // fallback: si en tu sesión guardas el usuario como array
            $user = session()->get('user');
            $userId = $user['id'] ?? null;
        }

        // Insertar historial
        $db->table('pedidos_estado')->insert([
            'pedido_id'  => $pedidoId,
            'estado'     => $estado,
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // traer el último cambio recién insertado
        $row = $db->table('pedidos_estado pe')
            ->select('pe.created_at as changed_at, u.nombre as user_name')
            ->join('users u', 'u.id = pe.user_id', 'left')
            ->where('pe.pedido_id', $pedidoId)
            ->orderBy('pe.created_at', 'DESC')
            ->get()
            ->getRowArray();

        return $this->response->setJSON([
            'success' => true,
            'last_status_change' => [
                'user_name'  => $row['user_name'] ?? '—',
                'changed_at' => $row['changed_at'] ?? null,
            ]
        ]);
    }
}
