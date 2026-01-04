<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class EstadoController extends BaseController
{
    private string $pedidosTable = 'pedidos';
    private string $estadoTable  = 'pedidos_estado'; // donde guardamos estados
    private string $usersTable   = 'users';

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
            $estado  = isset($payload['estado']) ? trim((string)$payload['estado']) : '';

            if ($orderId === '' || $estado === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Faltan parámetros: id / estado',
                ]);
            }

            $db = db_connect();

            // Verifica que exista el pedido en la tabla local "pedidos"
            $pedido = $db->table($this->pedidosTable)
                ->select('id')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado en tabla pedidos',
                ]);
            }

            $userId   = session()->get('user_id') ?? null;
            $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
            $now      = date('Y-m-d H:i:s');

            $db->transStart();

            // 1) Guardar el estado como un registro nuevo (histórico)
            $insert = [
                'order_id'   => (string)$orderId,
                'estado'     => (string)$estado,
                'user_id'    => $userId !== null ? (int)$userId : null,
                'created_at' => $now,
            ];

            // por si la tabla no tiene algún campo, no explota
            foreach (array_keys($insert) as $col) {
                if (!$db->fieldExists($col, $this->estadoTable)) {
                    unset($insert[$col]);
                }
            }

            $db->table($this->estadoTable)->insert($insert);

            // 2) Actualiza "last_change_user/at" en pedidos (esas columnas SÍ existen)
            $update = [];
            if ($db->fieldExists('last_change_user', $this->pedidosTable)) $update['last_change_user'] = $userName;
            if ($db->fieldExists('last_change_at', $this->pedidosTable))   $update['last_change_at']   = $now;

            if (!empty($update)) {
                $db->table($this->pedidosTable)->where('id', $orderId)->update($update);
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'Transacción fallida',
                ]);
            }

            return $this->response->setJSON([
                'success' => true,
                'order' => [
                    'id' => $orderId,
                    'estado' => $estado,
                    'last_status_change' => [
                        'user_name' => $userName,
                        'changed_at' => $now,
                    ],
                ],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'EstadoController::guardar ERROR: {msg} {file}:{line}', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'ERROR: ' . $e->getMessage(),
            ]);
        }
    }

    public function historial(int $orderId): ResponseInterface
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON([
                    'success' => false,
                    'message' => 'No autenticado',
                ]);
            }

            $db = db_connect();

            $rows = $db->table($this->estadoTable)
                ->where('order_id', $orderId)
                ->orderBy('id', 'DESC')
                ->limit(200)
                ->get()
                ->getResultArray();

            // opcional: agregar nombre de usuario
            foreach ($rows as &$r) {
                if (!empty($r['user_id'])) {
                    $u = $db->table($this->usersTable)->select('nombre')->where('id', $r['user_id'])->get()->getRowArray();
                    $r['user_name'] = $u['nombre'] ?? null;
                }
            }
            unset($r);

            return $this->response->setJSON([
                'success' => true,
                'order_id' => $orderId,
                'history' => $rows,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
