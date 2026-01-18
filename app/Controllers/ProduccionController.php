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

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // ✅ Ultimo estado desde HISTORIAL (por pedido interno p.id)
            // Nota: h.created_at existe, h.actualizado NO existe.
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

                    -- ✅ estado actual: primero historial, si no hay historial usa pedidos_estado
                    COALESCE(
                        CAST(h.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci,
                        CAST(pe.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci,
                        'por preparar'
                    ) AS estado_bd,


                    -- ✅ ultimo cambio
                    COALESCE(h.created_at, pe.estado_updated_at, pe.actualizado, p.created_at) AS estado_actualizado,
                    COALESCE(h.user_name, pe.estado_updated_by_name) AS estado_por

                FROM pedidos p

                -- fallback: pedidos_estado (ojo: a veces guarda order_id = p.id o shopify_order_id)
                LEFT JOIN pedidos_estado pe
                    ON (pe.order_id = p.id OR pe.order_id = p.shopify_order_id)

                -- ✅ subquery: ultimo historial por p.id
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
                ON h.order_id = p.id

                WHERE p.assigned_to_user_id = ?
                    AND LOWER(TRIM(
                        CAST(COALESCE(h.estado, pe.estado, '') AS CHAR)
                        COLLATE utf8mb4_uca1400_ai_ci
                    )) = 'confirmado'


                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, pe.actualizado, p.created_at) ASC
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
                'debug' => $e->getMessage(),
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

            // ✅ candidatos por último estado en historial
            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p

                INNER JOIN (
                    SELECT h1.*
                    FROM pedidos_estado_historial h1
                    INNER JOIN (
                        SELECT order_id, MAX(id) AS last_id
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) x ON x.last_id = h1.id
                ) h ON (
                    h.order_id = p.id
                    OR h.order_id = CAST(p.shopify_order_id AS CHAR)
                    OR CAST(h.order_id AS UNSIGNED) = p.shopify_order_id
                )

                WHERE TRIM(LOWER(h.estado)) COLLATE utf8mb4_unicode_ci = 'confirmado'
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)

                ORDER BY h.created_at ASC, p.id ASC
                LIMIT {$count}
            ")->getResultArray();


            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            // ✅ asigna en pedidos
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

            // ✅ (OPCIONAL) registra evento en historial para auditar la asignación
            //    Si NO quieres cambiar estado aquí, igual puedes registrar "Asignado".
            foreach ($candidatos as $c) {
                $shopifyId = trim((string)($c['shopify_order_id'] ?? ''));
                if ($shopifyId === '' || $shopifyId === '0') continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (int)$shopifyId,
                    'estado'     => 'Confirmado',     // o 'Asignado' si prefieres
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
            log_message('error', 'ProduccionController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno asignando pedidos',
                'debug' => $e->getMessage(),
            ]);
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
    public function uploadGeneral()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderIdRaw = trim((string)($this->request->getPost('order_id') ?? ''));
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'order_id requerido',
            ])->setStatusCode(400);
        }

        $files = $this->request->getFiles();
        if (!isset($files['files'])) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Sin archivos',
            ])->setStatusCode(400);
        }

        $db = \Config\Database::connect();

        // ✅ mejor timestamp (evita empates de created_at)
        // si tu columna created_at es DATETIME sin micros, igual sirve como string distinto si lo guardas
        $now = date('Y-m-d H:i:s'); 
        $nowMicro = date('Y-m-d H:i:s') . '.' . substr((string)microtime(true), -6);

        // ------------------------------------------------------------
        // 1) Resolver pedido en DB: puede venir p.id o p.shopify_order_id
        // ------------------------------------------------------------
        $pedido = $db->table('pedidos')
            ->select('id, shopify_order_id, assigned_to_user_id')
            ->groupStart()
                ->where('id', $orderIdRaw)
                ->orWhere('shopify_order_id', $orderIdRaw)
            ->groupEnd()
            ->get()
            ->getRowArray();

        $pedidoId = $pedido['id'] ?? null;
        $shopifyOrderId = trim((string)($pedido['shopify_order_id'] ?? $orderIdRaw)); // fallback

        // ------------------------------------------------------------
        // 2) Guardar archivos
        // ------------------------------------------------------------
        $saved = 0;
        $out = [];

        // ✅ carpeta siempre por el pedido interno (si existe) para consistencia
        $folderKey = $pedidoId ? (string)$pedidoId : $orderIdRaw;

        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        foreach ($files['files'] as $f) {
            if (!$f || !$f->isValid()) continue;

            $newName  = $f->getRandomName();
            $original = $f->getName();
            $mime     = $f->getClientMimeType();

            $f->move($dir, $newName);

            $saved++;
            $out[] = [
                'original_name' => $original,
                'filename' => $newName,
                'mime' => $mime,
                'size' => $f->getSize(),
                'created_at' => $now,
                'url' => site_url("produccion/file/{$folderKey}/{$newName}"),
            ];
        }

        if ($saved <= 0) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No se subió ningún archivo válido',
            ])->setStatusCode(200);
        }

        // ------------------------------------------------------------
        // 3) Acciones post-upload:
        //    - Cambiar estado a "Por producir"
        //    - Quitar asignación
        //    - Registrar historial
        // ------------------------------------------------------------
        $didUnassign = false;
        $didEstado = false;
        $didHist = false;

        try {
            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');

            $db->transStart();

            if ($pedidoId) {
                // ✅ 3.1) desasignar (para que desaparezca de la cola)
                $db->table('pedidos')
                    ->where('id', (int)$pedidoId)
                    ->update([
                        'assigned_to_user_id' => null,
                        'assigned_at' => null,
                    ]);
                $didUnassign = true;

                // ✅ 3.2) estado actual en pedidos_estado (order_id = shopify_order_id)
                $estadoModel = new \App\Models\PedidosEstadoModel();
                $didEstado = (bool) $estadoModel->setEstadoPedido(
                    (string)$shopifyOrderId,
                    'Por producir',
                    $userId ?: null,
                    $userName
                );

                // ✅ 3.3) historial: IMPORTANTE -> order_id = shopify_order_id
                // y created_at con micro para evitar empates
                $okHist = $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$shopifyOrderId,
                    'estado'     => 'Por producir',
                    'user_id'    => $userId ?: null,
                    'user_name'  => $userName,
                    'created_at' => $nowMicro, // ✅ evita empates
                    'pedido_json'=> null,
                ]);
                $didHist = (bool)$okHist;
            }

            $db->transComplete();

        } catch (\Throwable $e) {
            log_message('error', 'uploadGeneral post-actions ERROR: ' . $e->getMessage());

            // ✅ NO abortamos el upload, pero avisamos.
            return $this->response->setJSON([
                'success' => true,
                'saved' => $saved,
                'files' => $out,
                'warning' => 'Archivos subidos, pero falló actualizar estado/desasignar',
                'debug' => $e->getMessage(),
                'order_id_received' => $orderIdRaw,
                'pedido_id' => $pedidoId,
                'shopify_order_id' => $shopifyOrderId,
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'success' => true,
            'saved' => $saved,
            'files' => $out,

            // info extra para el frontend
            'order_id_received' => $orderIdRaw,
            'folder_key' => $folderKey,
            'pedido_id' => $pedidoId,
            'shopify_order_id' => $shopifyOrderId,

            'estado_set' => $didEstado,
            'historial_inserted' => $didHist,
            'unassigned' => $didUnassign,
            'new_estado' => 'Por producir',
        ])->setStatusCode(200);
    }

    public function listGeneral()
    {
        $orderId = $this->request->getGet('order_id');
        if (!$orderId) {
            return $this->response->setJSON(['success' => false, 'message' => 'order_id requerido'])->setStatusCode(400);
        }

        $dir = WRITEPATH . "uploads/produccion/" . $orderId;
        if (!is_dir($dir)) {
            return $this->response->setJSON(['success' => true, 'files' => []]);
        }

        $files = [];
        foreach (scandir($dir) as $name) {
            if ($name === "." || $name === "..") continue;
            $path = $dir . "/" . $name;
            if (!is_file($path)) continue;

            $files[] = [
                'original_name' => $name,
                'filename' => $name,
                'mime' => mime_content_type($path),
                'size' => filesize($path),
                'created_at' => date('Y-m-d H:i:s', filemtime($path)),
                'url' => site_url("produccion/file/{$orderId}/{$name}")
            ];
        }

        // ordena más nuevos arriba
        usort($files, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));

        return $this->response->setJSON(['success' => true, 'files' => $files]);
    }

}


