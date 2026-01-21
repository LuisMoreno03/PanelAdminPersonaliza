<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class MontajeController extends BaseController
{
    // ✅ Montaje toma pedidos que estén en Diseñado
    private string $estadoEntrada = 'Diseñado';

    // ✅ Al marcar "Cargado" pasan a Por producir
    private string $estadoSalida = 'Por producir';

    public function index()
    {
        return view('montaje'); // tu vista montaje
    }

    // =========================
    // GET /montaje/my-queue
    // =========================
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // ✅ detectar columnas reales
            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

            // ✅ condición NO ENVIADOS
            $condNoEnviados = "";
            if ($hasEstadoEnvio) {
                $condNoEnviados = "
                    AND (
                        p.estado_envio IS NULL
                        OR TRIM(COALESCE(p.estado_envio,'')) = ''
                        OR LOWER(TRIM(p.estado_envio)) = 'unfulfilled'
                    )
                ";
            } elseif ($hasFulfillment) {
                $condNoEnviados = "
                    AND (
                        p.fulfillment_status IS NULL
                        OR TRIM(COALESCE(p.fulfillment_status,'')) = ''
                        OR LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'
                    )
                ";
            }

            $estadoEntradaLower1 = mb_strtolower($this->estadoEntrada, 'UTF-8'); // diseñado
            $estadoEntradaLower2 = 'disenado'; // fallback sin ñ

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

                    COALESCE(
                        CAST(h.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci,
                        CAST(pe.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci,
                        'por preparar'
                    ) AS estado_bd,

                    COALESCE(h.created_at, pe.estado_updated_at, p.created_at) AS estado_actualizado,
                    COALESCE(h.user_name, pe.estado_updated_by_name) AS estado_por

                FROM pedidos p

                LEFT JOIN pedidos_estado pe
                    ON (pe.order_id = p.id OR pe.order_id = p.shopify_order_id)

                LEFT JOIN (
                    SELECT h1.order_id, h1.estado, h1.user_name, h1.created_at
                    FROM pedidos_estado_historial h1
                    INNER JOIN (
                        SELECT order_id, MAX(created_at) AS max_created
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) hx
                    ON hx.order_id = h1.order_id AND hx.max_created = h1.created_at
                ) h
                ON (
                    h.order_id = p.id
                    OR h.order_id = CAST(p.shopify_order_id AS CHAR)
                    OR CAST(h.order_id AS UNSIGNED) = p.shopify_order_id
                )

                WHERE p.assigned_to_user_id = ?
                  AND (
                        LOWER(TRIM(
                            CAST(COALESCE(h.estado, pe.estado, '') AS CHAR)
                            COLLATE utf8mb4_uca1400_ai_ci
                        )) = ?
                        OR LOWER(TRIM(
                            CAST(COALESCE(h.estado, pe.estado, '') AS CHAR)
                            COLLATE utf8mb4_uca1400_ai_ci
                        )) = ?
                  )
                  {$condNoEnviados}

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC
            ", [$userId, $estadoEntradaLower1, $estadoEntradaLower2])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'MontajeController myQueue ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando cola',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/pull
    // Trae 5 o 10 pedidos en Diseñado (no enviados) y los asigna
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

            // ✅ detectar columnas reales
            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

            // ✅ condición NO ENVIADOS
            $condNoEnviados = "";
            if ($hasEstadoEnvio) {
                $condNoEnviados = "
                    AND (
                        p.estado_envio IS NULL
                        OR TRIM(COALESCE(p.estado_envio,'')) = ''
                        OR LOWER(TRIM(p.estado_envio)) = 'unfulfilled'
                    )
                ";
            } elseif ($hasFulfillment) {
                $condNoEnviados = "
                    AND (
                        p.fulfillment_status IS NULL
                        OR TRIM(COALESCE(p.fulfillment_status,'')) = ''
                        OR LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'
                    )
                ";
            }

            $estadoEntradaLower1 = mb_strtolower($this->estadoEntrada, 'UTF-8'); // diseñado
            $estadoEntradaLower2 = 'disenado';

            // ✅ candidatos: estado actual = Diseñado (historial o pedidos_estado), no enviados, no asignados
            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p

                LEFT JOIN pedidos_estado pe
                    ON (pe.order_id = p.id OR pe.order_id = p.shopify_order_id)

                LEFT JOIN (
                    SELECT h1.order_id, h1.estado, h1.user_name, h1.created_at
                    FROM pedidos_estado_historial h1
                    INNER JOIN (
                        SELECT order_id, MAX(created_at) AS max_created
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) hx
                    ON hx.order_id = h1.order_id AND hx.max_created = h1.created_at
                ) h
                ON (
                    h.order_id = p.id
                    OR h.order_id = CAST(p.shopify_order_id AS CHAR)
                    OR CAST(h.order_id AS UNSIGNED) = p.shopify_order_id
                )

                WHERE (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                  AND (
                        LOWER(TRIM(
                            CAST(COALESCE(h.estado, pe.estado, '') AS CHAR)
                            COLLATE utf8mb4_uca1400_ai_ci
                        )) = ?
                        OR LOWER(TRIM(
                            CAST(COALESCE(h.estado, pe.estado, '') AS CHAR)
                            COLLATE utf8mb4_uca1400_ai_ci
                        )) = ?
                  )
                  {$condNoEnviados}

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC, p.id ASC
                LIMIT {$count}
            ", [$estadoEntradaLower1, $estadoEntradaLower2])->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar (no enviados + diseñados)',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            // 1) asignar en pedidos
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->where("(assigned_to_user_id IS NULL OR assigned_to_user_id = 0)", null, false)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now,
                ]);

            $affected = (int)$db->affectedRows();

            if ($affected <= 0) {
                $db->transComplete();
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'No se asignó nada (affectedRows=0).',
                    'debug' => ['ids' => $ids],
                ]);
            }

            // 2) opcional (igual que tu producción): insertar historial con el mismo estado para que quede "último cambio" por usuario
            foreach ($candidatos as $c) {
                $shopifyId = trim((string)($c['shopify_order_id'] ?? ''));
                if ($shopifyId === '' || $shopifyId === '0') continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$shopifyId,
                    'estado'     => $this->estadoEntrada, // Diseñado
                    'user_id'    => $userId,
                    'user_name'  => $userName,
                    'created_at' => $now,
                    'pedido_json'=> null,
                ]);
            }

            $db->transComplete();

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => $affected,
                'ids' => $ids,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'MontajeController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno asignando pedidos',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/cargado
    // Marca como "Por producir" + desasigna + historial
    // =========================
    public function cargado()
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

        $orderIdRaw = trim((string)($data['order_id'] ?? ''));
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'order_id requerido']);
        }

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // localizar pedido por id o shopify_order_id
            $pedido = $db->table('pedidos')
                ->select('id, shopify_order_id, assigned_to_user_id')
                ->groupStart()
                    ->where('id', $orderIdRaw)
                    ->orWhere('shopify_order_id', $orderIdRaw)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'error' => 'Pedido no encontrado']);
            }

            if ((int)($pedido['assigned_to_user_id'] ?? 0) !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'error' => 'Este pedido no está asignado a tu usuario']);
            }

            $pedidoId = (int)$pedido['id'];
            $shopifyId = trim((string)($pedido['shopify_order_id'] ?? ''));

            $db->transBegin();

            // 1) desasignar
            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            // 2) set estado oficial (pedidos_estado) si existe el modelo
            $estadoModel = new PedidosEstadoModel();
            if ($shopifyId !== '' && $shopifyId !== '0') {
                $estadoModel->setEstadoPedido(
                    (string)$shopifyId,
                    $this->estadoSalida,     // Por producir
                    $userId ?: null,
                    $userName
                );

                // 3) historial
                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$shopifyId,
                    'estado'     => $this->estadoSalida,
                    'user_id'    => $userId,
                    'user_name'  => $userName,
                    'created_at' => $now,
                    'pedido_json'=> null,
                ]);
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'Falló la transacción (cargado)',
                ]);
            }

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Pedido marcado como Cargado → Por producir',
                'pedido_id' => $pedidoId,
                'shopify_order_id' => $shopifyId,
                'new_estado' => $this->estadoSalida,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'MontajeController cargado ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno marcando como cargado',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/return-all
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
            log_message('error', 'MontajeController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'error' => 'Error interno devolviendo pedidos']);
        }
    }
}
