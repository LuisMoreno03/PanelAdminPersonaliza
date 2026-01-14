<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Pedidos que entran al flujo cuando haces "pull"
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando quedan asignados al usuario
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
                'ok'   => true,
                'data' => []
            ]);
        }

        $db = Database::connect();

        $query = $db->table('pedidos p')
            ->select('p.*, pe.estado, pe.actualizado')
            ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', (int)$userId)
            ->where('pe.estado', $this->estadoProduccion)
            // ✅ más viejos primero (para que trabajes en orden)
            ->orderBy('pe.actualizado', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->get();

        if ($query === false) {
            $dbError = $db->error();
            return $this->response->setStatusCode(500)->setJSON([
                'ok'    => false,
                'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
            ]);
        }

        return $this->response->setJSON([
            'ok'   => true,
            'data' => $query->getResultArray()
        ]);
    }

    /**
     * POST /produccion/pull
     * Body JSON: { "count": 5 } o { "count": 10 }
     *
     * 1) Busca pedidos en estado Confirmado y sin asignar
     * 2) Los asigna al usuario (sin repetir)
     * 3) Cambia su estado a Producción en pedidos_estado
     *
     * Orden: más viejos -> más nuevos según pe.actualizado (fallback por p.id)
     */
    public function pull()
    {
        $userId  = session()->get('user_id');
        $payload = $this->request->getJSON(true);
        $count   = (int)($payload['count'] ?? 0);

        if (!$userId || !in_array($count, [5, 10], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'    => false,
                'error' => 'Datos inválidos'
            ]);
        }

        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        // ✅ usar assigned_at SOLO si existe
        $hasAssignedAt = $db->fieldExists('assigned_at', 'pedidos');

        $db->transBegin();

        try {
            /**
             * ✅ Selección segura (bloquea filas) para evitar que dos usuarios
             * agarren los mismos pedidos.
             */
            $builder = $db->table('pedidos p')
                ->select('p.id')
                ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
                ->where('pe.estado', $this->estadoEntrada)
                ->groupStart()
                    ->where('p.assigned_to_user_id', null)
                    ->orWhere('p.assigned_to_user_id', 0)
                ->groupEnd()
                ->orderBy('pe.actualizado', 'ASC')
                ->orderBy('p.id', 'ASC')
                ->limit($count);

            // ✅ FOR UPDATE (MySQL) para bloquear selección en transacción
            $sql = $builder->getCompiledSelect() . ' FOR UPDATE';
            $query = $db->query($sql);

            if ($query === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $rows = $query->getResultArray();
            $ids  = array_map('intval', array_column($rows, 'id'));

            if (empty($ids)) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok'       => true,
                    'assigned' => 0,
                    'ids'      => []
                ]);
            }

            // 1) Asignar pedidos al usuario
            $updatePedidos = [
                'assigned_to_user_id' => (int)$userId,
            ];
            if ($hasAssignedAt) {
                $updatePedidos['assigned_at'] = $now;
            }

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update($updatePedidos);

            // 2) Cambiar estado a Producción + marcar actualizado/user_id
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
                ->update([
                    'estado'      => $this->estadoProduccion,
                    'actualizado' => $now,
                    'user_id'     => (int)$userId
                ]);

            $db->transCommit();

            return $this->response->setJSON([
                'ok'       => true,
                'assigned' => count($ids),
                'ids'      => $ids
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();

            return $this->response->setStatusCode(500)->setJSON([
                'ok'    => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /produccion/return-all
     * Devuelve TODOS los pedidos del usuario (quita asignación)
     * (Opcional) Si quieres también regresarlos a "Confirmado", te lo dejo comentado abajo.
     */
    public function returnAll()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON([
                'ok'       => true,
                'returned' => 0
            ]);
        }

        $db = Database::connect();

        $hasAssignedAt = $db->fieldExists('assigned_at', 'pedidos');

        // Obtener IDs asignados al usuario
        $q = $db->table('pedidos')
            ->select('id')
            ->where('assigned_to_user_id', (int)$userId)
            ->get();

        if ($q === false) {
            $dbError = $db->error();
            return $this->response->setStatusCode(500)->setJSON([
                'ok'    => false,
                'error' => 'DB error: ' . ($dbError['message'] ?? 'unknown')
            ]);
        }

        $ids = array_map('intval', array_column($q->getResultArray(), 'id'));

        // Quitar asignación
        $update = [
            'assigned_to_user_id' => null,
        ];
        if ($hasAssignedAt) {
            $update['assigned_at'] = null;
        }

        $db->table('pedidos')
            ->where('assigned_to_user_id', (int)$userId)
            ->update($update);

        /**
         * ✅ Si quieres que al devolverlos vuelvan a Confirmado, descomenta:
         */
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
            'ok'       => true,
            'returned' => count($ids),
            'ids'      => $ids
        ]);
    }
}
