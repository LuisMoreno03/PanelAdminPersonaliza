<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class ProduccionController extends BaseController
{
    private string $estadoEntrada = 'Confirmado';
    private string $estadoProduccion = 'Por producir';

    public function index()
    {
        return view('produccion');
    }

    // =========================
    // GET /produccion/my-queue
    // =========================
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int) (session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // ✅ JOIN correcto: pe.order_id = p.id (id interno)
            $rows = $db->query("
                SELECT
                    p.id,
                    p.numero,
                    p.cliente,
                    p.total,
                    p.estado_envio,
                    p.forma_envio,
                    p.etiquetas,
                    p.articulos,
                    p.created_at,
                    p.shopify_order_id,
                    p.assigned_to_user_id,
                    p.assigned_at,
                    pe.estado AS estado_bd,
                    COALESCE(pe.estado_updated_at, pe.actualizado) AS estado_actualizado,
                    pe.estado_updated_by_name AS estado_por
                FROM pedidos p
                LEFT JOIN pedidos_estado pe
                     ON pe.order_id = p.id
                WHERE p.assigned_to_user_id = ?
                  AND LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por producir','confirmado')
                ORDER BY COALESCE(pe.estado_updated_at, pe.actualizado, p.created_at) ASC
            ", [$userId])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController myQueue ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando cola',
            ]);
        }
    }

    // =========================
    // POST /produccion/pull
    // body: {count: 5|10}
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'No autenticado']);
        }

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int)($data['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // ✅ Candidatos: estado "Confirmado" y sin asignar
            // ✅ JOIN correcto: pe.order_id = p.id
            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p
                JOIN pedidos_estado pe
                  ON pe.order_id = p.id
                WHERE LOWER(TRIM(pe.estado)) = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY COALESCE(pe.estado_updated_at, pe.actualizado, p.created_at) ASC
                LIMIT {$count}
            ", [mb_strtolower($this->estadoEntrada)])->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            // 1) Asignar en pedidos
            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->where("(assigned_to_user_id IS NULL OR assigned_to_user_id = 0)", null, false)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now,
                ]);

            // 2) Cambiar estado usando el MODEL
            // ✅ Importante: pasar el ID interno (p.id), NO el shopify_order_id
            $estadoModel = new PedidosEstadoModel();

            foreach ($candidatos as $c) {
                $internalId = (int)($c['id'] ?? 0);
                if ($internalId <= 0) continue;

                // ajusta tu model para que acepte ID interno (ver nota abajo)
                $estadoModel->setEstadoPedido($internalId, $this->estadoProduccion, $userId, $userName);
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON(['ok' => false, 'error' => 'No se pudo asignar (transacción falló)']);
            }

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids),
                'ids' => $ids,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'error' => 'Error interno asignando pedidos']);
        }
    }

    // =========================
    // POST /produccion/return-all
    // =========================
    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'No autenticado']);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);
        }

        try {
            $db = \Config\Database::connect();

            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            return $this->response->setJSON(['ok' => true, 'message' => 'Pedidos devueltos']);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'error' => 'Error interno devolviendo pedidos']);
        }
    }
}
