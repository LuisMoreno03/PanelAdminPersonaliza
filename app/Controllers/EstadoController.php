<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Models\OrderStatusHistoryModel;

class EstadoController extends BaseController
{
    public function guardar(): ResponseInterface
    {
        // Si usas AuthFilter, ya estás protegido.
        // Aun así, validamos sesión.
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $payload = $this->request->getJSON(true) ?? [];
        $orderId = isset($payload['id']) ? (string)$payload['id'] : '';
        $nuevoEstado = isset($payload['estado']) ? trim((string)$payload['estado']) : '';

        if ($orderId === '' || $nuevoEstado === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan parámetros: id / estado',
            ]);
        }

        // Datos de usuario (desde sesión)
        $userId = session()->get('user_id');      // ajusta si tu sesión se llama distinto
        $userName = session()->get('nombre') ?? 'Sistema';

        $now = date('Y-m-d H:i:s');

        $db = db_connect();

        // 🔒 Transacción: actualiza + inserta historial
        $db->transStart();

        // 1) Obtener estado previo (ajusta nombre de tu tabla/campos)
        // Si tu tabla se llama "orders_local" o similar, cambia esto:
        $row = $db->table('orders')
            ->select('id, estado')
            ->where('id', $orderId)
            ->get()
            ->getRowArray();

        if (!$row) {
            $db->transComplete();
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Pedido no encontrado en BD local',
            ]);
        }

        $prevEstado = $row['estado'] ?? null;

        // 2) Actualizar estado actual en la tabla principal (TU TABLA)
        $db->table('orders')
            ->where('id', $orderId)
            ->update([
                'estado' => $nuevoEstado,
                'updated_at' => $now, // si existe
                // si tienes campos para último cambio:
                'last_change_user' => $userName,
                'last_change_at' => $now,
            ]);

        // 3) Insertar historial
        $history = new OrderStatusHistoryModel();

        $history->insert([
            'order_id' => (int)$orderId,
            'prev_estado' => $prevEstado,
            'nuevo_estado' => $nuevoEstado,
            'user_id' => $userId ? (int)$userId : null,
            'user_name' => (string)$userName,
            'ip' => $this->request->getIPAddress(),
            'user_agent' => substr((string)$this->request->getUserAgent(), 0, 255),
            'created_at' => $now,
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'No se pudo guardar el cambio',
            ]);
        }

        // Devuelve lo necesario para que tu dashboard.js sincronice
        return $this->response->setJSON([
            'success' => true,
            'order' => [
                'id' => $orderId,
                'estado' => $nuevoEstado,
                'last_status_change' => [
                    'user_name' => $userName,
                    'changed_at' => $now,
                ],
            ],
        ]);
    }
    public function historial(int $orderId): ResponseInterface
{
    $history = new \App\Models\OrderStatusHistoryModel();

    $rows = $history->where('order_id', $orderId)
        ->orderBy('id', 'DESC')
        ->findAll(200);

    return $this->response->setJSON([
        'success' => true,
        'order_id' => $orderId,
        'history' => $rows,
    ]);
}

}
