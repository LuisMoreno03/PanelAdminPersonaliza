<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class ProduccionController extends BaseController
{
    private string $estadoEntrada = 'Confirmado';
    // ✅ CAMBIO: antes 'Por producir'
    private string $estadoProduccion = 'Diseñado';

    public function index()
    {
        return view('produccion');
    }

    // =========================
    // Helpers (resolver pedido/carpeta)
    // =========================
    private function resolvePedidoKeys(string $orderIdRaw): array
    {
        $orderIdRaw = trim($orderIdRaw);

        $db = \Config\Database::connect();

        $pedido = $db->table('pedidos')
            ->select('id, shopify_order_id, assigned_to_user_id')
            ->groupStart()
                ->where('id', $orderIdRaw)
                ->orWhere('shopify_order_id', $orderIdRaw)
            ->groupEnd()
            ->get()
            ->getRowArray();

        $pedidoId = $pedido['id'] ?? null;

        // Shopify id "oficial" (numérico)
        $shopifyOrderId = '';
        if (!empty($pedido['shopify_order_id'])) {
            $shopifyOrderId = trim((string)$pedido['shopify_order_id']);
        } else {
            $tmp = trim((string)$orderIdRaw);
            if ($tmp !== '' && preg_match('/^\d{6,}$/', $tmp)) {
                $shopifyOrderId = $tmp;
            }
        }

        // preferencia: carpeta por pedido interno si existe
        $preferredFolderKey = $pedidoId ? (string)$pedidoId : $orderIdRaw;

        return [
            'pedido' => $pedido,
            'pedido_id' => $pedidoId,
            'shopify_order_id' => $shopifyOrderId,
            'preferred_folder_key' => $preferredFolderKey,
        ];
    }

    private function resolveExistingFolderKey(string $orderIdRaw, ?string $preferredFolderKey, ?string $pedidoIdStr, ?string $shopifyOrderId): string
    {
        $orderIdRaw = trim((string)$orderIdRaw);
        $candidates = [];

        if ($preferredFolderKey) $candidates[] = $preferredFolderKey;
        if ($pedidoIdStr) $candidates[] = $pedidoIdStr;
        if ($orderIdRaw !== '') $candidates[] = $orderIdRaw;
        if ($shopifyOrderId) $candidates[] = $shopifyOrderId;

        // unique manteniendo orden
        $seen = [];
        $uniq = [];
        foreach ($candidates as $c) {
            $c = trim((string)$c);
            if ($c === '' || isset($seen[$c])) continue;
            $seen[$c] = true;
            $uniq[] = $c;
        }

        foreach ($uniq as $key) {
            $dir = WRITEPATH . "uploads/produccion/" . $key;
            if (is_dir($dir)) return $key;
        }

        // si no existe ninguna, devolvemos el preferido o el raw
        return $preferredFolderKey ?: ($orderIdRaw ?: '0');
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

            // ✅ detectar columnas reales
            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

            // ✅ condición NO ENVIADOS (solo unfulfilled / null / vacío)
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

           $coll = 'utf8mb4_unicode_ci';
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
                        CAST(h.estado AS CHAR) COLLATE {$coll},
                        CAST(pe.estado AS CHAR) COLLATE {$coll},
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
                AND LOWER(TRIM(
                        CAST(COALESCE(h.estado, pe.estado, '') AS CHAR) COLLATE {$coll}
                )) = ('confirmado' COLLATE {$coll})
                {$condNoEnviados}

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC
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

            $coll = 'utf8mb4_unicode_ci';

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

                WHERE LOWER(TRIM(CAST(h.estado AS CHAR) COLLATE {$coll}))
                    = ('confirmado' COLLATE {$coll})
                {$condNoEnviados}
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)

                ORDER BY h.created_at ASC, p.id ASC
                LIMIT {$count}
            ")->getResultArray();


            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar (no enviados + confirmados)',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

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

            foreach ($candidatos as $c) {
                $shopifyId = trim((string)($c['shopify_order_id'] ?? ''));
                if ($shopifyId === '' || $shopifyId === '0') continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$shopifyId,
                    'estado'     => 'Confirmado',
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

    // =========================
    // POST /produccion/upload-general
    // =========================
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
        $now = date('Y-m-d H:i:s');

        // ------------------------------------------------------------
        // 1) Resolver pedido / ids
        // ------------------------------------------------------------
        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedido = $keys['pedido'];
        $pedidoId = $keys['pedido_id'];
        $shopifyOrderId = $keys['shopify_order_id'];

        // ------------------------------------------------------------
        // 2) Guardar archivos
        // ------------------------------------------------------------
        $saved = 0;
        $out = [];

        // ✅ Carpeta siempre por pedido interno si existe, si no por el raw
        $folderKey = $keys['preferred_folder_key'];
        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        foreach ($files['files'] as $f) {
            if (!$f || !$f->isValid()) continue;

            $newName  = $f->getRandomName();
            $original = $f->getClientName(); // ✅ nombre real del usuario
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
        //    - Cambiar estado a "Diseñado" ✅
        //    - Quitar asignación
        //    - Registrar historial
        // ------------------------------------------------------------
        $didUnassign = false;
        $didEstado = false;
        $didHist = false;

        // ✅ estado centralizado (según property)
        $estadoNuevo = $this->estadoProduccion; // 'Diseñado'

        try {
            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');

            $db->transBegin();

            if ($pedidoId) {
                // 3.1) desasignar
                $db->table('pedidos')
                    ->where('id', (int)$pedidoId)
                    ->update([
                        'assigned_to_user_id' => null,
                        'assigned_at' => null,
                    ]);
                $didUnassign = true;

                // 3.2) set estado (order_id = shopify_order_id)
                if ($shopifyOrderId !== '') {
                    $estadoModel = new PedidosEstadoModel();
                    $didEstado = (bool) $estadoModel->setEstadoPedido(
                        (string)$shopifyOrderId,
                        $estadoNuevo,            // ✅ Diseñado
                        $userId ?: null,
                        $userName
                    );

                    // 3.3) historial
                    $okHist = $db->table('pedidos_estado_historial')->insert([
                        'order_id'   => (string)$shopifyOrderId,
                        'estado'     => $estadoNuevo, // ✅ Diseñado
                        'user_id'    => $userId ?: null,
                        'user_name'  => $userName,
                        'created_at' => $now,
                        'pedido_json'=> null,
                    ]);
                    $didHist = (bool)$okHist;
                }
            }

            if ($db->transStatus() === false) {
                $db->transRollback();

                return $this->response->setJSON([
                    'success' => true,
                    'saved' => $saved,
                    'files' => $out,
                    'warning' => 'Archivos subidos, pero falló la transacción de estado/desasignación',
                    'order_id_received' => $orderIdRaw,
                    'pedido_id' => $pedidoId,
                    'shopify_order_id' => $shopifyOrderId,
                ])->setStatusCode(200);
            }

            $db->transCommit();

        } catch (\Throwable $e) {
            log_message('error', 'uploadGeneral post-actions ERROR: ' . $e->getMessage());

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
            'order_id_received' => $orderIdRaw,
            'folder_key' => $folderKey,
            'pedido_id' => $pedidoId,
            'shopify_order_id' => $shopifyOrderId,
            'estado_set' => $didEstado,
            'historial_inserted' => $didHist,
            'unassigned' => $didUnassign,
            'new_estado' => $estadoNuevo, // ✅ Diseñado
        ])->setStatusCode(200);
    }

    // =========================
    // ✅ POST /produccion/upload-modificada
    // =========================
    public function uploadModificada()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderIdRaw = trim((string)($this->request->getPost('order_id') ?? ''));
        $itemIndex  = (string)($this->request->getPost('item_index') ?? '');

        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'order_id requerido',
            ]);
        }
        if ($itemIndex === '' || !preg_match('/^\d+$/', $itemIndex)) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'item_index requerido',
            ]);
        }

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Archivo inválido',
            ]);
        }

        $mime = (string)$file->getClientMimeType();
        if (stripos($mime, 'image/') !== 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Solo se permiten imágenes',
            ]);
        }

        $now = date('Y-m-d H:i:s');

        // carpeta consistente con uploadGeneral
        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedidoId = $keys['pedido_id'];
        $shopifyOrderId = $keys['shopify_order_id'];
        $folderKey = $keys['preferred_folder_key'];

        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        // nombre: mod_{index}_{timestamp}_{random}.{ext}
        $newName = 'mod_' . $itemIndex . '_' . date('Ymd_His') . '_' . $file->getRandomName();
        $original = $file->getClientName();

        $file->move($dir, $newName);

        return $this->response->setJSON([
            'success' => true,
            'url' => site_url("produccion/file/{$folderKey}/{$newName}"),
            'file' => [
                'original_name' => $original,
                'filename' => $newName,
                'mime' => $mime,
                'size' => $file->getSize(),
                'created_at' => $now,
            ],
            'order_id_received' => $orderIdRaw,
            'folder_key' => $folderKey,
            'pedido_id' => $pedidoId,
            'shopify_order_id' => $shopifyOrderId,
        ]);
    }

    // =========================
    // GET /produccion/list-general
    // ✅ FIX: resuelve carpeta correcta (pedidoId vs shopify)
    // =========================
    public function listGeneral()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderIdRaw = trim((string)$this->request->getGet('order_id'));
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'order_id requerido'
            ]);
        }

        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedidoIdStr = $keys['pedido_id'] ? (string)$keys['pedido_id'] : null;
        $shopifyOrderId = $keys['shopify_order_id'] ?: null;

        // ✅ busca una carpeta existente (por compatibilidad con carpetas viejas)
        $folderKey = $this->resolveExistingFolderKey(
            $orderIdRaw,
            $keys['preferred_folder_key'] ?? null,
            $pedidoIdStr,
            $shopifyOrderId
        );

        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) {
            return $this->response->setJSON(['success' => true, 'files' => []]);
        }

        $files = [];
        foreach (scandir($dir) as $name) {
            if ($name === "." || $name === "..") continue;
            $path = $dir . "/" . $name;
            if (!is_file($path)) continue;

            $files[] = [
                // ⚠️ sin BD no hay original_name real aquí, por ahora mostramos el filename
                'original_name' => $name,
                'filename' => $name,
                'mime' => @mime_content_type($path) ?: '',
                'size' => @filesize($path) ?: 0,
                'created_at' => date('Y-m-d H:i:s', filemtime($path)),
                'url' => site_url("produccion/file/{$folderKey}/{$name}")
            ];
        }

        usort($files, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $this->response->setJSON([
            'success' => true,
            'files' => $files,
            'folder_key' => $folderKey,
        ]);
    }
}
