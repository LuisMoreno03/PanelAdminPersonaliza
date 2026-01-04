<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Models\OrderStatusHistoryModel;

class EstadoController extends BaseController
{
    // AJUSTA aquí tu tabla real:
    private string $ordersTable = 'orders'; // <-- cambia si tu tabla se llama distinto

    public function guardar(): ResponseInterface
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON([
                    'success' => false,
                    'message' => 'No autenticado',
                ]);
            }

            $payload = $this->request->getJSON(true) ?? [];
            $orderId = isset($payload['id']) ? trim((string)$payload['id']) : '';
            $nuevoEstado = isset($payload['estado']) ? trim((string)$payload['estado']) : '';

            if ($orderId === '' || $nuevoEstado === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Faltan parámetros: id / estado',
                ]);
            }

            $userId   = session()->get('user_id') ?? null;
            $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
            $now = date('Y-m-d H:i:s');

            $db = db_connect();

            // 1) Pedido existe?
            $row = $db->table($this->ordersTable)
                ->select('id, estado')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado en BD local',
                ]);
            }

            $prevEstado = $row['estado'] ?? null;

            $db->transStart();

            // 2) Update estado (sin asumir columnas que no existen)
            $update = [
                'estado' => $nuevoEstado,
            ];

            // Si tienes estas columnas, las llenamos (si no, no rompe)
            if ($db->fieldExists('updated_at', $this->ordersTable)) {
                $update['updated_at'] = $now;
            }
            if ($db->fieldExists('last_change_user', $this->ordersTable)) {
                $update['last_change_user'] = $userName;
            }
            if ($db->fieldExists('last_change_at', $this->ordersTable)) {
                $update['last_change_at'] = $now;
            }

            $db->table($this->ordersTable)
                ->where('id', $orderId)
                ->update($update);

            // 3) Historial (registro de todos los cambios y usuarios)
            $history = new OrderStatusHistoryModel();
            $history->insert([
                'order_id'     => (int)$orderId,
                'prev_estado'  => (string)($prevEstado ?? ''),
                'nuevo_estado' => (string)$nuevoEstado,
                'user_id'      => $userId !== null ? (int)$userId : null,
                'user_name'    => (string)$userName,
                'ip'           => (string)$this->request->getIPAddress(),
                'user_agent'   => substr((string)$this->request->getUserAgent(), 0, 255),
                'created_at'   => $now,
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'Transacción fallida (update/insert)',
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
            // TEMPORAL: para ver el error real en Network → Response
            log_message('error', 'EstadoController::guardar ERROR: {msg} {file}:{line}', [
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'ERROR: ' . $e->getMessage(),
                'where' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
        }
    }

    public function historial(int $orderId): ResponseInterface
    {
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
