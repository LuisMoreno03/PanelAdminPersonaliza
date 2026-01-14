<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Estado desde el cual se "jalan" pedidos
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando se asignan al usuario
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
     * Devuelve SOLO pedidos asignados al usuario y en estado "Producción"
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

        // Relación correcta: pedidos_estado.order_id = pedidos.id
        $query = $db->table('pedidos p')
            ->select('p.*, pe.estado, pe.actualizado AS estado_actualizado')
            ->join('pedidos_estado pe', 'pe.order_id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('TRIM(pe.estado)', $this->estadoProduccion)
            ->orderBy('pe.actualizado', 'DESC')
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
     *
     * 1) Busca pedidos en estado "Confirmado" y sin asignar
     * 2) Los asigna al usuario
     * 3) Cambia su estado a "Producción" en pedidos_estado
     * 4) Orden: más viejos -> más nuevos por pe.actualizado
     */
    public function pull()
    {
        $userId = session()->get('user_id');
        $payload = $this->request->getJSON(true);
        $count = (int)($payload['count'] ?? 0);

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
             * Tomamos IDs a asignar (bloqueando filas para evitar doble asignación)
             * IMPORTANTE: join correcto por pe.order_id = p.id
             */
            $sqlSelect = "
                SELECT p.id
                FROM pedidos p
                INNER JOIN pedidos_estado pe ON pe.order_id = p.id
                WHERE TRIM(pe.estado) = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                  AND pe.order_id IS NOT NULL
                  AND pe.order_id <> 0
                ORDER BY pe.actualizado ASC, p.created_at ASC, p.id ASC
                LIMIT ?
                FOR UPDATE
            ";

            $rows = $db->query($sqlSelect, [$this->estadoEntrada, $count])->getResultArray();
            $ids  = array_column($rows, 'id');

            if (empty($ids)) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'ids' => []
                ]);
            }

            // 1) Asignar en pedidos
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            // 2) Pasar estado a Producción en pedidos_estado (por order_id)
            $db->table('pedidos_estado')
                ->whereIn('order_id', $ids)
                ->update([
                    'estado' => $this->estadoProduccion,
                    'actualizado' => $now,
                    'user_id' => $userId
                ]);

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
     * Devuelve TODOS los pedidos del usuario:
     * - quita asignación en pedidos
     * - y los regresa a "Confirmado" en pedidos_estado (para que vuelvan a estar disponibles)
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

        $rows = $q->getResultArray();
        $ids = array_column($rows, 'id');

        if (empty($ids)) {
            return $this->response->setJSON([
                'ok' => true,
                'returned' => 0
            ]);
        }

        $db->transBegin();

        try {
            // Quitar asignación en pedidos
            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null
                ]);

            // Regresar estado a Confirmado (solo los que estaban en Producción)
            $db->table('pedidos_estado')
                ->whereIn('order_id', $ids)
                ->where('TRIM(estado)', $this->estadoProduccion)
                ->update([
                    'estado' => $this->estadoEntrada,
                    'actualizado' => $now,
                    'user_id' => null
                ]);

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'returned' => count($ids)
            ]);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}
