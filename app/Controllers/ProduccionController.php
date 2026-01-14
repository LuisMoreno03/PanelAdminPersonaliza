<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;

class ProduccionController extends BaseController
{
    // Estados
    private string $estadoEntrada = 'confirmado';   // OJO: en minúscula para comparar normalizado
    private string $estadoProduccion = 'producción'; // normalizado (si en BD está sin tilde, lo ajustamos abajo)

    public function index()
    {
        return view('produccion');
    }

    public function myQueue()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON(['ok' => true, 'data' => []]);
        }

        $db = Database::connect();

        $q = $db->table('pedidos p')
            ->select('p.*, pe.estado, pe.actualizado')
            ->join('pedidos_estado pe', 'pe.id = p.id', 'left', false)
            ->where('p.assigned_to_user_id', (int)$userId)
            // ✅ estado producción tolerante: producción / produccion
            ->where("(LOWER(TRIM(pe.estado)) = 'producción' OR LOWER(TRIM(pe.estado)) = 'produccion')", null, false)
            ->orderBy('pe.actualizado', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->get();

        if ($q === false) {
            $err = $db->error();
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'DB error: ' . ($err['message'] ?? 'unknown')
            ]);
        }

        return $this->response->setJSON(['ok' => true, 'data' => $q->getResultArray()]);
    }

    public function pull()
    {
        $userId  = session()->get('user_id');
        $payload = $this->request->getJSON(true);
        $count   = (int)($payload['count'] ?? 0);

        if (!$userId || !in_array($count, [5, 10], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'Datos inválidos'
            ]);
        }

        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $hasAssignedAt = $db->fieldExists('assigned_at', 'pedidos');

        $db->transBegin();

        try {
            // ✅ Selección tolerante de Confirmado
            $builder = $db->table('pedidos p')
                ->select('p.id')
                ->join('pedidos_estado pe', 'pe.id = p.id', 'left', false)

                // ✅ Confirmado tolerante (mayúsculas/espacios)
                ->where("LOWER(TRIM(pe.estado)) = 'confirmado'", null, false)

                // ✅ "sin asignar" tolerante (NULL / 0 / '')
                ->groupStart()
                    ->where('p.assigned_to_user_id', null)
                    ->orWhere('p.assigned_to_user_id', 0)
                    ->orWhere('p.assigned_to_user_id', '')
                ->groupEnd()

                // ✅ más viejos primero
                ->orderBy('pe.actualizado', 'ASC')
                ->orderBy('p.id', 'ASC')
                ->limit($count);

            // ✅ FOR UPDATE (evita duplicados entre usuarios)
            $sql = $builder->getCompiledSelect() . ' FOR UPDATE';
            $q = $db->query($sql);

            if ($q === false) {
                $err = $db->error();
                throw new \RuntimeException('DB error: ' . ($err['message'] ?? 'unknown'));
            }

            $rows = $q->getResultArray();
            $ids  = array_map('intval', array_column($rows, 'id'));

            if (empty($ids)) {
                $db->transCommit();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'ids' => []
                ]);
            }

            // 1) Asignar pedidos
            $upd = ['assigned_to_user_id' => (int)$userId];
            if ($hasAssignedAt) $upd['assigned_at'] = $now;

            $db->table('pedidos')->whereIn('id', $ids)->update($upd);

            // 2) Cambiar estado a Producción (con y sin tilde para compatibilidad)
            $db->table('pedidos_estado')
                ->whereIn('id', $ids)
                ->update([
                    'estado' => 'Producción',      // si tu BD usa "Produccion", cámbialo aquí
                    'actualizado' => $now,
                    'user_id' => (int)$userId
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

    public function returnAll()
    {
        $userId = session()->get('user_id');

        if (!$userId) {
            return $this->response->setJSON(['ok' => true, 'returned' => 0]);
        }

        $db = Database::connect();
        $hasAssignedAt = $db->fieldExists('assigned_at', 'pedidos');

        $q = $db->table('pedidos')->select('id')->where('assigned_to_user_id', (int)$userId)->get();
        $ids = $q ? array_map('intval', array_column($q->getResultArray(), 'id')) : [];

        $upd = ['assigned_to_user_id' => null];
        if ($hasAssignedAt) $upd['assigned_at'] = null;

        $db->table('pedidos')->where('assigned_to_user_id', (int)$userId)->update($upd);

        return $this->response->setJSON([
            'ok' => true,
            'returned' => count($ids),
            'ids' => $ids
        ]);
    }
}
