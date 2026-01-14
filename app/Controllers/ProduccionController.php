<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Pedidos que entran a Producción cuando haces "pull"
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando quedan asignados al usuario de Producción
    private string $estadoProduccion = 'Producción';

    /**
     * GET /produccion
     */
    public function index()
    {
        // Ajusta la vista según tu proyecto:
        // return view('produccion/index');
        return view('produccion');
    }

    /**
     * GET /produccion/my-queue
     * Devuelve SOLO los pedidos asignados al usuario y en estado Produccion
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

    $builder = $db->table('pedidos p')
        ->select('p.id')
        // ✅ LEFT JOIN para no perder pedidos sin fila en pedidos_estado
        ->join('pedidos_estado pe', 'pe.id = p.id', 'left', false)

        // ✅ estado de entrada
        ->where('pe.estado', $this->estadoEntrada)

        // ✅ "sin asignar" (en muchas BD viene como NULL o 0)
        ->groupStart()
            ->where('p.assigned_to_user_id', null)
            ->orWhere('p.assigned_to_user_id', 0)
            ->orWhere('p.assigned_to_user_id', '')
        ->groupEnd()

        // ✅ más viejos primero (usa assigned_at/created_at si existe, si no, por id)
        ->orderBy('p.id', 'ASC')
        ->limit($count);


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
     * 3) Cambia su estado a Produccion en pedidos_estado
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
         * ✅ CLAIM atómico:
         * 1) Asigna al usuario SOLO pedidos libres y en estado Confirmado
         * 2) Respeta orden: más viejos -> más nuevos según pe.actualizado
         *
         * Nota: usamos UPDATE con JOIN + ORDER + LIMIT para evitar carrera.
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

        // ✅ ¿cuántos se asignaron?
        $assigned = (int) ($db->affectedRows() ?? 0);

        if ($assigned <= 0) {
            $db->transCommit();
            return $this->response->setJSON([
                'ok' => true,
                'assigned' => 0
            ]);
        }

        /**
         * 2) Obtener los IDs recién asignados (los más recientes por assigned_at)
         * y ponerlos en estado "Asignado" (tu estadoProduccion)
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
        $ids = array_column($rows, 'id');

        if (!empty($ids)) {
            // ✅ Cambiar estado en pedidos_estado
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
     * Devuelve TODOS los pedidos del usuario (quita asignación)
     * (Opcional) Si quieres también regresarlos a "Confirmado", te lo ajusto.
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
        $ids = array_column($rows, 'id');

        // Quitar asignación en pedidos
        $db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        // Si quieres que al devolverlos regresen a Confirmado, descomenta esto:
        /*
        if (!empty($ids)) {
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
                ->update([
                    'estado' => $this->estadoEntrada, // Confirmado
                    'actualizado' => date('Y-m-d H:i:s'),
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
