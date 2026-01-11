<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    private string $estadoProduccion = 'Produccion';   // AJUSTA si es "Preparado"
    private string $estadoFabricando = 'Fabricando';   // solo referencia futura

    // ============================
    // GET /produccion/my-queue
    // ============================
    public function myQueue()
    {
        $userId = session()->get('user_id'); // ajusta si tu sesión usa otro nombre

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => true,
                'data' => []
            ]);
        }

        $db = Database::connect();

        $rows = $db->table('pedidos p')
            ->select('p.*, pe.estado')
            ->join('pedidos_estado pe', 'pe.pedido_id = p.id', 'inner')
            ->where('p.assigned_to_user_id', $userId)
            ->where('pe.estado', $this->estadoProduccion)
            ->orderBy('p.assigned_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'ok' => true,
            'data' => $rows
        ]);
    }

    // ============================
    // POST /produccion/pull
    // ============================
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
            // Buscar pedidos disponibles en PRODUCCION y sin asignar
            $available = $db->table('pedidos p')
                ->select('p.id')
                ->join('pedidos_estado pe', 'pe.pedido_id = p.id', 'inner')
                ->where('pe.estado', $this->estadoProduccion)
                ->where('p.assigned_to_user_id', null)
                ->orderBy('p.fecha', 'ASC')
                ->limit($count)
                ->get()
                ->getResultArray();

            if (!$available) {
                $db->transCommit();
                return $this->response->setJSON(['ok' => true, 'assigned' => 0]);
            }

            $ids = array_column($available, 'id');

            // Asignar al usuario
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => date('Y-m-d H:i:s')
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

    // ============================
    // POST /produccion/return-all
    // ============================
    public function returnAll()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON(['ok' => true, 'returned' => 0]);
        }

        $db = Database::connect();

        $db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        return $this->response->setJSON([
            'ok' => true
        ]);
    }
}
