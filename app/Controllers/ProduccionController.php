<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Pedidos que entran cuando haces "pull"
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando quedan asignados al usuario
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve SOLO pedidos asignados al usuario y en estado Producción
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
            ->select('p.*, pe.estado, pe.actualizado, pe.user_id')
            ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('pe.estado', $this->estadoProduccion)
            ->orderBy('p.assigned_at', 'ASC') // más viejos asignados primero
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
     * 1) Busca pedidos en estado Confirmado y sin asignar
     * 2) Los asigna al usuario
     * 3) Cambia su estado a Producción en pedidos_estado
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
             * 1) Traer candidatos (más viejos primero por pe.actualizado; si NULL, por p.id)
             *    SOLO sin asignar.
             */
            $candidatos = $db->table('pedidos p')
                ->select('p.id')
                ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
                ->where('pe.estado', $this->estadoEntrada)
                ->groupStart()
                    ->where('p.assigned_to_user_id', null)
                    ->orWhere('p.assigned_to_user_id', 0)
                ->groupEnd()
                ->orderBy('CASE WHEN pe.actualizado IS NULL THEN 1 ELSE 0 END', 'ASC', false)
                ->orderBy('pe.actualizado', 'ASC')
                ->orderBy('p.id', 'ASC')
                ->limit($count)
                ->get();

            if ($candidatos === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $rows = $candidatos->getResultArray();
            $ids = array_column($rows, 'id');

            if (empty($ids)) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'ids' => []
                ]);
            }

            /**
             * 2) Asignar pedidos al usuario
             */
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            /**
             * 3) Cambiar estado en pedidos_estado a Producción
             */
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
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
     * Devuelve TODOS los pedidos del usuario (quita asignación)
     * (Opcional) Regresarlos a Confirmado (lo dejo comentado)
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
        $ids = array_column($rows, 'id');

        // Quitar asignación
        $db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        /**
         * Si quieres regresarlos a Confirmado al devolver:
         */
        /*
        if (!empty($ids)) {
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
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
