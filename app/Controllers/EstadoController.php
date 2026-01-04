<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Models\OrderStatusHistoryModel;

class EstadoController extends BaseController
{
    // ✅ Cambia aquí si tu tabla no se llama "orders"
    private string $ordersTable = 'orders';

    public function guardar(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $payload = $this->request->getJSON(true) ?? [];
        $orderId = isset($payload['id']) ? trim((string) $payload['id']) : '';
        $nuevoEstado = isset($payload['estado']) ? trim((string) $payload['estado']) : '';

        if ($orderId === '' || $nuevoEstado === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan parámetros: id / estado',
            ]);
        }

        // Datos usuario desde sesión (ajusta nombres si tu sesión usa otras keys)
        $userId   = session()->get('user_id'); // puede ser null
        $userName = session()->get('nombre') ?? session()->get('user_name') ?? 'Sistema';

        $now = date('Y-m-d H:i:s');

        $db = db_connect();

        try {
            $db->transStart();

            // 1) Obtener estado previo
            $row = $db->table($this->ordersTable)
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

            // 2) Armar update solo con columnas que existan (evita 500)
            $updateData = [
                'estado' => $nuevoEstado,
            ];

            if ($db->fieldExists('updated_at', $this->ordersTable)) {
                $updateData['updated_at'] = $now;
            }
            if ($db->fieldExists('last_change_user', $this->ordersTable)) {
                $updateData['last_change_user'] = $userName;
            }
            if ($db->fieldExists('last_change_at', $this->ordersTable)) {
                $updateData['last_change_at'] = $now;
            }

            $db->table($this->ordersTable)
                ->where('id', $orderId)
                ->update($updateData);

            // 3) Insertar historial (NO castear order_id a int)
            $history = new OrderStatusHistoryModel();

            $history->insert([
                'order_id'     => $orderId, // ✅ string
                'prev_estado'  => $prevEstado,
                'nuevo_estado' => $nuevoEstado,
                'user_id'      => $userId !== null ? (int) $userId : null,
                'user_name'    => (string) $userName,
                'ip'           => $this->request->getIPAddress(),
                'user_agent'   => substr((string) $this->request->getUserAgent()->getAgentString(), 0, 255),
                'created_at'   => $now,
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se pudo guardar el cambio (transacción falló)',
                ]);
            }

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

        } catch (\Throwable $e) {
            // ✅ así ves el error real en logs
            log_message('error', 'EstadoController::guardar ERROR: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error interno al guardar',
            ]);
        }
    }

    public function historial($orderId = null): ResponseInterface
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Falta orderId',
            ]);
        }

        $history = new OrderStatusHistoryModel();

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
