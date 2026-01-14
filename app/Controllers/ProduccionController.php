<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    private string $estadoEntrada   = 'Confirmado';
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve pedidos asignados al usuario y en estado "Producción"
     */
    public function myQueue()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON(['ok' => true, 'data' => []]);
        }

        $db = Database::connect();

        $query = $db->table('pedidos p')
            ->select('p.id, p.numero, p.cliente, p.total, p.estado_envio, p.forma_envio, p.etiquetas, p.articulos, p.created_at, p.assigned_at, pe.estado, pe.actualizado')
            ->join('pedidos_estado pe', 'pe.order_id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('TRIM(pe.estado)', $this->estadoProduccion)
            ->orderBy('p.assigned_at', 'DESC')
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
             * 1) Claim atómico: asigna SOLO confirmados libres
             *    JOIN correcto: pe.order_id = p.id
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
             * 2) Tomar los ids recién asignados
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

            /**
             * 3) Cambiar estado en pedidos_estado para esos pedidos
             */
            if (!empty($ids)) {
                $db->table('pedidos_estado')
                    ->whereIn('order_id', $ids)
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
     * Desasigna todos los pedidos del usuario.
     * (Opcional) si quieres también regresarlos a Confirmado, te dejo el bloque comentado.
     */
    public function returnAll()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON(['ok' => true, 'returned' => 0]);
        }

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        // IDs asignados al usuario
        $q = $db->table('pedidos')
            ->select('id')
            ->where('assigned_to_user_id', $userId)
            ->get();

        if ($q === false) {
            $dbError = $db->error();
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
            ]);
        }

        $ids = array_map('intval', array_column($q->getResultArray(), 'id'));

        // Quitar asignación
        $db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        // Si quieres que vuelvan a Confirmado al devolver:
        /*
        if (!empty($ids)) {
            $db->table('pedidos_estado')
                ->whereIn('order_id', $ids)
                ->update([
                    'estado' => $this->estadoEntrada,
                    'actualizado' => $now,
                    'user_id' => null
                ]);
        }
        */

        return $this->response->setJSON([
            'ok' => true,
            'returned' => count($ids)
        ]);
    }
}
