<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class EstadoController extends BaseController
{
    private string $pedidosTable = 'pedidos';
    private string $estadoTable  = 'pedidos_estado';
    private string $usersTable   = 'users';

    // ✅ Estados permitidos
    private array $allowedEstados = [
        'Por preparar',
        'Faltan archivos',
        'Confirmado',
        'Diseñado',
        'Por producir',
        'Enviado',
        'Repetir',
        'Cancelado', // ✅ NUEVO
    ];

    // ✅ Normaliza estados (viejos / tildes / mayúsculas)
    private function normalizeEstado(?string $estado): string
    {
        $s = trim((string)($estado ?? ''));
        if ($s === '') return 'Por preparar';

        $lower = mb_strtolower($s);

        $map = [
            // ✅ nuevos (dashboard)
            'por preparar'      => 'Por preparar',
            'faltan archivos'   => 'Faltan archivos',
            'faltan_archivos'   => 'Faltan archivos',
            'confirmado'        => 'Confirmado',
            'diseñado'          => 'Diseñado',
            'disenado'          => 'Diseñado',
            'por producir'      => 'Por producir',
            'enviado'           => 'Enviado',
            'repetir'           => 'Repetir',
            'cancelado'         => 'Cancelado', // ✅ AHORA SÍ

            // ✅ tolerancia extra
            'por produccion'    => 'Por producir',
            'por producción'    => 'Por producir',

            // ✅ viejos -> nuevos (compat)
            'preparado'         => 'Confirmado',
            'fabricando'        => 'Por producir',
            'produccion'        => 'Por producir',
            'producción'        => 'Por producir',
            'a medias'          => 'Faltan archivos',
            'amedias'           => 'Faltan archivos',

            // otros históricos
            'entregado'         => 'Enviado',
            'devuelto'          => 'Por preparar',
        ];

        if (isset($map[$lower])) return $map[$lower];

        foreach ($this->allowedEstados as $ok) {
            if (mb_strtolower($ok) === $lower) return $ok;
        }

        return 'Por preparar';
    }

    public function guardar(): ResponseInterface
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON([
                    'success' => false,
                    'message' => 'No autenticado',
                ]);
            }

            $payload  = $this->request->getJSON(true) ?? [];

            // ✅ aceptar id u order_id
            $orderId  = trim((string)($payload['order_id'] ?? $payload['id'] ?? ''));
            $estadoIn = trim((string)($payload['estado'] ?? ''));

            // ✅ compat: mantener_asignado (si llega)
            $mantenerAsignado = (bool)($payload['mantener_asignado'] ?? false);

            if ($orderId === '' || $estadoIn === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Faltan parámetros: id/order_id / estado',
                ]);
            }

            $estado = $this->normalizeEstado($estadoIn);

            if (!in_array($estado, $this->allowedEstados, true)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success'    => false,
                    'message'    => 'Estado inválido',
                    'allowed'    => $this->allowedEstados,
                    'received'   => $estadoIn,
                    'normalized' => $estado,
                ]);
            }

            $db = db_connect();
            $dbName = $db->getDatabase();

            $userId   = session()->get('user_id') ?? null;
            $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
            $now      = date('Y-m-d H:i:s');

            // ---------------------------------------------------------
            // 0) Detectar si existe tabla pedidos
            // ---------------------------------------------------------
            $pedidosExists = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?
                 LIMIT 1",
                [$dbName, $this->pedidosTable]
            )->getRowArray();

            // Campos posibles en pedidos
            $hasShopifyIdInPedidos = !empty($pedidosExists) && $db->fieldExists('shopify_order_id', $this->pedidosTable);
            $hasAssignedUser       = !empty($pedidosExists) && $db->fieldExists('assigned_to_user_id', $this->pedidosTable);
            $hasAssignedAt         = !empty($pedidosExists) && $db->fieldExists('assigned_at', $this->pedidosTable);

            // ---------------------------------------------------------
            // 1) Validar esquema de pedidos_estado
            // ---------------------------------------------------------
            $hasEstado      = $db->fieldExists('estado', $this->estadoTable);
            $hasActualizado = $db->fieldExists('actualizado', $this->estadoTable);
            $hasCreatedAt   = $db->fieldExists('created_at', $this->estadoTable);
            $hasUserIdCol   = $db->fieldExists('user_id', $this->estadoTable);
            $hasUserNameCol = $db->fieldExists('user_name', $this->estadoTable);

            // ✅ compat: dos esquemas posibles
            $hasOrderIdCol  = $db->fieldExists('order_id', $this->estadoTable);
            $hasIdCol       = $db->fieldExists('id', $this->estadoTable);

            if (!$hasEstado || (!$hasOrderIdCol && !$hasIdCol)) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'La tabla pedidos_estado debe tener estado y (order_id o id)',
                ]);
            }

            $keyCol = $hasOrderIdCol ? 'order_id' : 'id';

            // ---------------------------------------------------------
            // 2) Detectar tabla historial (si existe)
            // ---------------------------------------------------------
            $histTable  = 'pedidos_estado_historial';
            $histExists = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?
                 LIMIT 1",
                [$dbName, $histTable]
            )->getRowArray();

            $db->transStart();

            // ---------------------------------------------------------
            // 3) Resolver pedido interno (id) si existe tabla pedidos
            //    (busca por id o por shopify_order_id)
            // ---------------------------------------------------------
            $pedidoRow = null;
            if (!empty($pedidosExists)) {
                $q = $db->table($this->pedidosTable)->select('id');
                $q->groupStart()
                    ->where('id', $orderId);
                if ($hasShopifyIdInPedidos) {
                    $q->orWhere('shopify_order_id', $orderId);
                }
                $q->groupEnd();
                $pedidoRow = $q->limit(1)->get()->getRowArray();
            }

            // ---------------------------------------------------------
            // 4) Insert en HISTORIAL (si existe)
            // ---------------------------------------------------------
            if (!empty($histExists)) {
                $hist = [
                    // ✅ guardar como string (no castear a int)
                    'order_id'   => (string)$orderId,
                    'estado'     => (string)$estado,
                    'user_id'    => ($userId !== null) ? (int)$userId : null,
                    'user_name'  => (string)$userName,
                    'created_at' => $now,
                ];

                foreach (array_keys($hist) as $col) {
                    if (!$db->fieldExists($col, $histTable)) {
                        unset($hist[$col]);
                    }
                }

                $db->table($histTable)->insert($hist);
            }

            // ---------------------------------------------------------
            // 5) UPSERT estado actual (por order_id o id)
            // ---------------------------------------------------------
            $existsRow = $db->table($this->estadoTable)
                ->select($keyCol)
                ->where($keyCol, $orderId)
                ->limit(1)
                ->get()
                ->getRowArray();

            $data = [
                $keyCol  => (string)$orderId,
                'estado' => (string)$estado,
            ];

            if ($hasActualizado) $data['actualizado'] = $now;

            if ($hasCreatedAt && !$existsRow) {
                $data['created_at'] = $now;
            }

            if ($hasUserIdCol)   $data['user_id'] = ($userId !== null) ? (int)$userId : null;
            if ($hasUserNameCol) $data['user_name'] = (string)$userName;

            if ($existsRow) {
                $db->table($this->estadoTable)->where($keyCol, $orderId)->update($data);
            } else {
                $db->table($this->estadoTable)->insert($data);
            }

            // ---------------------------------------------------------
            // 6) Actualiza last_change_user/at en pedidos (si existen)
            // ---------------------------------------------------------
            if (!empty($pedidosExists) && $pedidoRow) {
                $update = [];

                if ($db->fieldExists('last_change_user', $this->pedidosTable)) $update['last_change_user'] = $userName;
                if ($db->fieldExists('last_change_at', $this->pedidosTable))   $update['last_change_at']   = $now;

                if (!empty($update)) {
                    $db->table($this->pedidosTable)->where('id', (int)$pedidoRow['id'])->update($update);
                }

                // ✅ Si Confirmado o Cancelado => desasignar (para que salga de la cola)
                $estadoLower = mb_strtolower(trim($estado));
                $debeDesasignar = ($estadoLower === 'confirmado' || $estadoLower === 'cancelado');

                if ($debeDesasignar && !$mantenerAsignado) {
                    $unassign = [];
                    if ($hasAssignedUser) $unassign['assigned_to_user_id'] = null;
                    if ($hasAssignedAt)   $unassign['assigned_at'] = null;

                    if (!empty($unassign)) {
                        $db->table($this->pedidosTable)->where('id', (int)$pedidoRow['id'])->update($unassign);
                    }
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
                        'user_name'  => $userName,
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

            // ✅ compat: si existe order_id, usarlo; si no, id/pedido_id
            $hasOrderId  = $db->fieldExists('order_id', $this->estadoTable);
            $hasPedidoId = $db->fieldExists('pedido_id', $this->estadoTable);
            $hasId       = $db->fieldExists('id', $this->estadoTable);

            $q = $db->table($this->estadoTable);

            if ($hasOrderId)      $q->where('order_id', (string)$orderId);
            elseif ($hasPedidoId) $q->where('pedido_id', $orderId);
            elseif ($hasId)       $q->where('id', (string)$orderId);
            else                  $q->where('id', $orderId);

            $rows = $q->orderBy('id', 'DESC')
                ->limit(200)
                ->get()
                ->getResultArray();

            foreach ($rows as &$r) {
                if (isset($r['estado'])) {
                    $r['estado'] = $this->normalizeEstado((string)$r['estado']);
                }

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
                'success'  => true,
                'order_id' => $orderId,
                'history'  => $rows,
            ]);

        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
