<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class EstadoController extends BaseController
{
    private string $pedidosTable = 'pedidos';
    private string $estadoTable  = 'pedidos_estado';
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
            $dbName = $db->getDatabase();

            // ---------------------------------------------------------
            // 0) Detectar si existe tabla pedidos (soft-check)
            // ---------------------------------------------------------
            $pedidosExists = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?
                 LIMIT 1",
                [$dbName, $this->pedidosTable]
            )->getRowArray();

            // Si existe la tabla, intentamos comprobar existencia del pedido.
            // Si no existe tabla o no hay fila, NO bloqueamos.
            if (!empty($pedidosExists)) {
                $pedido = $db->table($this->pedidosTable)
                    ->select('id')
                    ->where('id', $orderId)
                    ->get()
                    ->getRowArray();

                // Si tu tabla pedidos NO se sincroniza con Shopify, esto puede fallar.
                // En vez de romper, lo dejamos pasar.
                if (!$pedido) {
                    // puedes loguearlo si quieres:
                    // log_message('warning', "Pedido {$orderId} no existe en tabla pedidos (se guardará estado igualmente).");
                }
            }

            // ---------------------------------------------------------
            // 1) Detectar FK real en pedidos_estado (order_id / pedido_id / id)
            // ---------------------------------------------------------
            $hasOrderId  = $db->fieldExists('order_id', $this->estadoTable);
            $hasPedidoId = $db->fieldExists('pedido_id', $this->estadoTable);
            $hasId       = $db->fieldExists('id', $this->estadoTable);

            // columnas opcionales
            $hasEstado    = $db->fieldExists('estado', $this->estadoTable);
            $hasUserId    = $db->fieldExists('user_id', $this->estadoTable);
            $hasUserName  = $db->fieldExists('user_name', $this->estadoTable);
            $hasCreatedAt = $db->fieldExists('created_at', $this->estadoTable);

            if (!$hasEstado) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'La tabla pedidos_estado no tiene columna estado',
                ]);
            }

            $userId   = session()->get('user_id') ?? null;
            $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
            $now      = date('Y-m-d H:i:s');

            $db->transStart();

            // ---------------------------------------------------------
            // 2) Insert histórico (si hay FK). Si no hay FK, fallback.
            // ---------------------------------------------------------
            $insert = [];

            // FK
            if ($hasOrderId)      $insert['order_id']  = (string)$orderId;
            elseif ($hasPedidoId) $insert['pedido_id'] = (string)$orderId;
            elseif ($hasId)       $insert['id']        = (string)$orderId; // último recurso

            $insert['estado'] = (string)$estado;

            if ($hasUserId)    $insert['user_id'] = ($userId !== null) ? (int)$userId : null;
            if ($hasUserName)  $insert['user_name'] = (string)$userName;
            if ($hasCreatedAt) $insert['created_at'] = $now;

            $db->table($this->estadoTable)->insert($insert);

            // ---------------------------------------------------------
            // 3) Actualiza last_change_user/at en pedidos (si existen)
            // ---------------------------------------------------------
            if (!empty($pedidosExists)) {
                $update = [];
                if ($db->fieldExists('last_change_user', $this->pedidosTable)) $update['last_change_user'] = $userName;
                if ($db->fieldExists('last_change_at', $this->pedidosTable))   $update['last_change_at']   = $now;

                if (!empty($update)) {
                    $db->table($this->pedidosTable)->where('id', $orderId)->update($update);
                }
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

            // detectar FK para filtrar el historial correctamente
            $hasOrderId  = $db->fieldExists('order_id', $this->estadoTable);
            $hasPedidoId = $db->fieldExists('pedido_id', $this->estadoTable);

            $q = $db->table($this->estadoTable);

            if ($hasOrderId)      $q->where('order_id', $orderId);
            elseif ($hasPedidoId) $q->where('pedido_id', $orderId);
            else                  $q->where('id', $orderId);

            $rows = $q->orderBy('id', 'DESC')
                ->limit(200)
                ->get()
                ->getResultArray();

            // opcional: agregar nombre de usuario desde users
            foreach ($rows as &$r) {
                if (!empty($r['user_id'])) {
                    $u = $db->table($this->usersTable)
                        ->select('nombre')
                        ->where('id', (int)$r['user_id'])
                        ->get()
                        ->getRowArray();

                    $r['user_name'] = $r['user_name'] ?? ($u['nombre'] ?? null);
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
