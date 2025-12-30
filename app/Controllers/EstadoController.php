<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use Config\Database;

class EstadoController extends ResourceController
{
    /**
     * POST /api/estado/guardar
     * Body JSON: { "id": 7173..., "estado": "Preparado" }
     */
    public function guardar()
    {
        if (!session()->get('logged_in')) {
            return $this->respond([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }

        $data = $this->request->getJSON(true);

        $pedidoId = $data['id'] ?? null;
        $estado   = $data['estado'] ?? null;

        if (!$pedidoId || !$estado) {
            return $this->respond([
                'success' => false,
                'message' => 'Faltan campos: id o estado'
            ], 400);
        }

        $db = Database::connect();

        // ✅ Insertar historial en pedidos_estado (id = ID Shopify)
        $now = date('Y-m-d H:i:s');
        $userId = session()->get('user_id') ?? null;
        $userName = session()->get('nombre') ?? (session()->get('user_name') ?? 'Usuario');

        $db->table('pedidos_estado')->insert([
            'id'         => $pedidoId,    // 👈 tu tabla usa "id" como ID del pedido
            'estado'     => $estado,
            'user_id'    => $userId,
            'created_at' => $now,
        ]);

        // ✅ Responder con el último cambio para que el frontend lo pinte al instante
        return $this->respond([
            'success' => true,
            'last_status_change' => [
                'user_name'  => $userName,
                'changed_at' => $now,
            ]
        ]);
    }
}
