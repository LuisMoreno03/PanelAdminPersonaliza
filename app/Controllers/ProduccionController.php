<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Estado desde el que se “toman” pedidos
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando quedan en la cola del usuario
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve SOLO los pedidos asignados al usuario y con estado Producción (en pedidos_estado)
     */
    public function myQueue()
    {
        $userId = (int) (session()->get('user_id') ?? 0);

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => true,
                'data' => []
            ]);
        }

        $db = Database::connect();

        // ✅ JOIN correcto: pedidos_estado.order_id (o fallback a pedidos_estado.id si hay legacy)
        $builder = $db->table('pedidos p')
            ->select('p.*, pe.estado, pe.actualizado')
            ->join('pedidos_estado pe', 'COALESCE(pe.order_id, pe.id) = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('pe.estado', $this->estadoProduccion)
            // ✅ más viejos primero (por última modificación de estado)
            ->orderBy('pe.actualizado', 'ASC')
            ->orderBy('p.created_at', 'ASC')
            ->orderBy('p.id', 'ASC');

        $query = $builder->get();

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
     * 1) Selecciona pedidos en estado Confirmado y sin asignar
     * 2) Los asigna al usuario
     * 3) Cambia su estado a Producción en pedidos_estado
     *
     * ✅ Orden: más viejos -> más nuevos según pe.actualizado
     * ✅ Sin repetir: solo toma los que están libres (assigned_to_user_id NULL/0)
     */
    public function pull()
    {
        $userId = (int) (session()->get('user_id') ?? 0);
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
             * 1) Tomar IDs candidatos (confirmados + libres) en orden ASC
             *    FOR UPDATE evita que dos usuarios tomen lo mismo a la vez.
             */
            $sqlPick = "
                SELECT p.id
                FROM pedidos p
                INNER JOIN pedidos_estado pe
                    ON COALESCE(pe.order_id, pe.id) = p.id
                WHERE TRIM(pe.estado) = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY pe.actualizado ASC, p.created_at ASC, p.id ASC
                LIMIT ?
                FOR UPDATE
            ";

            $pick = $db->query($sqlPick, [$this->estadoEntrada, $count]);
            if ($pick === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $rows = $pick->getResultArray();
            $ids  = array_values(array_filter(array_map(fn($r) => $r['id'] ?? null, $rows)));

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
             * 3) Cambiar estado en pedidos_estado (por order_id)
             *    Si tienes filas legacy con order_id NULL pero id=p.id, el COALESCE del JOIN
             *    ya deja a esos pedidos elegibles, pero acá actualizamos por order_id=id.
             *    Entonces aseguramos el link también.
             */
            // 3a) “arreglar” links faltantes (si existieran)
            $db->query("
                UPDATE pedidos_estado
                SET order_id = id
                WHERE (order_id IS NULL OR order_id = 0)
            ");

            // 3b) Actualizar estado
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
     * - Quita asignación en pedidos
     * - Regresa estado a Confirmado en pedidos_estado (para que puedan volver a “pull”)
     */
    public function returnAll()
    {
        $userId = (int) (session()->get('user_id') ?? 0);

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => true,
                'returned' => 0
            ]);
        }

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        try {
            // 1) IDs asignados al usuario
            $q = $db->table('pedidos')
                ->select('id')
                ->where('assigned_to_user_id', $userId)
                ->get();

            if ($q === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $rows = $q->getResultArray();
            $ids = array_column($rows, 'id');

            if (empty($ids)) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'returned' => 0
                ]);
            }

            // 2) Quitar asignación
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null
                ]);

            // 3) Regresar estado a Confirmado
            $db->table('pedidos_estado')
                ->whereIn('order_id', $ids)
                ->update([
                    'estado' => $this->estadoEntrada,
                    'actualizado' => $now,
                    'user_id' => null
                ]);

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'returned' => count($ids),
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
}
