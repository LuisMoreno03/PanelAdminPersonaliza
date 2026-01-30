<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class ProduccionController extends BaseController
{
    private string $estadoEntrada    = 'Confirmado';
    private string $estadoProduccion = 'Diseñado'; // cambia aquí si quieres "Por producir"

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

        // carpeta por NUMERO, si no existe -> id -> raw
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

    private function json401()
    {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'success' => false,
            'error' => 'No autenticado',
            'message' => 'No autenticado',
        ]);
    }

    private function isBadOrderId(string $s): bool
    {
        $t = strtolower(trim($s));
        return $t === '' || $t === '0' || $t === 'null' || $t === 'undefined';
    }

    /**
     * ✅ Detecta el caso típico: el archivo excede límites (post_max_size/upload_max_filesize)
     * y PHP llega con $_POST vacío / sin files.
     */
    private function payloadTooLargeLikely(): bool
    {
        // Si el body es grande y aun así no hay variables, suele ser límite de PHP/Nginx
        $cl = (int)($this->request->getHeaderLine('Content-Length') ?: 0);

        // Si Content-Length existe y es > 0, pero CI4 no ve nada en post/var, sospechamos.
        $orderIdPost = trim((string)($this->request->getPost('order_id') ?? ''));
        $orderIdVar  = trim((string)($this->request->getVar('order_id') ?? ''));

        if ($cl > 0 && $orderIdPost === '' && $orderIdVar === '') {
            // También check rápido de files: si no hay, refuerza la sospecha
            $f1 = $this->request->getFileMultiple('files');
            $f2 = $this->request->getFileMultiple('files[]');
            $hasFiles = (is_array($f1) && count($f1)) || (is_array($f2) && count($f2));
            if (!$hasFiles) return true;
        }

        return false;
    }

    /**
     * ✅ SQL para priorizar pedidos con envío express
     * Detecta por forma_envio o etiquetas (incluye "express/expres/exprés").
     */
    private function expressPrioritySql(string $alias = 'p'): string
    {
        $a = $alias;

        return "CASE WHEN (
            LOWER(COALESCE({$a}.forma_envio,'')) LIKE '%express%'
            OR LOWER(COALESCE({$a}.forma_envio,'')) LIKE '%expres%'
            OR LOWER(COALESCE({$a}.forma_envio,'')) LIKE '%exprés%'
            OR LOWER(COALESCE({$a}.etiquetas,''))  LIKE '%express%'
            OR LOWER(COALESCE({$a}.etiquetas,''))  LIKE '%expres%'
            OR LOWER(COALESCE({$a}.etiquetas,''))  LIKE '%exprés%'
        ) THEN 1 ELSE 0 END";
    }

    // =========================
    // GET /produccion/my-queue
    // =========================
    public function myQueue()
    {
        if (!session()->get('logged_in')) return $this->json401();

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);
        }

        try {
            $db = \Config\Database::connect();

            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

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
            $estadoEntradaLower = mb_strtolower(trim($this->estadoEntrada));

            $expressSql = $this->expressPrioritySql('p');

            $rows = $db->query("
                SELECT
                    p.id,
                    p.numero,
                    p.cliente,
                    p.total,
                    p.estado_envio,
                    p.forma_envio,
                    {$expressSql} AS is_express,
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
                )) = (? COLLATE {$coll})
                {$condNoEnviados}

                ORDER BY is_express DESC, COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC
            ", [$userId, $estadoEntradaLower])->getResultArray();

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
        if (!session()->get('logged_in')) return $this->json401();

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int)($data['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $fields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $fields, true);
            $hasFulfillment = in_array('fulfillment_status', $fields, true);

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
            $estadoEntradaLower = mb_strtolower(trim($this->estadoEntrada));
            $expressSql = $this->expressPrioritySql('p');

            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id,
                    {$expressSql} AS is_express
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

                WHERE LOWER(TRIM(CAST(h.estado AS CHAR) COLLATE {$coll})) = (? COLLATE {$coll})
                {$condNoEnviados}
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)

                ORDER BY is_express DESC, h.created_at ASC, p.id ASC
                LIMIT {$count}
            ", [$estadoEntradaLower])->getResultArray();

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
        if (!session()->get('logged_in')) return $this->json401();

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);

        try {
            $db = \Config\Database::connect();

            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);

            return $this->response->setJSON(['ok' => true, 'message' => 'Pedidos devueltos']);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'error' => 'Error interno devolviendo pedidos']);
        }
    }
    public function returnOne()
{
    // Lee JSON o POST
    $payload = $this->request->getJSON(true);
    if (!is_array($payload)) {
        $payload = $this->request->getPost() ?? [];
    }

    $internalId = trim((string)($payload['id'] ?? $payload['order_id'] ?? ''));
    $shopifyId  = trim((string)($payload['shopify_order_id'] ?? $payload['shopifyId'] ?? ''));

    if ($internalId === '' && $shopifyId === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'success' => false,
            'message' => 'id/order_id requerido',
        ]);
    }

    $db = \Config\Database::connect();

    // ✅ Cambia si tu tabla tiene otro nombre
    $tableName = 'pedidos';

    // Si tu tabla no existe, responde claro
    if (!$db->tableExists($tableName)) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => "La tabla '{$tableName}' no existe. Ajusta \$tableName en ProduccionController::returnOne()",
        ]);
    }

    // Detecta campo de asignación (ajusta si tienes otro)
    $assignCandidates = [
        'produccion_user_id',
        'assigned_to',
        'asignado_a',
        'usuario_id',
        'user_id',
    ];

    $assignField = null;
    foreach ($assignCandidates as $f) {
        if ($db->fieldExists($f, $tableName)) { $assignField = $f; break; }
    }

    if (!$assignField) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => "No encontré un campo de asignación en '{$tableName}'. Agrega tu campo a \$assignCandidates.",
        ]);
    }

    // Usuario actual (según tu auth)
    $session = session();
    $userId =
        $session->get('user_id') ??
        $session->get('id') ??
        $session->get('usuario_id') ??
        null;

    $builder = $db->table($tableName);

    // Buscar pedido por Shopify ID o por ID interno (varios nombres por compatibilidad)
    $builder->groupStart();
        if ($shopifyId !== '') {
            // intenta por shopify_order_id si existe, si no, igual hace where y no explota
            $builder->orWhere('shopify_order_id', $shopifyId);
            $builder->orWhere('order_id', $shopifyId);
        }
        if ($internalId !== '') {
            $builder->orWhere('id', $internalId);
            $builder->orWhere('pedido_id', $internalId);
            $builder->orWhere('order_id', $internalId);
        }
    $builder->groupEnd();

    // Asegura que SOLO devuelva pedidos asignados a este usuario (si hay userId)
    if ($userId !== null) {
        $builder->where($assignField, $userId);
    }

    $update = [
        $assignField => null, // desasignar
    ];

    // si tienes updated_at
    if ($db->fieldExists('updated_at', $tableName)) {
        $update['updated_at'] = date('Y-m-d H:i:s');
    }

    $ok = $builder->update($update);

    if (!$ok) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'No se pudo actualizar el pedido (update failed).',
        ]);
    }

    if ($db->affectedRows() <= 0) {
        return $this->response->setStatusCode(404)->setJSON([
            'success' => false,
            'message' => 'No se encontró el pedido o no estaba asignado a tu usuario.',
        ]);
    }

    return $this->response->setJSON([
        'success' => true,
        'ok' => true,
        'message' => 'Pedido devuelto correctamente.',
    ]);
}

    // =========================
    // POST /produccion/upload-general
    // =========================
    public function uploadGeneral()
    {
        if (!session()->get('logged_in')) return $this->json401();

        // ✅ Si el body fue rechazado por tamaño, CI4 no verá order_id ni files.
        if ($this->payloadTooLargeLikely()) {
            return $this->response->setStatusCode(413)->setJSON([
                'success' => false,
                'message' => 'El archivo (o conjunto de archivos) excede el límite permitido del servidor (post_max_size / upload_max_filesize / client_max_body_size).',
            ]);
        }

        $orderIdRaw = trim((string)($this->request->getPost('order_id') ?? $this->request->getVar('order_id') ?? ''));
        if ($this->isBadOrderId($orderIdRaw)) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'order_id requerido',
            ]);
        }

        // ✅ robusto con name="files" o name="files[]"
        $uploaded = $this->request->getFileMultiple('files');
        if (!$uploaded || !is_array($uploaded) || count($uploaded) === 0) {
            $uploaded = $this->request->getFileMultiple('files[]');
        }

        if (!$uploaded || !is_array($uploaded) || count($uploaded) === 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Sin archivos',
            ]);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedidoId = $keys['pedido_id'];
        $shopifyOrderId = $keys['shopify_order_id'];

        $saved = 0;
        $out = [];

        $folderKey = $keys['preferred_folder_key'];
        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        // ✅ Detectar si subieron Illustrator
        $hasIllustrator = false;

        foreach ($uploaded as $f) {
            if (!$f || !$f->isValid()) continue;

            $newName  = $f->getRandomName();
            $original = $f->getClientName();
            $mime     = $f->getClientMimeType();

            // Detecta .ai por extensión o mimes típicos
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $mimeLow = strtolower((string)$mime);

            if ($ext === 'ai') $hasIllustrator = true;

            if (in_array($mimeLow, [
                'application/illustrator',
                'application/postscript',
                'application/x-illustrator',
                'application/ai',
            ], true)) {
                $hasIllustrator = true;
            }

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
            ]);
        }

        // ✅ Solo cuando se sube Illustrator: desasignar + pasar a Diseñado
        $estadoNuevo = $this->estadoProduccion;
        $didUnassign = false;
        $didEstado = false;
        $didHist = false;

        try {
            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');

            if ($hasIllustrator) {
                $db->transBegin();

                // ✅ Desasignar robusto aunque no resuelva $pedidoId (por id / shopify_order_id / numero)
                $builder = $db->table('pedidos');
                $builder->groupStart();

                $added = false;

                if (!empty($pedidoId)) {
                    $builder->where('id', (int)$pedidoId);
                    $added = true;
                }
                if (!empty($shopifyOrderId)) {
                    $added ? $builder->orWhere('shopify_order_id', $shopifyOrderId)
                           : $builder->where('shopify_order_id', $shopifyOrderId);
                    $added = true;
                }
                if (!$this->isBadOrderId($orderIdRaw)) {
                    $added ? $builder->orWhere('numero', $orderIdRaw)
                           : $builder->where('numero', $orderIdRaw);
                    $added = true;
                }

                $builder->groupEnd();

                if ($added) {
                    $builder->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
                    $didUnassign = ($db->affectedRows() > 0);
                }

                // ✅ Estado -> Diseñado + historial
                if ($shopifyOrderId !== '') {
                    $estadoModel = new PedidosEstadoModel();
                    $didEstado = (bool)$estadoModel->setEstadoPedido(
                        (string)$shopifyOrderId,
                        $estadoNuevo,
                        $userId ?: null,
                        $userName
                    );

                    $okHist = $db->table('pedidos_estado_historial')->insert([
                        'order_id'   => (string)$shopifyOrderId,
                        'estado'     => $estadoNuevo,
                        'user_id'    => $userId ?: null,
                        'user_name'  => $userName,
                        'created_at' => $now,
                        'pedido_json'=> null,
                    ]);
                    $didHist = (bool)$okHist;
                }

                if ($db->transStatus() === false || ($shopifyOrderId !== '' && (!$didEstado || !$didHist))) {
                    $db->transRollback();
                    return $this->response->setJSON([
                        'success' => true,
                        'saved' => $saved,
                        'files' => $out,
                        'warning' => 'Archivos subidos, pero falló la transacción de estado/desasignación',
                        'order_id_received' => $orderIdRaw,
                        'pedido_id' => $pedidoId,
                        'shopify_order_id' => $shopifyOrderId,
                    ]);
                }

                $db->transCommit();
            }

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
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'saved' => $saved,
            'files' => $out,
            'order_id_received' => $orderIdRaw,
            'folder_key' => $folderKey,
            'pedido_id' => $pedidoId,
            'shopify_order_id' => $shopifyOrderId,

            // ✅ flags de automatización (solo cuando suben .ai)
            'uploaded_ai' => $hasIllustrator,
            'should_remove_from_queue' => $hasIllustrator,

            'estado_set' => $didEstado,
            'historial_inserted' => $didHist,
            'unassigned' => $didUnassign,
            'new_estado' => $hasIllustrator ? $estadoNuevo : null,
        ]);
    }

    // =========================
    // POST /produccion/upload-modificada
    // =========================
    public function uploadModificada()
    {
        if (!session()->get('logged_in')) return $this->json401();

        $orderIdRaw = trim((string)($this->request->getPost('order_id') ?? $this->request->getVar('order_id') ?? ''));
        $itemIndex  = (string)($this->request->getPost('item_index') ?? $this->request->getVar('item_index') ?? '');

        if ($this->isBadOrderId($orderIdRaw)) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'order_id requerido']);
        }
        if ($itemIndex === '' || !preg_match('/^\d+$/', $itemIndex)) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'item_index requerido']);
        }

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Archivo inválido']);
        }

        $mime = (string)$file->getClientMimeType();
        if (stripos($mime, 'image/') !== 0) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Solo se permiten imágenes']);
        }

        $now = date('Y-m-d H:i:s');

        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedidoId = $keys['pedido_id'];
        $shopifyOrderId = $keys['shopify_order_id'];
        $folderKey = $keys['preferred_folder_key'];

        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

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
    // =========================
    public function listGeneral()
    {
        if (!session()->get('logged_in')) return $this->json401();

        $orderIdRaw = trim((string)$this->request->getGet('order_id'));
        if ($this->isBadOrderId($orderIdRaw)) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'order_id requerido']);
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
                'url' => site_url("produccion/file/{$folderKey}/{$name}"),
            ];
        }

        usort($files, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $this->response->setJSON([
            'success' => true,
            'files' => $files,
            'folder_key' => $folderKey,
        ]);
    }

    // =========================
    // POST /produccion/set-estado
    // =========================
    public function setEstado()
    {
        if (!session()->get('logged_in')) return $this->json401();

        $body = $this->request->getJSON(true);
        if (!is_array($body)) $body = [];

        $orderIdRaw = trim((string)($body['order_id'] ?? $body['orderId'] ?? ''));
        $estado = trim((string)($body['estado'] ?? ''));

        if ($this->isBadOrderId($orderIdRaw)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'success' => false, 'message' => 'order_id requerido']);
        }
        if ($estado === '') {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'success' => false, 'message' => 'estado requerido']);
        }

        try {
            $keys = $this->resolvePedidoKeys($orderIdRaw);
            $shopifyOrderId = trim((string)($keys['shopify_order_id'] ?? ''));

            if ($shopifyOrderId === '') {
                return $this->response->setStatusCode(404)->setJSON([
                    'ok' => false,
                    'success' => false,
                    'message' => 'No se pudo resolver shopify_order_id para ese pedido',
                ]);
            }

            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');
            $now = date('Y-m-d H:i:s');

            $db = \Config\Database::connect();
            $db->transBegin();

            $estadoModel = new PedidosEstadoModel();
            $ok = (bool)$estadoModel->setEstadoPedido(
                (string)$shopifyOrderId,
                $estado,
                $userId ?: null,
                $userName
            );

            $db->table('pedidos_estado_historial')->insert([
                'order_id'   => (string)$shopifyOrderId,
                'estado'     => $estado,
                'user_id'    => $userId ?: null,
                'user_name'  => $userName,
                'created_at' => $now,
                'pedido_json'=> null,
            ]);

            if ($db->transStatus() === false || !$ok) {
                $db->transRollback();
                return $this->response->setJSON(['ok' => false, 'success' => false, 'message' => 'No se pudo actualizar estado']);
            }

            $db->transCommit();
            return $this->response->setJSON(['ok' => true, 'success' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController setEstado ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'success' => false, 'message' => 'Error interno', 'debug' => $e->getMessage()]);
        }
    }

    // =========================
    // GET /produccion/file/{folder}/{name}
    // =========================
    public function file(string $folder, string $name)
    {
        if (!session()->get('logged_in')) return $this->json401();

        $folder = $this->sanitizeFolderKey($folder);
        $name = basename($name);

        $path = WRITEPATH . "uploads/produccion/{$folder}/{$name}";
        if (!is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $mime = @mime_content_type($path) ?: 'application/octet-stream';
        $inline = preg_match('/^(image\/|application\/pdf|text\/|application\/svg\+xml)/i', $mime) === 1;

        $this->response->setHeader('Content-Type', $mime);
        $this->response->setHeader('Content-Length', (string)filesize($path));
        $this->response->setHeader(
            'Content-Disposition',
            ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($name) . '"'
        );

        return $this->response->setBody(file_get_contents($path));
    }
}
