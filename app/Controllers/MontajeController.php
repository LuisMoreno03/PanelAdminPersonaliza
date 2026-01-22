<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class MontajeController extends BaseController
{
    // ✅ Montaje: ENTRADA = Diseñado
    private string $estadoEntrada = 'Diseñado';

    // ✅ Al marcar "Realizado": SALIDA = Por producir
    private string $estadoSalida = 'Por producir';

    public function index()
    {
        return view('montaje');
    }

    // =========================================================
    // Helper: expresión estado actual (historial -> pedidos_estado -> '')
    // =========================================================
    private function sqlEstadoActualExpr(): string
    {
        // Sin COLLATE explícito para evitar errores en servidores con collation distinto
        return "LOWER(TRIM(COALESCE(h.estado, pe.estado, '')))";
    }

    // =========================================================
    // Helper: condición "NO ENVIADOS"
    // =========================================================
    private function buildCondNoEnviados(\CodeIgniter\Database\BaseConnection $db): string
    {
        $fields = $db->getFieldNames('pedidos') ?? [];
        $hasEstadoEnvio = in_array('estado_envio', $fields, true);
        $hasFulfillment = in_array('fulfillment_status', $fields, true);

        if ($hasEstadoEnvio) {
            return "
                AND (
                    p.estado_envio IS NULL
                    OR TRIM(COALESCE(p.estado_envio,'')) = ''
                    OR LOWER(TRIM(p.estado_envio)) = 'unfulfilled'
                )
            ";
        }

        if ($hasFulfillment) {
            return "
                AND (
                    p.fulfillment_status IS NULL
                    OR TRIM(COALESCE(p.fulfillment_status,'')) = ''
                    OR LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'
                )
            ";
        }

        return "";
    }

    // =========================================================
    // Helper: SELECT seguro según columnas existentes en pedidos
    // (evita que reviente si no existe estado_envio, etc.)
    // =========================================================
    private function buildSelectPedidoFields(\CodeIgniter\Database\BaseConnection $db): string
    {
        $fields = $db->getFieldNames('pedidos') ?? [];

        $sel = [];

        // obligatorias (si no existen, es raro; pero evitamos crash con fallback)
        $sel[] = in_array('id', $fields, true) ? "p.id" : "0 AS id";
        $sel[] = in_array('numero', $fields, true) ? "p.numero" : "CONCAT('#', p.id) AS numero";
        $sel[] = in_array('cliente', $fields, true) ? "p.cliente" : "'' AS cliente";
        $sel[] = in_array('total', $fields, true) ? "p.total" : "'' AS total";
        $sel[] = in_array('created_at', $fields, true) ? "p.created_at" : "NULL AS created_at";

        // opcionales
        $sel[] = in_array('shopify_order_id', $fields, true) ? "p.shopify_order_id" : "NULL AS shopify_order_id";
        $sel[] = in_array('assigned_to_user_id', $fields, true) ? "p.assigned_to_user_id" : "NULL AS assigned_to_user_id";
        $sel[] = in_array('assigned_at', $fields, true) ? "p.assigned_at" : "NULL AS assigned_at";

        $sel[] = in_array('forma_envio', $fields, true) ? "p.forma_envio" : "NULL AS forma_envio";
        $sel[] = in_array('etiquetas', $fields, true) ? "p.etiquetas" : "NULL AS etiquetas";
        $sel[] = in_array('articulos', $fields, true) ? "p.articulos" : "NULL AS articulos";

        // estado_envio: si no existe, usa fulfillment_status como alias estado_envio
        if (in_array('estado_envio', $fields, true)) {
            $sel[] = "p.estado_envio";
        } elseif (in_array('fulfillment_status', $fields, true)) {
            $sel[] = "p.fulfillment_status AS estado_envio";
        } else {
            $sel[] = "NULL AS estado_envio";
        }

        return implode(",\n                    ", $sel);
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

            $condNoEnviados = $this->buildCondNoEnviados($db);
            $estadoExpr = $this->sqlEstadoActualExpr();

            // match estado: "diseñado" o "disenado"
            $e1 = mb_strtolower($this->estadoEntrada, 'UTF-8'); // diseñado
            $e2 = 'disenado';

            $selectPedidos = $this->buildSelectPedidoFields($db);

            $rows = $db->query("
                SELECT
                    {$selectPedidos},

                    -- estado actual (para pintar en UI)
                    COALESCE(h.estado, pe.estado, 'por preparar') AS estado_bd,

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
                  AND ({$estadoExpr} = ? OR {$estadoExpr} = ?)
                  {$condNoEnviados}

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC
            ", [$userId, $e1, $e2])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MontajeController myQueue ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando cola de montaje',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/pull
    // Trae 5 o 10 pedidos en Diseñado (no enviados)
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado'
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión'
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int)($data['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $condNoEnviados = $this->buildCondNoEnviados($db);
            $estadoExpr = $this->sqlEstadoActualExpr();

            $e1 = mb_strtolower($this->estadoEntrada, 'UTF-8'); // diseñado
            $e2 = 'disenado';

            // ✅ candidatos: estado actual = Diseñado, no enviados, no asignados
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
                  AND ({$estadoExpr} = ? OR {$estadoExpr} = ?)
                  {$condNoEnviados}

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC, p.id ASC
                LIMIT {$count}
            ", [$e1, $e2])->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar (no enviados + diseñados)',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            // asignar
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

            // (opcional) historial: registrar que “tomaste” en Diseñado
            foreach ($candidatos as $c) {
                $pedidoId = (string)((int)($c['id'] ?? 0));
                $shopifyId = trim((string)($c['shopify_order_id'] ?? ''));

                $orderKey = ($shopifyId !== '' && $shopifyId !== '0') ? (string)$shopifyId : $pedidoId;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$orderKey,
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
                'error' => 'Error interno haciendo pull en montaje',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/realizado
    // Realizado => Por producir + desasigna + historial
    // =========================
    public function realizado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado'
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión'
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $orderIdRaw = trim((string)($data['order_id'] ?? ''));
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'order_id requerido'
            ]);
        }

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // busca por id o shopify_order_id
            $pedido = $db->table('pedidos')
                ->select('id, shopify_order_id, assigned_to_user_id')
                ->groupStart()
                    ->where('id', $orderIdRaw)
                    ->orWhere('shopify_order_id', $orderIdRaw)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'ok' => false,
                    'error' => 'Pedido no encontrado'
                ]);
            }

            if ((int)($pedido['assigned_to_user_id'] ?? 0) !== $userId) {
                return $this->response->setStatusCode(403)->setJSON([
                    'ok' => false,
                    'error' => 'Este pedido no está asignado a tu usuario'
                ]);
            }

            $pedidoId  = (int)$pedido['id'];
            $shopifyId = trim((string)($pedido['shopify_order_id'] ?? ''));

            // ✅ key para estado/historial: shopify_id si existe, si no id local
            $orderKey = ($shopifyId !== '' && $shopifyId !== '0') ? (string)$shopifyId : (string)$pedidoId;

            $db->transBegin();

            // 1) desasignar (para que desaparezca de la lista)
            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            // 2) set estado a Por producir + historial
            $estadoModel = new PedidosEstadoModel();
            $estadoModel->setEstadoPedido(
                (string)$orderKey,
                $this->estadoSalida, // Por producir
                $userId ?: null,
                $userName
            );

            $db->table('pedidos_estado_historial')->insert([
                'order_id'   => (string)$orderKey,
                'estado'     => $this->estadoSalida,
                'user_id'    => $userId,
                'user_name'  => $userName,
                'created_at' => $now,
                'pedido_json'=> null,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'Falló la transacción'
                ]);
            }

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Realizado → Por producir',
                'new_estado' => $this->estadoSalida,
                'order_key' => $orderKey,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MontajeController realizado ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno marcando como realizado',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/cargado
    // Alias por compatibilidad
    // =========================
    public function cargado()
    {
        return $this->realizado();
    }

    // =========================
    // POST /montaje/return-all
    // =========================
    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado'
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión'
            ]);
        }

        try {
            $db = \Config\Database::connect();

            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Pedidos devueltos'
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MontajeController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno devolviendo pedidos',
                'debug' => $e->getMessage(),
            ]);
        }
    }
}
