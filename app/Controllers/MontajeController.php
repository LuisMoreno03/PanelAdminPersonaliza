<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class MontajeController extends BaseController
{
    // ✅ Montaje: ENTRADA = Diseñado
    private string $estadoEntrada = 'Diseñado';

    // ✅ Al marcar "Cargado/Realizado": SALIDA = Por producir
    private string $estadoSalida = 'Por producir';

    // ✅ Al presionar "Enviar": SALIDA = Por preparar
    private string $estadoEnviar = 'Por preparar';

    // ✅ Collation "fija" para evitar mezclas
    private string $forceCollation = 'utf8mb4_unicode_ci';

    public function index()
    {
        return view('montaje');
    }

    // =========================================================
    // Helpers Producción -> Montaje (para listar archivos en disco)
    // =========================================================
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

    private function listProduccionFiles(string $orderIdRaw): array
    {
        $keys = $this->resolvePedidoKeys($orderIdRaw);
        $pedidoIdStr = $keys['pedido_id'] ? (string)$keys['pedido_id'] : null;
        $shopifyOrderId = $keys['shopify_order_id'] ?: null;

        // ✅ busca carpeta existente por compatibilidad con carpetas viejas
        $folderKey = $this->resolveExistingFolderKey(
            $orderIdRaw,
            $keys['preferred_folder_key'] ?? null,
            $pedidoIdStr,
            $shopifyOrderId
        );

        $dir = WRITEPATH . "uploads/produccion/" . $folderKey;
        if (!is_dir($dir)) {
            return ['folder_key' => $folderKey, 'files' => []];
        }

        $files = [];
        foreach (scandir($dir) as $name) {
            if ($name === "." || $name === "..") continue;
            $path = $dir . "/" . $name;
            if (!is_file($path)) continue;

            $files[] = [
                // sin BD no hay original_name real aquí; mostramos filename
                'original_name' => $name,
                'filename' => $name,
                'mime' => @mime_content_type($path) ?: '',
                'size' => @filesize($path) ?: 0,
                'created_at' => date('Y-m-d H:i:s', filemtime($path)),
                'url' => site_url("produccion/file/{$folderKey}/{$name}"),
            ];
        }

        usort($files, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return ['folder_key' => $folderKey, 'files' => $files];
    }

    // =========================================================
    // Helper: estado actual normalizado (FORZANDO COLLATION)
    // =========================================================
    private function sqlEstadoActualExpr(): string
    {
        $coll = $this->forceCollation;
        return "LOWER(TRIM(CONVERT(COALESCE(h.estado, pe.estado, '') USING utf8mb4) COLLATE {$coll}))";
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
    // Helper: SELECT de pedidos sin romper si faltan columnas
    // =========================================================
    private function buildSelectPedidoFields(\CodeIgniter\Database\BaseConnection $db): string
    {
        $fields = $db->getFieldNames('pedidos') ?? [];
        $sel = [];

        $sel[] = in_array('id', $fields, true) ? "p.id" : "0 AS id";
        $sel[] = in_array('numero', $fields, true) ? "p.numero" : "CONCAT('#', p.id) AS numero";
        $sel[] = in_array('cliente', $fields, true) ? "p.cliente" : "'' AS cliente";
        $sel[] = in_array('total', $fields, true) ? "p.total" : "'' AS total";
        $sel[] = in_array('created_at', $fields, true) ? "p.created_at" : "NULL AS created_at";

        $sel[] = in_array('shopify_order_id', $fields, true) ? "p.shopify_order_id" : "NULL AS shopify_order_id";
        $sel[] = in_array('assigned_to_user_id', $fields, true) ? "p.assigned_to_user_id" : "NULL AS assigned_to_user_id";
        $sel[] = in_array('assigned_at', $fields, true) ? "p.assigned_at" : "NULL AS assigned_at";

        $sel[] = in_array('forma_envio', $fields, true) ? "p.forma_envio" : "NULL AS forma_envio";
        $sel[] = in_array('etiquetas', $fields, true) ? "p.etiquetas" : "NULL AS etiquetas";
        $sel[] = in_array('articulos', $fields, true) ? "p.articulos" : "NULL AS articulos";

        if (in_array('estado_envio', $fields, true)) {
            $sel[] = "p.estado_envio";
        } elseif (in_array('fulfillment_status', $fields, true)) {
            $sel[] = "p.fulfillment_status AS estado_envio";
        } else {
            $sel[] = "NULL AS estado_envio";
        }

        return implode(",\n                    ", $sel);
    }

    // =========================================================
    // Helper: campos extra (imagenes, archivos, jsons, etc)
    // =========================================================
    private function buildSelectPedidoExtraFields(\CodeIgniter\Database\BaseConnection $db): string
    {
        $fields = $db->getFieldNames('pedidos') ?? [];
        $sel = [];

        $candidates = [
            'pedido_json',
            'nota', 'notas', 'observaciones',

            'imagenes', 'imagenes_json',
            'archivos', 'archivos_json',

            'archivo_diseno', 'archivos_diseno', 'diseno_url', 'diseno_urls',
            'archivo_confirmacion', 'archivos_confirmacion', 'confirmacion_url', 'confirmacion_urls',

            // ✅ nuevos
            'imagenes_locales',
            'product_images',
            'product_image',
        ];

        foreach ($candidates as $col) {
            if (in_array($col, $fields, true)) {
                $sel[] = "p.`{$col}`";
            }
        }

        return $sel ? (",\n                    " . implode(",\n                    ", $sel)) : "";
    }

    // =========================================================
    // Helper: JOIN sin collation conflict (preferimos NUMÉRICO)
    // =========================================================
    private function joinPedidosEstadoSQL(): string
    {
        return "
            LEFT JOIN pedidos_estado pe
                ON (
                    CAST(pe.order_id AS UNSIGNED) = p.id
                    OR CAST(pe.order_id AS UNSIGNED) = p.shopify_order_id
                )
        ";
    }

    private function joinHistorialUltimoSQL(): string
    {
        return "
            LEFT JOIN (
                SELECT h1.order_id, h1.estado, h1.user_name, h1.created_at, h1.pedido_json
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
                OR CAST(h.order_id AS UNSIGNED) = p.shopify_order_id
            )
        ";
    }

    // =========================
    // ✅ GET /montaje/details/{orderKey}
    // Devuelve pedido + historial + archivos en disco de Producción
    // =========================
    public function details($orderIdRaw = null)
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

        $orderIdRaw = trim((string)$orderIdRaw);
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'order_id requerido',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // 1) obtener pedido por id o shopify_order_id
            $pedido = $db->table('pedidos')
                ->select('*')
                ->groupStart()
                    ->where('id', $orderIdRaw)
                    ->orWhere('shopify_order_id', $orderIdRaw)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'ok' => false,
                    'error' => 'Pedido no encontrado',
                ]);
            }

            // ✅ seguridad: solo si está asignado al usuario
            if ((int)($pedido['assigned_to_user_id'] ?? 0) !== $userId) {
                return $this->response->setStatusCode(403)->setJSON([
                    'ok' => false,
                    'error' => 'Este pedido no está asignado a tu usuario',
                ]);
            }

            $pedidoId  = (int)($pedido['id'] ?? 0);
            $shopifyId = trim((string)($pedido['shopify_order_id'] ?? ''));
            $orderKey = ($shopifyId !== '' && $shopifyId !== '0') ? (string)$shopifyId : (string)$pedidoId;

            // 2) historial completo
            $historial = $db->table('pedidos_estado_historial')
                ->select('order_id, estado, user_id, user_name, created_at, pedido_json')
                ->where('order_id', (string)$orderKey)
                ->orderBy('created_at', 'ASC')
                ->get()
                ->getResultArray();

            // 3) ✅ archivos de Producción en disco
            $prod = $this->listProduccionFiles((string)$orderKey);

            return $this->response->setJSON([
                'ok' => true,
                'order_key' => (string)$orderKey,
                'pedido' => $pedido,
                'historial' => $historial ?: [],
                'files_produccion' => $prod['files'] ?? [],
                'folder_key_produccion' => $prod['folder_key'] ?? null,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MontajeController details ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando detalles',
                'debug' => $e->getMessage(),
            ]);
        }
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
            $coll = $this->forceCollation;

            $e1 = mb_strtolower($this->estadoEntrada, 'UTF-8');
            $e2 = 'disenado';

            $selectPedidos = $this->buildSelectPedidoFields($db);
            $selectExtras  = $this->buildSelectPedidoExtraFields($db);

            $rows = $db->query("
                SELECT
                    {$selectPedidos}
                    {$selectExtras},

                    COALESCE(h.estado, pe.estado, 'por preparar') AS estado_bd,
                    COALESCE(h.created_at, pe.estado_updated_at, p.created_at) AS estado_actualizado,
                    COALESCE(h.user_name, pe.estado_updated_by_name) AS estado_por,
                    h.pedido_json AS pedido_json_historial

                FROM pedidos p

                {$this->joinPedidosEstadoSQL()}
                {$this->joinHistorialUltimoSQL()}

                WHERE p.assigned_to_user_id = ?
                  AND (
                        {$estadoExpr} = (? COLLATE {$coll})
                        OR {$estadoExpr} = (? COLLATE {$coll})
                  )
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
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
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
            $coll = $this->forceCollation;

            $e1 = mb_strtolower($this->estadoEntrada, 'UTF-8');
            $e2 = 'disenado';

            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p

                {$this->joinPedidosEstadoSQL()}
                {$this->joinHistorialUltimoSQL()}

                WHERE (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                  AND (
                        {$estadoExpr} = (? COLLATE {$coll})
                        OR {$estadoExpr} = (? COLLATE {$coll})
                  )
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
                $pedidoId = (string)((int)($c['id'] ?? 0));
                $shopifyId = trim((string)($c['shopify_order_id'] ?? ''));

                $orderKey = ($shopifyId !== '' && $shopifyId !== '0') ? (string)$shopifyId : $pedidoId;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$orderKey,
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
    // =========================
    public function realizado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $orderIdRaw = trim((string)($data['order_id'] ?? ''));
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'order_id requerido',
            ]);
        }

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

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
                    'error' => 'Pedido no encontrado',
                ]);
            }

            if ((int)($pedido['assigned_to_user_id'] ?? 0) !== $userId) {
                return $this->response->setStatusCode(403)->setJSON([
                    'ok' => false,
                    'error' => 'Este pedido no está asignado a tu usuario',
                ]);
            }

            $pedidoId  = (int)$pedido['id'];
            $shopifyId = trim((string)($pedido['shopify_order_id'] ?? ''));

            $orderKey = ($shopifyId !== '' && $shopifyId !== '0') ? (string)$shopifyId : (string)$pedidoId;

            $db->transBegin();

            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            $estadoModel = new PedidosEstadoModel();
            $estadoModel->setEstadoPedido(
                (string)$orderKey,
                $this->estadoSalida,
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
                    'error' => 'Falló la transacción',
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

    // alias
    public function cargado()
    {
        return $this->realizado();
    }

    // =========================
    // POST /montaje/enviar
    // =========================
    public function enviar()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $orderIdRaw = trim((string)($data['order_id'] ?? ''));
        if ($orderIdRaw === '' || $orderIdRaw === '0') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'error' => 'order_id requerido',
            ]);
        }

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

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
                    'error' => 'Pedido no encontrado',
                ]);
            }

            if ((int)($pedido['assigned_to_user_id'] ?? 0) !== $userId) {
                return $this->response->setStatusCode(403)->setJSON([
                    'ok' => false,
                    'error' => 'Este pedido no está asignado a tu usuario',
                ]);
            }

            $pedidoId  = (int)$pedido['id'];
            $shopifyId = trim((string)($pedido['shopify_order_id'] ?? ''));

            $orderKey = ($shopifyId !== '' && $shopifyId !== '0') ? (string)$shopifyId : (string)$pedidoId;

            $db->transBegin();

            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            $estadoModel = new PedidosEstadoModel();
            $estadoModel->setEstadoPedido(
                (string)$orderKey,
                $this->estadoEnviar,
                $userId ?: null,
                $userName
            );

            $db->table('pedidos_estado_historial')->insert([
                'order_id'   => (string)$orderKey,
                'estado'     => $this->estadoEnviar,
                'user_id'    => $userId,
                'user_name'  => $userName,
                'created_at' => $now,
                'pedido_json'=> null,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'Falló la transacción',
                ]);
            }

            $db->transCommit();

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Enviado → Por preparar',
                'new_estado' => $this->estadoEnviar,
                'order_key' => $orderKey,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MontajeController enviar ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno enviando pedido',
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

            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Pedidos devueltos',
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
