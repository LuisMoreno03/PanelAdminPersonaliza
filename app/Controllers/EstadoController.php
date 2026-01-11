<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class EstadoController extends BaseController
{
    private string $pedidosTable = 'pedidos';
    private string $estadoTable  = 'pedidos_estado';
    private string $usersTable   = 'users';

    // ✅ Estados "por hacer" permitidos (DASHBOARD)
    private array $allowedEstados = [
        'Por preparar',
        'Faltan archivos',
        'Confirmado',
        'Diseñado',
        'Por producir',
        'Enviado',
    ];

    // ✅ Normaliza estados (viejos / tildes / mayúsculas)
    // Basado en tu normalizeEstado() de dashboard.js, pero extendido para compatibilidad
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

            // ✅ tolerancia extra (tildes / variantes)
            'por produccion'    => 'Por producir',
            'por producción'    => 'Por producir',

            // ✅ viejos -> nuevos (compat)
            'preparado'         => 'Confirmado',      // antes lo mandabas a Fabricando; ahora en "por hacer"
            'fabricando'        => 'Por producir',    // si viene de otro flujo, lo aterrizamos
            'produccion'        => 'Por producir',
            'producción'        => 'Por producir',
            'a medias'          => 'Faltan archivos', // opcional: ajústalo si quieres otro mapping
            'amedias'           => 'Faltan archivos',

            // otros históricos
            'entregado'         => 'Enviado',
            'cancelado'         => 'Por preparar',
            'devuelto'          => 'Por preparar',
        ];

        if (isset($map[$lower])) return $map[$lower];

        // Si ya viene un valor válido con distinta capitalización
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
            $orderId  = isset($payload['id']) ? trim((string)$payload['id']) : '';
            $estadoIn = isset($payload['estado']) ? trim((string)$payload['estado']) : '';

            if ($orderId === '' || $estadoIn === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Faltan parámetros: id / estado',
                ]);
            }

            // ✅ normalizar + validar
            $estado = $this->normalizeEstado($estadoIn);

            if (!in_array($estado, $this->allowedEstados, true)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Estado inválido',
                    'allowed' => $this->allowedEstados,
                    'received' => $estadoIn,
                    'normalized' => $estado,
                ]);
            }

            $db = db_connect();
            $dbName = $db->getDatabase();

            $userId   = session()->get('user_id') ?? null;
            $userName = session()->get('nombre') ?? session()->get('username') ?? 'Sistema';
            $now      = date('Y-m-d H:i:s');

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
                    ->limit(1)
                    ->get()
                    ->getRowArray();
                // no bloqueamos si no existe
            }

            // ---------------------------------------------------------
            // 1) Validar esquema de pedidos_estado (estado ACTUAL)
            // ---------------------------------------------------------
            $hasId          = $db->fieldExists('id', $this->estadoTable);
            $hasEstado      = $db->fieldExists('estado', $this->estadoTable);
            $hasActualizado = $db->fieldExists('actualizado', $this->estadoTable);
            $hasCreatedAt   = $db->fieldExists('created_at', $this->estadoTable);
            $hasUserIdCol   = $db->fieldExists('user_id', $this->estadoTable);
            $hasUserNameCol = $db->fieldExists('user_name', $this->estadoTable);

            if (!$hasId || !$hasEstado) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'La tabla pedidos_estado debe tener columnas id y estado',
                ]);
            }

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
            // 3) Insert en HISTORIAL (si existe)
            // ---------------------------------------------------------
            if (!empty($histExists)) {
                $hist = [
                    'order_id'   => (int)$orderId,
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
            // 4) Guardar estado ACTUAL (UPSERT por id)
            // ---------------------------------------------------------
            $data = [
                'id'     => (string)$orderId,
                'estado' => (string)$estado,
            ];

            if ($hasActualizado) $data['actualizado'] = $now;

            if ($hasCreatedAt) {
                $existsRow = $db->table($this->estadoTable)
                    ->select('id')
                    ->where('id', $orderId)
                    ->limit(1)
                    ->get()
                    ->getRowArray();

                if (!$existsRow) $data['created_at'] = $now;
            }

            if ($hasUserIdCol)   $data['user_id'] = ($userId !== null) ? (int)$userId : null;
            if ($hasUserNameCol) $data['user_name'] = (string)$userName;

            $db->table($this->estadoTable)->replace($data);

            // ---------------------------------------------------------
            // 5) Actualiza last_change_user/at en pedidos (si existen)
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
                    'estado' => $estado, // ✅ normalizado al estilo nuevo
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
