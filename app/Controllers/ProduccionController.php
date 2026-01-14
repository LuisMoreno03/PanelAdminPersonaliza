<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Pedidos que entran a Producción cuando haces "pull"
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando quedan asignados al usuario
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve SOLO los pedidos asignados al usuario y en estado Producción
     */
    public function myQueue()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => true,
                'data' => []
            ]);
        }

        $db = Database::connect();

        try {
            $query = $db->table('pedidos p')
                ->select('p.*, pe.estado, pe.actualizado')
                // ✅ JOIN correcto: pedidos_estado.order_id -> pedidos.id
                ->join('pedidos_estado pe', 'pe.order_id = p.id', 'inner', false)
                ->where('p.assigned_to_user_id', (int)$userId)
                ->where('TRIM(pe.estado)', $this->estadoProduccion)
                // ✅ más viejos primero por fecha de actualizado
                ->orderBy('pe.actualizado', 'ASC')
                ->get();

            if ($query === false) {
                $dbError = $db->error();
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
                ]);
            }

            return $this->response->setJSON([
                'ok' => true,
                'data' => $query->getResultArray()
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /produccion/pull
     * Body JSON: { "count": 5 } o { "count": 10 }
     *
     * 1) Toma pedidos en estado Confirmado y sin asignar
     * 2) Los asigna al usuario
     * 3) Los pasa a estado Producción en pedidos_estado
     *
     * ✅ NO se repiten (claim atómico)
     * ✅ Orden: más viejos -> más nuevos por pe.actualizado
     */
    public function pull()
    {
        $userId = session()->get('user_id');
        $payload = $this->request->getJSON(true);
        $count = (int) ($payload['count'] ?? 0);

        if (!$userId || !in_array($count, [5, 10], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'Datos inválidos'
            ]);
        }

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        try {
            // ✅ 0) Asegurar que exista fila en pedidos_estado para TODOS los pedidos (si faltan)
            //     (si ya existe, no inserta nada)
            $sqlBackfill = "
                INSERT INTO pedidos_estado (order_id, estado, actualizado, created_at, user_id)
                SELECT p.id, 'Por preparar', NOW(), NOW(), NULL
                FROM pedidos p
                LEFT JOIN pedidos_estado pe ON pe.order_id = p.id
                WHERE pe.order_id IS NULL
            ";
            $db->query($sqlBackfill);

            /**
             * ✅ 1) CLAIM atómico:
             * - asigna SOLO pedidos libres
             * - SOLO si estado = Confirmado
             * - orden por actualizado ASC (más viejos primero)
             */
            $sqlClaim = "
                UPDATE pedidos p
                INNER JOIN pedidos_estado pe ON pe.order_id = p.id
                SET p.assigned_to_user_id = ?,
                    p.assigned_at = ?
                WHERE TRIM(pe.estado) = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY pe.actualizado ASC
                LIMIT {$count}
            ";
            $db->query($sqlClaim, [(int)$userId, $now, $this->estadoEntrada]);

            $assigned = (int) ($db->affectedRows() ?? 0);

            if ($assigned <= 0) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'ids' => []
                ]);
            }

            /**
             * ✅ 2) Obtener IDs recién asignados
             */
            $idsQuery = $db->table('pedidos')
                ->select('id')
                ->where('assigned_to_user_id', (int)$userId)
                ->where('assigned_at', $now)
                ->get();

            if ($idsQuery === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $rows = $idsQuery->getResultArray();
            $ids = array_map('intval', array_column($rows, 'id'));

            if (!empty($ids)) {
                // ✅ 3) Cambiar estado en pedidos_estado (JOIN por order_id)
                $db->table('pedidos_estado')
                    ->whereIn('order_id', $ids)
                    ->update([
                        'estado' => $this->estadoProduccion,
                        'actualizado' => $now,
                        'user_id' => (int)$userId
                    ]);
            }

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids),
                'ids' => $ids
            ]);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /produccion/return-all
     * Devuelve TODOS los pedidos del usuario (quita asignación)
     */
    public function returnAll()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => true,
                'returned' => 0
            ]);
        }

        $db = Database::connect();

        try {
            $query = $db->table('pedidos')
                ->select('id')
                ->where('assigned_to_user_id', (int)$userId)
                ->get();

            if ($query === false) {
                $dbError = $db->error();
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
                ]);
            }

            $rows = $query->getResultArray();
            $ids = array_map('intval', array_column($rows, 'id'));

            $db->table('pedidos')
                ->where('assigned_to_user_id', (int)$userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null
                ]);

            return $this->response->setJSON([
                'ok' => true,
                'returned' => count($ids)
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}
