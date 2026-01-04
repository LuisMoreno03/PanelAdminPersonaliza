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

        if (!empty($pedidosExists)) {
            $pedido = $db->table($this->pedidosTable)
                ->select('id')
                ->where('id', $orderId)
                ->get()
                ->getRowArray();

            // NO bloqueamos si no existe (tu pedidos puede no estar sincronizada)
            if (!$pedido) {
                // log_message('warning', "Pedido {$orderId} no existe en tabla pedidos (se guardará estado igualmente).");
            }
        }

        // ---------------------------------------------------------
        // 1) Detectar columnas reales en pedidos_estado (tu esquema)
        //    En tu BD: id, estado, actualizado, created_at, user_id
        // ---------------------------------------------------------
        $hasId          = $db->fieldExists('id', $this->estadoTable);
        $hasEstado      = $db->fieldExists('estado', $this->estadoTable);
        $hasActualizado = $db->fieldExists('actualizado', $this->estadoTable);
        $hasCreatedAt   = $db->fieldExists('created_at', $this->estadoTable);
        $hasUserId      = $db->fieldExists('user_id', $this->estadoTable);
        $hasUserName    = $db->fieldExists('user_name', $this->estadoTable); // por si algún día existe

        // Si tu tabla NO tiene id+estado no podemos hacer nada
        if (!$hasId || !$hasEstado) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'La tabla pedidos_estado debe tener columnas id y estado',
            ]);
        }

        $userId   = session()->get('user_id') ?? null;
        $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
        $now      = date('Y-m-d H:i:s');

        $db->transStart();

        // ---------------------------------------------------------
        // 2) Guardar estado ACTUAL (UPSERT por id)
        //    ✅ REPLACE funciona si "id" es PK/UNIQUE (lo normal en tu tabla)
        // ---------------------------------------------------------
        $data = [
            'id'     => (string)$orderId,
            'estado' => (string)$estado,
        ];

        // tu tabla usa actualizado como "fecha de último cambio"
        if ($hasActualizado) $data['actualizado'] = $now;

        // si tienes created_at, opcional: solo setearlo si la fila NO existía
        // (si quieres siempre actualizarlo, comenta el if y asigna directo)
        if ($hasCreatedAt) {
            $existsRow = $db->table($this->estadoTable)
                ->select('id')
                ->where('id', $orderId)
                ->limit(1)
                ->get()
                ->getRowArray();

            if (!$existsRow) $data['created_at'] = $now;
        }

        if ($hasUserId)   $data['user_id'] = ($userId !== null) ? (int)$userId : null;
        if ($hasUserName) $data['user_name'] = (string)$userName;

        // ✅ REPLACE (insert si no existe, update si existe)
        $db->table($this->estadoTable)->replace($data);

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
