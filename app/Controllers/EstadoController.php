<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class EstadoController extends BaseController
{
    private string $pedidosTable = 'pedidos';
    private string $estadoTable  = 'pedidos_estado';

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
            $pedidoId    = trim((string)($payload['id'] ?? ''));
            $nuevoEstado = trim((string)($payload['estado'] ?? ''));

            if ($pedidoId === '' || $nuevoEstado === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Faltan parámetros: id / estado',
                ]);
            }

            $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
            $now = date('Y-m-d H:i:s');

            $db = db_connect();

            // 1️⃣ Verificar que el pedido existe
            $pedido = $db->table($this->pedidosTable)
                ->select('id')
                ->where('id', $pedidoId)
                ->get()
                ->getRow();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ]);
            }

            $db->transStart();

            // 2️⃣ Guardar estado (insertamos SIEMPRE el nuevo estado)
            $db->table($this->estadoTable)->insert([
                'pedido_id' => $pedidoId,
                'estado'    => $nuevoEstado,
                'user_name' => $userName,
                'created_at'=> $now,
            ]);

            // 3️⃣ Actualizar metadata en pedidos
            $db->table($this->pedidosTable)
                ->where('id', $pedidoId)
                ->update([
                    'last_change_user' => $userName,
                    'last_change_at'   => $now,
                ]);

            $db->transComplete();

            if (!$db->transStatus()) {
                throw new \RuntimeException('Transacción fallida');
            }

            return $this->response->setJSON([
                'success' => true,
                'order' => [
                    'id' => $pedidoId,
                    'estado' => $nuevoEstado,
                    'last_status_change' => [
                        'user_name' => $userName,
                        'changed_at' => $now,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'EstadoController ERROR: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
