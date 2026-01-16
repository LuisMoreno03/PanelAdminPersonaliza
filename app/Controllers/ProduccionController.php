<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ProduccionController extends BaseController
{
    /**
     * Estado que Producción "toma" como entrada (debe existir en pedidos_estado.estado)
     */
    private string $estadoEntrada = 'Confirmado';

    /**
     * Estado al que pasa cuando Producción lo asigna (tu modal usa "Por producir")
     */
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

            // ✅ IMPORTANTÍSIMO:
            // JOIN por texto: pe.order_id = p.shopify_order_id
            // y usar pe.estado_updated_at (no "actualizado")
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
                    pe.estado_updated_at AS estado_actualizado,
                    pe.estado_updated_by_name AS estado_por
                FROM pedidos p
                LEFT JOIN pedidos_estado pe
                     ON pe.order_id = p.shopify_order_id
                WHERE p.assigned_to_user_id = ?
                  AND LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por producir','producción','produccion','confirmado')
                ORDER BY pe.estado_updated_at ASC
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
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int) (session('user_id') ?? 0);
        $userName = (string) (session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int) ($data['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // ✅ Traer candidatos:
            // - pedidos no asignados
            // - estado = Confirmado (en pedidos_estado)
            // JOIN texto: pe.order_id = p.shopify_order_id
            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p
                JOIN pedidos_estado pe
                  ON pe.order_id = p.shopify_order_id
                WHERE LOWER(TRIM(pe.estado)) = ?
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY pe.estado_updated_at ASC
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

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            // 1) Asignar en pedidos
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->where("(assigned_to_user_id IS NULL OR assigned_to_user_id = 0)", null, false)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at'         => $now,
                ]);

            // 2) ✅ Mover estado a "Por producir" (para que Producción los vea ya en su flujo)
            // Esto hace que ya no sigan quedando en "Confirmado" eternamente.
            foreach ($candidatos as $c) {
                $oid = (string)($c['shopify_order_id'] ?? '');
                if ($oid === '') continue;

                $db->table('pedidos_estado')
                    ->where('order_id', $oid)
                    ->update([
                        'estado'                 => $this->estadoProduccion,
                        'estado_updated_at'      => $now,
                        'estado_updated_by'      => $userId,
                        'estado_updated_by_name' => $userName,
                    ]);
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'No se pudo asignar (transacción falló)',
                ]);
            }

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids),
                'ids' => $ids,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno asignando pedidos',
            ]);
        }
    }

    // =========================
    // POST /produccion/return-all
    // =========================
    public function returnAll()
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

            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at'         => null,
                ]);

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Pedidos devueltos',
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno devolviendo pedidos',
            ]);
        }
    }
}
