<?php

namespace App\Controllers;

use App\Controllers\ProduccionController;

class ProduccionController extends BaseController
{
    /**
     * Vista principal de pedidos Produccion / preparados
     */
    public function index()
    {
        // Si más adelante traes datos desde modelo:
        // $data['pedidos'] = [];

        return view('produccion'); 
        // 👉 cambia 'produccion' por el nombre real de tu vista si es otro
    }

    // GET /produccion/my-queue
public function myQueue()
{
    $userId = session()->get('user_id'); // AJUSTA si tu sesión usa otro nombre

    $db = \Config\Database::connect();

    // Solo pedidos asignados a este usuario y que sigan en estado "produccion"
    $rows = $db->table('pedidos') // AJUSTA nombre real de tu tabla
        ->where('assigned_to_user_id', $userId)
        ->where('estado', 'produccion')
        ->orderBy('assigned_at', 'DESC')
        ->get()
        ->getResultArray();

    return $this->response->setJSON([
        'ok' => true,
        'data' => $rows
    ]);
}


// POST /produccion/pull   {count: 5|10}
public function pull()
{
    $userId = session()->get('user_id'); // AJUSTA si tu sesión usa otro nombre
    $payload = $this->request->getJSON(true) ?? [];
    $count = (int) ($payload['count'] ?? 0);

    if (!in_array($count, [5, 10], true)) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'error' => 'count inválido'
        ]);
    }

    $db = \Config\Database::connect();
    $db->transBegin();

    try {
        // 1) Buscar pedidos disponibles SOLO en estado "produccion" y sin asignar
        $available = $db->table('pedidos') // AJUSTA nombre real de tu tabla
            ->select('id, estado')
            ->where('estado', 'produccion')
            ->where('assigned_to_user_id', null)
            ->orderBy('fecha', 'ASC')
            ->limit($count)
            ->get()
            ->getResultArray();

        if (empty($available)) {
            $db->transCommit();
            return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'ids' => []]);
        }

        $ids = array_column($available, 'id');

        // 2) Asignar
        $db->table('pedidos')
            ->whereIn('id', $ids)
            ->where('assigned_to_user_id', null)
            ->update([
                'assigned_to_user_id' => $userId,
                'assigned_at' => date('Y-m-d H:i:s'),
            ]);

        // 3) (Opcional) Log: si todavía no creaste tabla logs, comenta este bloque
        if ($db->tableExists('produccion_logs')) {
            $now = date('Y-m-d H:i:s');
            $logs = [];
            foreach ($available as $p) {
                $logs[] = [
                    'pedido_id' => $p['id'],
                    'action' => 'ASSIGN',
                    'from_user_id' => null,
                    'to_user_id' => $userId,
                    'from_status' => $p['estado'],
                    'to_status' => $p['estado'],
                    'metadata' => json_encode(['count_requested' => $count]),
                    'created_by_user_id' => $userId,
                    'created_at' => $now,
                ];
            }
            $db->table('produccion_logs')->insertBatch($logs);
        }

        $db->transCommit();

        return $this->response->setJSON(['ok' => true, 'assigned' => count($ids), 'ids' => $ids]);

    } catch (\Throwable $e) {
        $db->transRollback();
        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
    }
}


// POST /produccion/return-all
public function returnAll()
{
    $userId = session()->get('user_id'); // AJUSTA

    $db = \Config\Database::connect();
    $db->transBegin();

    try {
        $current = $db->table('pedidos') // AJUSTA
            ->select('id, estado')
            ->where('assigned_to_user_id', $userId)
            ->where('estado', 'produccion')
            ->get()
            ->getResultArray();

        if (empty($current)) {
            $db->transCommit();
            return $this->response->setJSON(['ok' => true, 'returned' => 0]);
        }

        $ids = array_column($current, 'id');

        $db->table('pedidos')
            ->whereIn('id', $ids)
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null,
            ]);

        // (Opcional) Log
        if ($db->tableExists('produccion_logs')) {
            $now = date('Y-m-d H:i:s');
            $logs = [];
            foreach ($current as $p) {
                $logs[] = [
                    'pedido_id' => $p['id'],
                    'action' => 'UNASSIGN',
                    'from_user_id' => $userId,
                    'to_user_id' => null,
                    'from_status' => $p['estado'],
                    'to_status' => $p['estado'],
                    'metadata' => json_encode(['reason' => 'return-all']),
                    'created_by_user_id' => $userId,
                    'created_at' => $now,
                ];
            }
            $db->table('produccion_logs')->insertBatch($logs);
        }

        $db->transCommit();

        return $this->response->setJSON(['ok' => true, 'returned' => count($ids)]);

    } catch (\Throwable $e) {
        $db->transRollback();
        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
    }
}

}