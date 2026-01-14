<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Estado desde el que se “jala” a la cola
    private string $estadoEntrada = 'Confirmado';

    // Estado al asignar a Producción
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve SOLO pedidos asignados al usuario y con estado "Producción"
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

        $q = $db->table('pedidos p')
            ->select('p.*, pe.estado, pe.actualizado')
            // ✅ relación REAL: pedidos_estado.order_id -> pedidos.id
            ->join('pedidos_estado pe', 'pe.order_id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('TRIM(pe.estado)', $this->estadoProduccion)
            ->orderBy('pe.actualizado', 'ASC')
            ->get();

        if ($q === false) {
            $dbError = $db->error();
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'data' => $q->getResultArray()
        ]);
    }

    /**
     * POST /produccion/pull
     * Body JSON: {"count":5} o {"count":10}
     *
     * - Trae pedidos en estado Confirmado y sin asignar
     * - NO repite (porque filtra assigned_to_user_id NULL/0)
     * - Orden: más viejos -> más nuevos por pe.actualizado (modificación)
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
             * 1) Seleccionar los pedidos disponibles (IDs) en orden de más viejo -> más nuevo
             */
            $rows = $db->table('pedidos_estado pe')
                ->select('pe.order_id')
                // ✅ SOLO Confirmados
                ->where('TRIM(pe.estado)', $this->estadoEntrada)
                // ✅ JOIN para asegurar que el pedido existe
                ->join('pedidos p', 'p.id = pe.order_id', 'inner', false)
                // ✅ sin asignar (NULL o 0 o '')
                ->groupStart()
                    ->where('p.assigned_to_user_id', null)
                    ->orWhere('p.assigned_to_user_id', 0)
                    ->orWhere('p.assigned_to_user_id', '')
                ->groupEnd()
                // ✅ más viejos primero según última modificación en pedidos_estado
                ->orderBy('pe.actualizado', 'ASC')
                ->limit($count)
                ->get()
                ->getResultArray();

            if (!$rows) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'ids' => []
                ]);
            }

            $ids = array_map(fn($r) => (string)$r['order_id'], $rows);

            /**
             * 2) Marcar asignación en pedidos
             */
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            /**
             * 3) Cambiar estado en pedidos_estado a "Producción"
             */
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
     * Quita asignación de TODOS los pedidos del usuario en Producción
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

        // buscar IDs asignados a este user (solo los que están en Producción)
        $q = $db->table('pedidos p')
            ->select('p.id')
            ->join('pedidos_estado pe', 'pe.order_id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('TRIM(pe.estado)', $this->estadoProduccion)
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

        // quitar asignación
        $db->table('pedidos')
            ->whereIn('id', $ids)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        return $this->response->setJSON([
            'ok' => true,
            'returned' => count($ids)
        ]);
    }
}
