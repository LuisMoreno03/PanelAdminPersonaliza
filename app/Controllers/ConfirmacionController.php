<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion');
    }

    private function normalizeShopifyOrderId($id): string
    {
        $s = trim((string)$id);
        if ($s === '') return '';
        if (preg_match('~/Order/(\d+)~i', $s, $m)) return (string)$m[1];
        return $s;
    }

    private function orderKeyFromPedido(array $pedido): string
    {
        $sid = trim((string)($pedido['shopify_order_id'] ?? ''));
        if ($sid !== '' && $sid !== '0') return $sid;
        return (string)($pedido['id'] ?? '');
    }

    /* =====================================================
      GET /confirmacion/my-queue
    ===================================================== */
    public function myQueue()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'No auth']);
            }

            $userId = (int) session('user_id');
            if ($userId <= 0) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'User inválido']);
            }

            $db = \Config\Database::connect();

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId  = in_array('shopify_order_id', $pFields, true);
            $hasPedidoJson = in_array('pedido_json', $pFields, true);

            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);
            $hasPeUserName  = in_array('user_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            $driver = strtolower((string)($db->DBDriver ?? ''));

            if ($hasShopifyId) {
                if (str_contains($driver, 'mysql')) {
                    $orderKeySql = "COALESCE(NULLIF(TRIM(p.shopify_order_id),''), CONCAT(p.id,''))";
                } else {
                    $orderKeySql = "COALESCE(NULLIF(TRIM(CAST(p.shopify_order_id AS TEXT)),''), CAST(p.id AS TEXT))";
                }
            } else {
                $orderKeySql = str_contains($driver, 'mysql')
                    ? "CONCAT(p.id,'')"
                    : "CAST(p.id AS TEXT)";
            }

            $q = $db->table('pedidos p')
                ->select(
                    "p.id, " .
                    ($hasShopifyId ? "p.shopify_order_id, " : "NULL as shopify_order_id, ") .
                    ($hasPedidoJson ? "p.pedido_json, " : "NULL as pedido_json, ") .
                    "p.numero, p.cliente, p.total, p.estado_envio, p.forma_envio, p.etiquetas, p.articulos, p.created_at, " .
                    "COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect",
                    false
                )
                ->join(
                    'pedidos_estado pe',
                    "pe.order_id COLLATE utf8mb4_unicode_ci = ($orderKeySql) COLLATE utf8mb4_unicode_ci",
                    'left',
                    false
                )
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por preparar','faltan archivos')", null, false)
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();

            $this->applyEtiquetaExclusions($q, $db);
            $this->applyPedidoJsonExclusions($q, $db, $hasPedidoJson);

            $rows = $q->orderBy('p.created_at', 'ASC')->get()->getResultArray();

            if ($hasPedidoJson && $rows) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return !$this->isCancelledFromPedidoJson($r['pedido_json'] ?? null);
                }));
                foreach ($rows as &$r) { unset($r['pedido_json']); }
                unset($r);
            }

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /* =====================================================
      POST /confirmacion/pull
    ===================================================== */
    public function pull()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'No auth']);
            }

            $userId = (int) session('user_id');
            $user   = session('nombre') ?? 'Sistema';

            $payload = $this->request->getJSON(true) ?? [];
            $count = (int)($payload['count'] ?? 5);
            $count = in_array($count, [5, 10], true) ? $count : 5;

            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId  = in_array('shopify_order_id', $pFields, true);
            $hasPedidoJson = in_array('pedido_json', $pFields, true);

            if (!$hasShopifyId) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => 'La tabla pedidos no tiene la columna shopify_order_id.'
                ]);
            }

            $db->transStart();

            $limitFetch = max($count * 8, $count);
            $select = 'p.id, p.shopify_order_id, p.etiquetas' . ($hasPedidoJson ? ', p.pedido_json' : '');

            $candQuery = $db->table('pedidos p')
                ->select($select, false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where("LOWER(TRIM(COALESCE(pe.estado,'por preparar')))", 'por preparar')
                ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)')
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();

            $this->applyEtiquetaExclusions($candQuery, $db);
            $this->applyPedidoJsonExclusions($candQuery, $db, $hasPedidoJson);

            $candidatosRaw = $candQuery
                ->orderBy('p.created_at', 'ASC')
                ->limit($limitFetch)
                ->get()
                ->getResultArray();

            if (!$candidatosRaw) {
                $db->transComplete();
                return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos']);
            }

            $candidatos = $candidatosRaw;

            if ($hasPedidoJson) {
                $candidatos = array_values(array_filter($candidatos, function ($r) {
                    return !$this->isCancelledFromPedidoJson($r['pedido_json'] ?? null);
                }));
            }

            $candidatos = array_slice($candidatos, 0, $count);

            if (!$candidatos) {
                $db->transComplete();
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0,
                    'message' => 'Sin candidatos (filtrados por cancelación/etiquetas)'
                ]);
            }

            $ids = array_column($candidatos, 'id');

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update(['assigned_to_user_id' => $userId, 'assigned_at' => $now]);

            foreach ($candidatos as $c) {
                $orderKey = trim((string)($c['shopify_order_id'] ?? ''));
                if ($orderKey === '') $orderKey = (string)($c['id'] ?? '');
                if ($orderKey === '') continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => $orderKey,
                    'estado'     => 'Por preparar',
                    'user_name'  => $user,
                    'created_at' => $now
                ]);

                $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();
                if (!$existe) {
                    $db->table('pedidos_estado')->insert([
                        'order_id' => $orderKey,
                        'estado' => 'Por preparar',
                        'estado_updated_at' => $now,
                        'estado_updated_by_name' => $user
                    ]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'Transacción falló']);
            }

            return $this->response->setJSON(['ok' => true, 'assigned' => count($ids)]);
        } catch (\Throwable $e) {
            log_message('error', 'pull() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
        }

        $userId = (int) session('user_id');
        if ($userId <= 0) return $this->response->setJSON(['ok' => false]);

        \Config\Database::connect()
            ->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);

        return $this->response->setJSON(['ok' => true]);
    }

    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }
        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'ID inválido']);
        }

        try {
            $idNorm = $this->normalizeShopifyOrderId($id);

            $db = \Config\Database::connect();
            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasPedidoJson = in_array('pedido_json', $pFields, true);
            $hasImgLocales = in_array('imagenes_locales', $pFields, true);
            $hasProdImages = in_array('product_images', $pFields, true);

            $pedido = $db->table('pedidos')
                ->groupStart()
                    ->where('id', $idNorm)
                    ->orWhere('shopify_order_id', $idNorm)
                    ->orWhere('shopify_order_id', (string)$id)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);
            }

            $shopifyId = $pedido['shopify_order_id'] ?? null;

            $orderJson = null;
            if ($hasPedidoJson) {
                $orderJson = json_decode($pedido['pedido_json'] ?? '', true);
            }

            if (!$orderJson || (empty($orderJson['line_items']) && empty($orderJson['lineItems']))) {
                $orderJson = [
                    'id' => $shopifyId ?: ($pedido['id'] ?? null),
                    'name' => $pedido['numero'] ?? ('#'.($shopifyId ?: $pedido['id'])),
                    'created_at' => $pedido['created_at'] ?? null,
                    'customer' => ['first_name' => $pedido['cliente'] ?? '', 'last_name' => ''],
                    'line_items' => [],
                ];
            }

            $imagenesLocales = $hasImgLocales ? json_decode($pedido['imagenes_locales'] ?? '{}', true) : [];
            $productImages   = $hasProdImages ? json_decode($pedido['product_images'] ?? '{}', true) : [];

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
                'product_images' => is_array($productImages) ? $productImages : [],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'detalles() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function subirImagen()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false]);
        }

        $orderIdRaw = (string)$this->request->getPost('order_id');
        $orderId = $this->normalizeShopifyOrderId($orderIdRaw);

        $index = (int) $this->request->getPost('line_index');
        $file  = $this->request->getFile('file');

        $modifiedBy = trim((string) $this->request->getPost('modified_by'));
        $modifiedAt = trim((string) $this->request->getPost('modified_at'));
        if ($modifiedBy === '') $modifiedBy = session('nombre') ?? 'Sistema';
        if ($modifiedAt === '') $modifiedAt = date('c');

        if ($orderId === '' || !$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido']);
        }

        $dir = WRITEPATH . 'uploads/confirmacion';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $name = $file->getRandomName();
        $file->move($dir, $name);
        $url = base_url('writable/uploads/confirmacion/' . $name);

        $db = \Config\Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
        if (!$pedido) $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
        if (!$pedido) return $this->response->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);

        $orderKey = $this->orderKeyFromPedido($pedido);

        if ($hasImgLocales) {
            $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
            if (!is_array($imagenes)) $imagenes = [];

            $imagenes[$index] = [
                'url' => $url,
                'modified_by' => $modifiedBy,
                'modified_at' => $modifiedAt,
            ];

            $db->table('pedidos')
                ->where('id', (int)$pedido['id'])
                ->update(['imagenes_locales' => json_encode($imagenes, JSON_UNESCAPED_SLASHES)]);
        }

        $nuevoEstado = $this->validarEstadoAutomatico((int)$pedido['id'], $orderKey);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
            'modified_by' => $modifiedBy,
            'modified_at' => $modifiedAt,
            'estado' => $nuevoEstado
        ]);
    }

    public function guardarEstado()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'ok' => false, 'message' => 'No auth']);
        }

        try {
            $db = \Config\Database::connect();
            $payload = $this->request->getJSON(true) ?? [];

            $orderIdRaw = (string)($payload['order_id'] ?? $payload['id'] ?? '');
            $orderId = $this->normalizeShopifyOrderId($orderIdRaw);

            $estado   = (string)($payload['estado'] ?? '');
            $mantener = (bool)($payload['mantener_asignado'] ?? false);

            if ($orderId === '' || $estado === '') {
                return $this->response->setStatusCode(400)->setJSON(['success' => false, 'ok' => false, 'message' => 'Payload inválido']);
            }

            $user = session('nombre') ?? 'Sistema';
            $now  = date('Y-m-d H:i:s');

            $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
            if (!$pedido) $pedido = $db->table('pedidos')->where('id', $orderId)->get()->getRowArray();
            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['success' => false, 'ok' => false, 'message' => 'Pedido no encontrado']);
            }

            $orderKey = $this->orderKeyFromPedido($pedido);

            $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();

            if ($existe) {
                $db->table('pedidos_estado')->where('order_id', $orderKey)->update([
                    'estado' => $estado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user,
                ]);
            } else {
                $db->table('pedidos_estado')->insert([
                    'order_id' => $orderKey,
                    'estado' => $estado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user,
                ]);
            }

            $db->table('pedidos_estado_historial')->insert([
                'order_id' => $orderKey,
                'estado' => $estado,
                'user_name' => $user,
                'created_at' => $now
            ]);

            $estadoLower = mb_strtolower(trim($estado));

            if ($estadoLower === 'confirmado' || $estadoLower === 'cancelado') {
                $db->table('pedidos')
                    ->where('id', (int)$pedido['id'])
                    ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
            }

            if ($estadoLower === 'faltan archivos' && $mantener === true) {
                // no-op
            }

            return $this->response->setJSON(['success' => true, 'ok' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'guardarEstado() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'ok' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function validarEstadoAutomatico(int $pedidoId, string $orderKey): string
    {
        $db = \Config\Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasPedidoJson = in_array('pedido_json', $pFields, true);
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        if (!$hasPedidoJson || !$hasImgLocales) return 'Por preparar';

        $pedido = $db->table('pedidos')->where('id', $pedidoId)->get()->getRowArray();
        if (!$pedido) return 'Por preparar';

        $order    = json_decode($pedido['pedido_json'] ?? '', true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        if (!is_array($imagenes)) $imagenes = [];

        $requeridas = 0;
        $cargadas   = 0;

        foreach (($order['line_items'] ?? []) as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;

                $val = $imagenes[$i] ?? null;
                $url = '';

                if (is_string($val)) $url = $val;
                elseif (is_array($val)) $url = (string)($val['url'] ?? $val['value'] ?? '');

                if (trim($url) !== '') $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas) ? 'Confirmado' : 'Faltan archivos';
        $now  = date('Y-m-d H:i:s');
        $user = session('nombre') ?? 'Sistema';

        $existe = $db->table('pedidos_estado')->where('order_id', $orderKey)->countAllResults();
        if ($existe) {
            $db->table('pedidos_estado')
                ->where('order_id', $orderKey)
                ->update([
                    'estado' => $nuevoEstado,
                    'estado_updated_at' => $now,
                    'estado_updated_by_name' => $user
                ]);
        } else {
            $db->table('pedidos_estado')->insert([
                'order_id' => $orderKey,
                'estado' => $nuevoEstado,
                'estado_updated_at' => $now,
                'estado_updated_by_name' => $user
            ]);
        }

        $db->table('pedidos_estado_historial')->insert([
            'order_id' => $orderKey,
            'estado' => $nuevoEstado,
            'user_name' => $user,
            'created_at' => $now
        ]);

        if ($nuevoEstado === 'Confirmado') {
            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);
        }

        return $nuevoEstado;
    }

    private function requiereImagen(array $item): bool
    {
        $title = strtolower($item['title'] ?? '');
        $sku   = strtolower($item['sku'] ?? '');

        if (str_contains($title, 'llavero') || str_contains($sku, 'llav')) return true;

        foreach (($item['properties'] ?? []) as $p) {
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', (string)($p['value'] ?? ''))) return true;
        }

        return false;
    }

    private function applyEtiquetaExclusions($q, $db): void
    {
        $bad = [
            'cancel', 'cancelado', 'cancelada', 'cancelled', 'canceled',
            'anulado', 'voided',
            'cliente pide cancelar pedido',
            'cliente pide cancelar',
            'pide cancelar pedido',
            'pide cancelar',
            'cancelar pedido',
            'devuel', 'devuelto', 'devuelta',
            'devolu', 'devolucion', 'devolución',
            'devolucion 100', 'devolucion 100%', 'devolución 100', 'devolución 100%',
            'devolucion total', 'devolución total',
            'returned', 'return',
            'reemb', 'reembolso', 'reembolsado',
            'refund', 'refunded',
            'contracargo', 'chargeback',
            'dispute', 'disputa',
        ];

        $tagExpr = "LOWER(COALESCE(p.etiquetas,''))";

        foreach ($bad as $t) {
            $t = mb_strtolower(trim($t));
            if ($t === '') continue;

            $like = '%' . $db->escapeLikeString($t) . '%';
            $q->where("$tagExpr NOT LIKE " . $db->escape($like), null, false);
        }
    }

    // ✅ MEJORADO: filtro SQL por pedido_json (más agresivo)
    private function applyPedidoJsonExclusions($q, $db, bool $hasPedidoJson): void
    {
        if (!$hasPedidoJson) return;

        $jsonExpr = "LOWER(COALESCE(p.pedido_json,''))";

        $needles = [
            'cancelled_at','canceled_at','cancelledat','canceledat',
            'cancel_reason','cancelreason',
            '"status":"cancel', '"status":"canc',
            'cancelado','cancelada','cancelled','canceled','cancel',
            'refund','refunded','reembolso','reembols','voided',
            'devuelto','devuelta','devolucion','devolución','devolu','devuel','returned','return',
            'chargeback','contracargo','dispute','disputa',
            'archived','archivado',
            '"financial_status":"void', '"financial_status":"refund',
            '"displayfinancialstatus":"void', '"displayfinancialstatus":"refund',
        ];

        foreach ($needles as $t) {
            $t = mb_strtolower(trim($t));
            if ($t === '') continue;
            $like = '%' . $db->escapeLikeString($t) . '%';
            $q->where("$jsonExpr NOT LIKE " . $db->escape($like), null, false);
        }
    }

    // ✅ MEJORADO: filtro PHP (si algo se cuela, igual lo bloquea)
    private function isCancelledFromPedidoJson(?string $pedidoJson): bool
    {
        $pedidoJson = (string)$pedidoJson;
        if (trim($pedidoJson) === '') return false;

        $s = mb_strtolower($pedidoJson);
        if (preg_match('/\b(cancelad|cancelled|canceled|cancel_reason|cancelled_at|canceled_at|voided|refund|refunded|reembols|reembolso|devolu|devuel|returned|chargeback|contracargo|disput|archiv)\b/u', $s)) {
            return true;
        }

        $o = json_decode($pedidoJson, true);
        if (!is_array($o)) return false;

        $deepFind = function ($data, array $keys, int $maxDepth = 7) {
            $keys = array_map(fn($k) => mb_strtolower($k), $keys);
            $queue = [[$data, 0]];

            while ($queue) {
                [$cur, $depth] = array_shift($queue);
                if ($depth > $maxDepth) continue;

                if (is_array($cur)) {
                    foreach ($cur as $k => $v) {
                        if (is_string($k) && in_array(mb_strtolower($k), $keys, true)) return $v;
                        if (is_array($v)) $queue[] = [$v, $depth + 1];
                    }
                }
            }
            return null;
        };

        $cancelAt = $deepFind($o, ['cancelled_at','canceled_at','cancelledAt','canceledAt']);
        if ($cancelAt !== null && trim((string)$cancelAt) !== '' && mb_strtolower(trim((string)$cancelAt)) !== 'null') return true;

        $cancelReason = $deepFind($o, ['cancel_reason','cancelReason']);
        if ($cancelReason !== null && trim((string)$cancelReason) !== '' && mb_strtolower(trim((string)$cancelReason)) !== 'null') return true;

        $status = $deepFind($o, ['status','displayStatus']);
        $status = mb_strtolower(trim((string)$status));
        if (in_array($status, ['cancelled','canceled','cancelado','cancelada'], true)) return true;

        $fin = $deepFind($o, ['financial_status','displayFinancialStatus','financialStatus']);
        if (is_array($fin)) $fin = $fin['displayName'] ?? $fin['name'] ?? $fin['value'] ?? '';
        $fin = mb_strtolower(trim((string)$fin));
        if ($fin !== '' && (str_contains($fin, 'void') || str_contains($fin, 'refund') || str_contains($fin, 'reembols'))) return true;

        $tags = $deepFind($o, ['tags']);
        if (is_array($tags)) {
            $flat = [];
            $stack = [$tags];
            while ($stack) {
                $x = array_pop($stack);
                if (is_array($x)) foreach ($x as $vv) $stack[] = $vv;
                else $flat[] = (string)$x;
            }
            $tags = implode(',', $flat);
        }
        $tags = mb_strtolower(trim((string)$tags));
        if ($tags !== '' && preg_match('/\b(cancel|cancelad|refund|reemb|devolu|devuel|returned|chargeback|disput|archiv)\b/u', $tags)) return true;

        return false;
    }
}
