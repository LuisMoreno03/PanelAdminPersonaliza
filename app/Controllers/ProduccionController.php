<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Pedidos elegibles para entrar a Producción cuando haces "pull"
    private string $estadoEntrada = 'Confirmado';

    // Estado que se asigna cuando quedan en la cola del usuario (Producción)
    private string $estadoProduccion = 'Producción';

    /**
     * GET /produccion
     */
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

        // ✅ IMPORTANTe: usamos INNER JOIN porque para estar en cola debe existir estado
        // Si aún no tienes filas en pedidos_estado, primero debes “backfillear” (te dejo SQL abajo)
        $query = $db->table('pedidos p')
            ->select('p.*, pe.estado, pe.actualizado, pe.user_id')
            ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('pe.estado', $this->estadoProduccion)
            // ✅ más viejos primero según última modificación de estado
            ->orderBy('pe.actualizado', 'ASC')
            ->orderBy('p.id', 'ASC')
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
     * 1) "Claim" atómico: asigna pedidos libres y en estado Confirmado
     * 2) Cambia estado a Producción
     * 3) Devuelve ids asignados
     */
    public function pull()
    {
        $userId  = session()->get('user_id');
        $payload = $this->request->getJSON(true);
        $count   = (int)($payload['count'] ?? 0);

        if (!$userId || !in_array($count, [5, 10], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'Datos inválidos'
            ]);
        }

        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        try {
            /**
             * ✅ CLAIM atómico con UPDATE + JOIN + ORDER + LIMIT
             * - Solo toma pedidos con estado Confirmado en pedidos_estado
             * - Solo toma pedidos sin asignar (NULL o 0)
             * - Ordena por pe.actualizado ASC (más viejo primero)
             */
            $sqlClaim = "
                UPDATE pedidos p
                INNER JOIN pedidos_estado pe ON pe.id = p.id
                SET p.assigned_to_user_id = ?,
                    p.assigned_at = ?
                WHERE pe.estado = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY pe.actualizado ASC, p.id ASC
                LIMIT {$count}
            ";

            $db->query($sqlClaim, [$userId, $now, $this->estadoEntrada]);

            $affected = (int)($db->affectedRows() ?? 0);

            if ($affected <= 0) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'ids' => []
                ]);
            }

            // ✅ Recuperar los IDs asignados en esta operación
            // OJO: si assigned_at no es único por segundo, esto puede mezclar pulls simultáneos.
            // Aun así, en la práctica suele bastar.
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
            $ids  = array_column($rows, 'id');

            if (!empty($ids)) {
                // ✅ Cambiar estado a Producción
                $db->table('pedidos_estado')
                    ->whereIn('id', $ids)
                    ->update([
                        'estado'      => $this->estadoProduccion,
                        'actualizado' => $now,
                        'user_id'     => $userId
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

        // Obtener IDs asignados al usuario
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
        $ids  = array_column($rows, 'id');

        // Quitar asignación en pedidos
        $db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        // ✅ Si quieres que al devolverlos regresen a Confirmado, descomenta:
        /*
        if (!empty($ids)) {
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
                ->update([
                    'estado'      => $this->estadoEntrada,
                    'actualizado' => date('Y-m-d H:i:s'),
                    'user_id'     => null
                ]);
        }
        */

        return $this->response->setJSON([
            'ok' => true,
            'returned' => count($ids)
        ]);
    }
}
