<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Pedidos que entran a Producción cuando haces "pull"
    private string $estadoEntrada = 'Confirmado';

    // Estado al que pasan cuando quedan asignados al usuario de Producción
    private string $estadoProduccion = 'Asignado';

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

        $query = $db->table('pedidos p')
            ->select('p.*, pe.estado')
            // En tu BD: pedidos_estado.id = pedidos.id
            ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
            ->where('p.assigned_to_user_id', $userId)
            ->where('pe.estado', $this->estadoProduccion)
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
        $db->transBegin();

        try {
            // 1) Buscar pedidos disponibles en estado Confirmado y sin asignar
            $builder = $db->table('pedidos p')
                ->select('p.id')
                ->join('pedidos_estado pe', 'pe.id = p.id', 'inner', false)
                ->where('pe.estado', $this->estadoEntrada)   // <-- Confirmado
                ->where('p.assigned_to_user_id', null)
                ->orderBy('p.id', 'ASC')                     // Cambia a p.created_at si existe
                ->limit($count);

            $query = $builder->get();

            if ($query === false) {
                $dbError = $db->error();
                throw new \RuntimeException('DB error: ' . ($dbError['message'] ?? 'unknown'));
            }

            $available = $query->getResultArray();

            if (!$available) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0
                ]);
            }

            $ids = array_column($available, 'id');

            // 2) Asignar los pedidos al usuario
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => date('Y-m-d H:i:s')
                ]);

            // 3) Cambiar estado a Produccion (en tu BD pedidos_estado.id = pedido.id)
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
                ->update([
                    'estado' => $this->estadoProduccion,
                    'actualizado' => date('Y-m-d H:i:s'),
                    'user_id' => $userId
                ]);

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids)
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
