<?php

namespace App\Controllers;

use Config\Database;

class EstadoController extends BaseController
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

        $pedidoId = $json['id'] ?? null;
        $estado   = $json['estado'] ?? null;

        if (!$pedidoId || !$estado) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Datos incompletos'
            ])->setStatusCode(400);
        }

        $db = Database::connect();

        $db->table('pedidos_estado')->insert([
            'id'         => $pedidoId, // 🔥 ID REAL Shopify
            'estado'     => $estado,
            'user_id'    => session()->get('user_id'), // 🔥 USUARIO LOGUEADO
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'success' => true
        ]);
    }
}
