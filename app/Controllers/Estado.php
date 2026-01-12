<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class Estado extends BaseController
{
    public function guardar()
    {
        $payload = $this->request->getJSON(true);
        $orderId = (int)($payload['id'] ?? 0);
        $estado  = trim((string)($payload['estado'] ?? ''));

        if (!$orderId || $estado === '') {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Payload inválido (id/estado).'
            ]);
        }

        // ✅ usuario (ajústalo a tu auth real)
        $userId = session('user_id') ? (int)session('user_id') : null;
        $userName = session('nombre') ? (string)session('nombre') : (session('user_name') ? (string)session('user_name') : 'Sistema');

        $m = new PedidosEstadoModel();

        $ok = $m->setEstadoPedido($orderId, $estado, $userId, $userName);

        if (!$ok) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No se pudo guardar en BD.'
            ]);
        }

        // ✅ devolver lo que el frontend espera
        return $this->response->setJSON([
            'success' => true,
            'order' => [
                'id' => (string)$orderId,
                'estado' => $estado,
                'last_status_change' => [
                    'user_name' => $userName,
                    'changed_at' => date('Y-m-d H:i:s'),
                ]
            ]
        ]);
    }
}
