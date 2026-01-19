<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion');
    }

    /* =====================================================
      GET /confirmacion/my-queue
    ===================================================== */

    private function shopifyGet(string $endpoint, array $query = []): array
{
    $shop = getenv('SHOPIFY_STORE_DOMAIN');
    $token = getenv('SHOPIFY_ADMIN_TOKEN');
    $ver = getenv('SHOPIFY_API_VERSION') ?: '2025-10';

    if (!$shop || !$token) {
        throw new \RuntimeException('Faltan SHOPIFY_STORE_DOMAIN o SHOPIFY_TOKEN en .env');
    }

    $qs = $query ? ('?' . http_build_query($query)) : '';
    $url = "https://{$shop}/admin/api/{$ver}/{$endpoint}{$qs}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-Shopify-Access-Token: {$token}",
            "Accept: application/json",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new \RuntimeException("Shopify CURL error: {$err}");
    }

    $data = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($data) ? json_encode($data) : $raw;
        throw new \RuntimeException("Shopify HTTP {$code}: {$msg}");
    }

    return is_array($data) ? $data : [];
}

private function shopifyGetProductImageMap(array $productIds): array
{
    // Devuelve: [product_id => image_url]
    $map = [];
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (!$productIds) return $map;

    foreach ($productIds as $pid) {
        try {
            // GET /products/{id}.json?fields=id,image,images
            $res = $this->shopifyGet("products/{$pid}.json", [
                'fields' => 'id,image,images'
            ]);

            $p = $res['product'] ?? null;
            if (!$p) continue;

            // prioridad: image.src, si no: images[0].src
            $img = $p['image']['src'] ?? ($p['images'][0]['src'] ?? null);
            if ($img) $map[(string)$pid] = (string)$img;

        } catch (\Throwable $e) {
            // si un producto falla, no rompemos el pull
            log_message('warning', 'shopifyGetProductImageMap() product '.$pid.' error: '.$e->getMessage());
        }
    }

    return $map;
}

private function upsertPedidoDesdeShopify(array $o, array $productImagesMap): void
{
    $db = \Config\Database::connect();

    $shopifyId = (string)($o['id'] ?? '');
    if (!$shopifyId) return;

    $cliente = trim(($o['shipping_address']['name'] ?? '') ?: ($o['customer']['first_name'] ?? '').' '.($o['customer']['last_name'] ?? ''));
    $cliente = trim($cliente) ?: ($o['email'] ?? '—');

    $articulos = 0;
    $productIds = [];
    foreach (($o['line_items'] ?? []) as $li) {
        $articulos += (int)($li['quantity'] ?? 0);
        if (!empty($li['product_id'])) $productIds[] = (int)$li['product_id'];
    }

    // product_images por pedido: solo los usados en ese pedido
    $pedidoProductImages = [];
    foreach (array_unique($productIds) as $pid) {
        $k = (string)$pid;
        if (!empty($productImagesMap[$k])) $pedidoProductImages[$k] = $productImagesMap[$k];
    }

    // columnas opcionales según tu tabla
    $fields = $db->getFieldNames('pedidos') ?? [];
    $hasFulfillment = in_array('fulfillment_status', $fields, true);
    $hasEstadoEnvio = in_array('estado_envio', $fields, true);

    $data = [
        'shopify_order_id' => $shopifyId,
        'numero'           => $o['name'] ?? ('#'.$shopifyId),
        'created_at'       => $o['created_at'] ?? date('Y-m-d H:i:s'),
        'cliente'          => $cliente,
        'total'            => $o['total_price'] ?? '0.00',
        'articulos'        => $articulos,
        'pedido_json'      => json_encode($o, JSON_UNESCAPED_UNICODE),
        'product_images'   => json_encode($pedidoProductImages, JSON_UNESCAPED_UNICODE),
    ];

    $ful = $o['fulfillment_status'] ?? null; // null / unfulfilled / fulfilled
    if ($hasFulfillment) $data['fulfillment_status'] = $ful;
    if ($hasEstadoEnvio) $data['estado_envio'] = $ful;

    // INSERT si no existe, UPDATE si existe
    $existe = $db->table('pedidos')->where('shopify_order_id', $shopifyId)->countAllResults();
    if ($existe) {
        $db->table('pedidos')->where('shopify_order_id', $shopifyId)->update($data);
    } else {
        // Si tu tabla requiere valores por defecto para assigned_to_user_id etc, añádelos aquí
        $db->table('pedidos')->insert($data);
    }

    // Asegurar que exista fila en pedidos_estado (para que COALESCE no dependa de join raro)
    $exPe = $db->table('pedidos_estado')->where('order_id', $shopifyId)->countAllResults();
    if (!$exPe) {
        $db->table('pedidos_estado')->insert([
            'order_id' => $shopifyId,
            'estado'   => 'Por preparar',
        ]);
    }
}


    public function myQueue()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
            }

            $userId = (int) session('user_id');
            $db = \Config\Database::connect();

            // columnas de pedidos
            $pedidoFields = $db->getFieldNames('pedidos') ?? [];
            $hasEstadoEnvio = in_array('estado_envio', $pedidoFields, true);
            $hasFulfillment = in_array('fulfillment_status', $pedidoFields, true);

            // columnas de pedidos_estado
            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUserName = in_array('user_name', $peFields, true);
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            $q = $db->table('pedidos p')
                ->select("p.*, COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect", false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar');

            // ✅ FILTRO: SOLO UNFULFILLED (NULL / '' / unfulfilled)
            if ($hasEstadoEnvio) {
                $q->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd();
            } elseif ($hasFulfillment) {
                $q->groupStart()
                    ->where('p.fulfillment_status IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.fulfillment_status,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'", null, false)
                ->groupEnd();
            }

            $rows = $q->orderBy('p.created_at', 'ASC')->get()->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);
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
        $count   = (int) ($payload['count'] ?? 5);
        if ($count <= 0) $count = 5;

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // 1) Traer de Shopify SOLO unfulfilled (y status=any para que Shopify no limite)
        $res = $this->shopifyGet('orders.json', [
            'status'            => 'any',
            'fulfillment_status'=> 'unfulfilled',
            'limit'             => max(10, $count * 3),
            // fields para reducir payload si quieres (pero tú dijiste “toda la info”, así que lo dejamos completo)
        ]);

        $orders = $res['orders'] ?? [];
        if (!$orders) {
            return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'message' => 'Shopify no devolvió pedidos unfulfilled']);
        }

        // 2) Sacar product_ids y resolver product_images (referencias visuales)
        $productIds = [];
        foreach ($orders as $o) {
            foreach (($o['line_items'] ?? []) as $li) {
                if (!empty($li['product_id'])) $productIds[] = (int)$li['product_id'];
            }
        }
        $productImagesMap = $this->shopifyGetProductImageMap($productIds);

        // 3) Upsert cada pedido en tu BD (pedido_json completo + product_images)
        foreach ($orders as $o) {
            $this->upsertPedidoDesdeShopify($o, $productImagesMap);
        }

        // 4) Asignar a este usuario (solo por preparar + no asignados + unfulfilled)
        $pedidoFields = $db->getFieldNames('pedidos') ?? [];
        $hasFulfillment = in_array('fulfillment_status', $pedidoFields, true);
        $hasEstadoEnvio = in_array('estado_envio', $pedidoFields, true);

        $q = $db->table('pedidos p')
            ->select('p.id, p.shopify_order_id')
            ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
            ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar')
            ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)', null, false);

        // filtro unfulfilled en tu tabla
        if ($hasFulfillment) {
            $q->groupStart()
                ->where('p.fulfillment_status IS NULL', null, false)
                ->orWhere("TRIM(COALESCE(p.fulfillment_status,'')) = ''", null, false)
                ->orWhere("LOWER(TRIM(p.fulfillment_status)) = 'unfulfilled'", null, false)
            ->groupEnd();
        } elseif ($hasEstadoEnvio) {
            $q->groupStart()
                ->where('p.estado_envio IS NULL', null, false)
                ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
            ->groupEnd();
        }

        $candidatos = $q->orderBy('p.created_at', 'ASC')->limit($count)->get()->getResultArray();

        if (!$candidatos) {
            return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos luego del sync']);
        }

        $ids = array_column($candidatos, 'id');

        $db->table('pedidos')->whereIn('id', $ids)->update([
            'assigned_to_user_id' => $userId,
            'assigned_at' => $now
        ]);

        foreach ($candidatos as $c) {
            if (empty($c['shopify_order_id'])) continue;
            $db->table('pedidos_estado_historial')->insert([
                'order_id'   => $c['shopify_order_id'],
                'estado'     => 'Por preparar',
                'user_name'  => $user,
                'created_at' => $now
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'assigned' => count($ids),
            'shopify_fetched' => count($orders),
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'pull(shopify) error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);
    }
}

    /* =====================================================
      POST /confirmacion/return-all
    ===================================================== */
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
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null
            ]);

        return $this->response->setJSON(['ok' => true]);
    }

    /* =====================================================
      GET /confirmacion/detalles/{id}
    ===================================================== */
    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ]);
        }

        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'ID inválido'
            ]);
        }

        try {
            $db = \Config\Database::connect();

            $pedido = $db->table('pedidos')
                ->groupStart()
                    ->where('id', $id)
                    ->orWhere('shopify_order_id', $id)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado'
                ]);
            }

            $orderJson = json_decode($pedido['pedido_json'] ?? '', true);

            if (empty($orderJson)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Pedido sin JSON guardado'
                ]);
            }

            $imagenesLocales = json_decode($pedido['imagenes_locales'] ?? '[]', true);
            $productImages   = json_decode($pedido['product_images'] ?? '{}', true);

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
                'product_images' => is_array($productImages) ? $productImages : [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion detalles ERROR: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* =====================================================
      POST /api/pedidos/imagenes/subir
    ===================================================== */
    public function subirImagen()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false]);
        }

        $orderId = $this->request->getPost('order_id');
        $index   = (int) $this->request->getPost('line_index');
        $file    = $this->request->getFile('file');

        if (!$orderId || !$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido']);
        }

        $name = $file->getRandomName();
        $file->move(WRITEPATH . 'uploads/confirmacion', $name);

        $url = base_url('writable/uploads/confirmacion/' . $name);

        $db = \Config\Database::connect();
        $pedido = $db->table('pedidos')
            ->where('shopify_order_id', $orderId)
            ->get()
            ->getRowArray();

        if (!$pedido) {
            return $this->response->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);
        }

        $imagenes = json_decode($pedido['imagenes_locales'] ?? '[]', true);
        if (!is_array($imagenes)) $imagenes = [];
        $imagenes[$index] = $url;

        $db->table('pedidos')
            ->where('shopify_order_id', $orderId)
            ->update(['imagenes_locales' => json_encode($imagenes)]);

        $this->validarEstadoAutomatico((int)$pedido['id'], (string)$pedido['shopify_order_id']);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url
        ]);
    }

    /* =====================================================
      ESTADO AUTOMÁTICO (con UPSERT + soporta REST/GraphQL)
    ===================================================== */
    private function validarEstadoAutomatico(int $pedidoId, string $shopifyId)
    {
        $db = \Config\Database::connect();

        $pedido = $db->table('pedidos')->where('id', $pedidoId)->get()->getRowArray();
        if (!$pedido) return;

        $order = json_decode($pedido['pedido_json'] ?? '', true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '[]', true);
        if (!is_array($imagenes)) $imagenes = [];

        $items = [];

        // REST
        if (!empty($order['line_items']) && is_array($order['line_items'])) {
            $items = $order['line_items'];
        }
        // GraphQL
        elseif (!empty($order['lineItems']['edges']) && is_array($order['lineItems']['edges'])) {
            foreach ($order['lineItems']['edges'] as $edge) {
                if (!empty($edge['node'])) $items[] = $edge['node'];
            }
        }

        $requeridas = 0;
        $cargadas   = 0;

        foreach ($items as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;
                if (!empty($imagenes[$i])) $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas)
            ? 'Confirmado'
            : 'Faltan archivos';

        // ✅ UPSERT en pedidos_estado (si no existe fila, la crea)
        $existe = $db->table('pedidos_estado')
            ->where('order_id', $shopifyId)
            ->countAllResults();

        $dataEstado = [
            'order_id' => $shopifyId,
            'estado' => $nuevoEstado,
            'estado_updated_at' => date('Y-m-d H:i:s'),
            'estado_updated_by_name' => session('nombre') ?? 'Sistema'
        ];

        if ($existe) {
            $db->table('pedidos_estado')->where('order_id', $shopifyId)->update($dataEstado);
        } else {
            // si tu tabla NO tiene estas columnas extra, quítalas aquí.
            $db->table('pedidos_estado')->insert($dataEstado);
        }

        // Confirmado → liberar pedido
        if ($nuevoEstado === 'Confirmado') {
            $db->table('pedidos')
                ->where('id', $pedidoId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null
                ]);
        }
    }

    /* =====================================================
      REGLAS IMAGEN (soporta REST + GraphQL)
    ===================================================== */
    private function requiereImagen(array $item): bool
    {
        $title = strtolower((string)($item['title'] ?? ''));
        $sku   = strtolower((string)($item['sku'] ?? ''));

        if (str_contains($title, 'llavero') || str_contains($sku, 'llav')) {
            return true;
        }

        // REST: properties = array de ['name','value']
        if (!empty($item['properties']) && is_array($item['properties'])) {
            foreach ($item['properties'] as $p) {
                $val = (string)($p['value'] ?? '');
                if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', $val)) return true;
            }
        }

        // GraphQL: customAttributes = array de ['key','value']
        if (!empty($item['customAttributes']) && is_array($item['customAttributes'])) {
            foreach ($item['customAttributes'] as $p) {
                $val = (string)($p['value'] ?? '');
                if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)/i', $val)) return true;
            }
        }

        return false;
    }
}
