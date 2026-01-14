<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Estado de donde se "tira" a Producción (en TU BD pedidos_estado)
    private string $estadoEntrada = 'Confirmado';

    // Estado que representa "está en Producción" (en TU BD pedidos_estado)
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve pedidos asignados al usuario EN estado Producción (según pedidos_estado)
     * Orden: más viejos -> más nuevos por pe.actualizado
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

        $query = $db->table('pedidos p')
            ->select('p.*, pe.estado as estado_bd, pe.actualizado as estado_actualizado')
            ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('pe.estado', $this->estadoProduccion)
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
    }

    /**
     * POST /produccion/pull
     * Body JSON: { "count": 5 } o { "count": 10 }
     *
     * - Selecciona pedidos en estadoEntrada (BD) y NO asignados
     * - Los asigna al usuario (claim atómico)
     * - Cambia su estado a Producción (BD)
     * - Orden: más viejos primero por pe.actualizado
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
            /**
             * 1) CLAIM atómico:
             * - Solo pedidos libres (NULL o 0)
             * - Solo pedidos cuyo estado en pedidos_estado sea estadoEntrada
             * - Orden por pe.actualizado ASC (viejos primero)
             */
            $sqlClaim = "
                UPDATE pedidos p
                INNER JOIN pedidos_estado pe ON pe.id = p.id
                SET p.assigned_to_user_id = ?,
                    p.assigned_at = ?
                WHERE pe.estado = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY pe.actualizado ASC
                LIMIT {$count}
            ";

            $db->query($sqlClaim, [$userId, $now, $this->estadoEntrada]);

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
             * 2) Obtener los IDs que acabamos de asignar (por assigned_at exacto)
             */
            $idsQuery = $db->table('pedidos')
                ->select('id')
                ->where('assigned_to_user_id', $userId)
                ->where('assigned_at', $now)
                ->get();

            if ($idsQuery === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $rows = $idsQuery->getResultArray();
            $ids = array_map('intval', array_column($rows, 'id'));

            if (!empty($ids)) {
                /**
                 * 3) Cambiar estado BD a Producción
                 */
                $db->table('pedidos_estado')
                    ->whereIn('id', $ids)
                    ->update([
                        'estado' => $this->estadoProduccion,
                        'actualizado' => $now,
                        'user_id' => $userId
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
     * Devuelve TODOS los pedidos asignados al usuario:
     * - Quita asignación
     * - (Opcional) regresa estado a Confirmado en BD (descomentable)
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

        $query = $db->table('pedidos')
            ->select('id')
            ->where('assigned_to_user_id', $userId)
            ->get();

        if ($query === false) {
            $dbError = $db->error();
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
            ]);
        }

        $rows = $query->getResultArray();
        $ids = array_column($rows, 'id');

        // Quitar asignación
        $db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        /**
         * Si quieres que al devolverlos regresen a "Confirmado" (BD):
         *
         * $now = date('Y-m-d H:i:s');
         * if (!empty($ids)) {
         *   $db->table('pedidos_estado')
         *     ->whereIn('id', $ids)
         *     ->update([
         *       'estado' => $this->estadoEntrada,
         *       'actualizado' => $now,
         *       'user_id' => null
         *     ]);
         * }
         */

        return $this->response->setJSON([
            'ok' => true,
            'returned' => count($ids)
        ]);
    }
}
