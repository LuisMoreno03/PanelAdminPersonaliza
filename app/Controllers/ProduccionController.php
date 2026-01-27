<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class ProduccionController extends BaseController
{
    /**
     * Estado desde el que se hace Pull (cola inicial)
     * En tu vista dice: "Pull desde Confirmado"
     */
    private string $estadoEntrada = 'Confirmado';

    /**
     * Estado al que pasa el pedido cuando subes archivos diseñados
     * ✅ Si quieres que pase a "Por producir", cambia aquí:
     * private string $estadoProduccion = 'Por producir';
     */
    private string $estadoProduccion = 'Diseñado';

    public function index()
    {
        return view('produccion');
    }

    // =========================
    // Helpers (resolver pedido/carpeta)
    // =========================

    private function sanitizeFolderKey(string $key): string
    {
        $key = trim($key);
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
        return $key !== '' ? $key : '0';
    }

    private function resolvePedidoKeys(string $orderIdRaw): array
    {
        $orderIdRaw = trim($orderIdRaw);
        $db = \Config\Database::connect();

        $pedido = $db->table('pedidos')
            ->select('id, numero, shopify_order_id, assigned_to_user_id')
            ->groupStart()
                ->where('id', $orderIdRaw)
                ->orWhere('shopify_order_id', $orderIdRaw)
                ->orWhere('numero', $orderIdRaw)
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

        // Carpeta por numero -> id -> raw
        $pedidoNumero = $pedido['numero'] ?? null;
        $preferredFolderKeyRaw = $pedidoNumero
            ? (string)$pedidoNumero
            : ($pedidoId ? (string)$pedidoId : $orderIdRaw);

        $preferredFolderKey = $this->sanitizeFolderKey($preferredFolderKeyRaw);

        return [
            'pedido' => $pedido,
            'pedido_id' => $pedidoId,
            'pedido_numero' => $pedidoNumero ? (string)$pedidoNumero : null,
            'shopify_order_id' => $shopifyOrderId,
            'preferred_folder_key' => $preferredFolderKey,
        ];
    }

    private function resolveExistingFolderKey(
        string $orderIdRaw,
        ?string $preferredFolderKey,
        ?string $pedidoIdStr,
        ?string $shopifyOrderId,
        ?string $pedidoNumero = null
    ): string {
        $orderIdRaw = trim((string)$orderIdRaw);
        $candidates = [];

        if ($pedidoNumero) $candidates[] = $this->sanitizeFolderKey((string)$pedidoNumero);
        if ($preferredFolderKey) $candidates[] = $this->sanitizeFolderKey((string)$preferredFolderKey);
        if ($pedidoIdStr) $candidates[] = $this->sanitizeFolderKey((string)$pedidoIdStr);
        if ($orderIdRaw !== '') $candidates[] = $this->sanitizeFolderKey((string)$orderIdRaw);
        if ($shopifyOrderId) $candidates[] = $this->sanitizeFolderKey((string)$shopifyOrderId);

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

        $fallback = $preferredFolderKey ?: ($orderIdRaw ?: '0');
        return $this->sanitizeFolderKey($fallback);
    }

    // =========================
    // GET /produccion/my-queue
    // =========================
    public function myQueue()
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

            // Detectar columnas reales
            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

            // Condición NO ENVIADOS
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
            $estadoEntradaLower = strtolower(trim($this->estadoEntrada));

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
                ON (
                    CAST(pe.order_id AS UNSIGNED) = p.id
                    OR (
                        p.shopify_order_id IS NOT NULL
                        AND p.shopify_order_id <> 0
                        AND CAST(pe.order_id AS UNSIGNED) = p.shopify_order_id
                    )
                )

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
                    CAST(h.order_id AS UNSIGNED) = p.id
                    OR (
                        p.shopify_order_id IS NOT NULL
                        AND p.shopify_order_id <> 0
                        AND CAST(h.order_id AS UNSIGNED) = p.shopify_order_id
                    )
                )

                WHERE p.assigned_to_user_id = ?
                AND LOWER(TRIM(
                    CAST(COALESCE(h.estado, pe.estado, '') AS CHAR) COLLATE {$coll}
                )) = ('{$estadoEntradaLower}' COLLATE {$coll})
                {$condNoEnviados}

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC
            ", [$userId])->getResultArray();

            return $this->response->setJSON(['ok' => true, 'data' => $rows ?: []]);

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

            // Detectar columnas reales
            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

            // Condición NO ENVIADOS
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
            $estadoEntradaLower = strtolower(trim($this->estadoEntrada));

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
                ) h
                ON (
                    CAST(h.order_id AS UNSIGNED) = p.id
                    OR (
                        p.shopify_order_id IS NOT NULL
                        AND p.shopify_order_id <> 0
                        AND CAST(h.order_id AS UNSIGNED) = p.shopify_order_id
                    )
                )

                WHERE LOWER(TRIM(CAST(h.estado AS CHAR) COLLATE {$coll}))
                    = ('{$estadoEntradaLower}' COLLATE {$coll})
                {$condNoEnviados}
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)

                ORDER BY h.created_at ASC, p.id ASC
                LIMIT {$count}
            ")->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => "No hay pedidos disponibles para asignar (no enviados + {$this->estadoEntrada})",
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

            // Opcional: registrar evento de asignación en historial (mismo estado)
            foreach ($candidatos as $c) {
                $shopifyId = trim((string)($c['shopify_order_id'] ?? ''));
                if ($shopifyId === '' || $shopifyId === '0') continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$shopifyId,
                    'estado'     => $this->estadoEntrada,
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
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'order_id requerido',
            ]);
        }

        $files = $this->request->getFiles();
        if (!isset($files['files'])) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Sin archivos',
            ]);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // 1) Resolver pedido / ids
        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedidoId = $keys['pedido_id'];
        $shopifyOrderId = $keys['shopify_order_id'];

        // 2) Guardar archivos en carpeta por NUMERO
        $saved = 0;
        $out = [];

        $folderKey = $keys['preferred_folder_key'];
        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $incoming = $files['files'];
        if (!is_array($incoming)) $incoming = [$incoming];

        foreach ($incoming as $f) {
            if (!$f || !$f->isValid()) continue;

            $newName  = $f->getRandomName();
            $original = $f->getClientName();
            $mime     = (string)$f->getClientMimeType();

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

        // 3) Post-upload: desasignar + set estado + historial
        $didUnassign = false;
        $didEstado = false;
        $didHist = false;

        $estadoNuevo = $this->estadoProduccion;

        try {
            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');

            $db->transBegin();

            if ($pedidoId) {
                $db->table('pedidos')
                    ->where('id', (int)$pedidoId)
                    ->update([
                        'assigned_to_user_id' => null,
                        'assigned_at' => null,
                    ]);
                $didUnassign = true;
            }

            $orderIdToSave = $shopifyOrderId ?: ($pedidoId ? (string)$pedidoId : $orderIdRaw);

            if ($orderIdToSave !== '') {
                $estadoModel = new PedidosEstadoModel();
                $didEstado = (bool)$estadoModel->setEstadoPedido(
                    (string)$orderIdToSave,
                    $estadoNuevo,
                    $userId ?: null,
                    $userName
                );

                $okHist = $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$orderIdToSave,
                    'estado'     => $estadoNuevo,
                    'user_id'    => $userId ?: null,
                    'user_name'  => $userName,
                    'created_at' => $now,
                    'pedido_json'=> null,
                ]);
                $didHist = (bool)$okHist;
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
                    'folder_key' => $folderKey,
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
                'folder_key' => $folderKey,
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
            'new_estado' => $estadoNuevo,
        ])->setStatusCode(200);
    }

    // =========================
    // POST /produccion/set-estado
    // (fallback del JS)
    // =========================
    public function setEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $orderIdRaw = trim((string)($data['order_id'] ?? ''));
        $estado = trim((string)($data['estado'] ?? ''));

        if ($orderIdRaw === '' || $orderIdRaw === '0' || $estado === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'order_id y estado requeridos',
            ]);
        }

        try {
            $keys = $this->resolvePedidoKeys($orderIdRaw);
            $pedidoId = $keys['pedido_id'];
            $shopifyOrderId = $keys['shopify_order_id'];

            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');
            $now = date('Y-m-d H:i:s');

            $orderIdToSave = $shopifyOrderId ?: ($pedidoId ? (string)$pedidoId : $orderIdRaw);

            $db = \Config\Database::connect();
            $db->transBegin();

            $estadoModel = new PedidosEstadoModel();
            $okEstado = (bool)$estadoModel->setEstadoPedido(
                (string)$orderIdToSave,
                $estado,
                $userId ?: null,
                $userName
            );

            $okHist = $db->table('pedidos_estado_historial')->insert([
                'order_id'   => (string)$orderIdToSave,
                'estado'     => $estado,
                'user_id'    => $userId ?: null,
                'user_name'  => $userName,
                'created_at' => $now,
                'pedido_json'=> null,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Falló transacción set-estado',
                ])->setStatusCode(200);
            }

            $db->transCommit();

            return $this->response->setJSON([
                'success' => true,
                'estado_set' => $okEstado,
                'historial_inserted' => (bool)$okHist,
                'order_id_saved' => (string)$orderIdToSave,
            ])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController setEstado ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno seteando estado',
                'debug' => $e->getMessage(),
            ])->setStatusCode(200);
        }
    }

    // =========================
    // GET /produccion/file/{folderKey}/{filename}
    // (para abrir/preview)
    // =========================
    public function file(string $folderKey = '', string $filename = '')
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setBody('No autenticado');
        }

        $folderKey = $this->sanitizeFolderKey($folderKey);
        $filename = basename((string)$filename);

        if ($folderKey === '' || $folderKey === '0' || $filename === '') {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $path = WRITEPATH . "uploads/produccion/" . $folderKey . "/" . $filename;
        if (!is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $mime = @mime_content_type($path) ?: 'application/octet-stream';
        $inline = (stripos($mime, 'image/') === 0 || stripos($mime, 'pdf') !== false) ? 'inline' : 'attachment';

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', $inline . '; filename="' . $filename . '"')
            ->setBody(file_get_contents($path));
    }

    // =========================
    // GET /produccion/list-general
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
        $pedidoNumero = $keys['pedido_numero'] ?? null;

        $folderKey = $this->resolveExistingFolderKey(
            $orderIdRaw,
            $keys['preferred_folder_key'] ?? null,
            $pedidoIdStr,
            $shopifyOrderId,
            $pedidoNumero
        );

        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) {
            return $this->response->setJSON(['success' => true, 'files' => [], 'folder_key' => $folderKey]);
        }

        $files = [];
        foreach (scandir($dir) as $name) {
            if ($name === "." || $name === "..") continue;
            $path = $dir . "/" . $name;
            if (!is_file($path)) continue;

            $files[] = [
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
