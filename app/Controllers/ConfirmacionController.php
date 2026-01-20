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
      -> SOLO pedidos asignados a mi user
      -> SOLO "Por preparar"
      -> SOLO NO ENVIADOS (unfulfilled)
    ===================================================== */
    public function myQueue()
    {
        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
            }

            $userId = (int) session('user_id');
            $db = \Config\Database::connect();

            // Campos reales (no romper si faltan)
            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId = in_array('shopify_order_id', $pFields, true);
            $hasAssignedAt = in_array('assigned_at', $pFields, true);

            // pedidos_estado columnas posibles
            $peFields = $db->getFieldNames('pedidos_estado') ?? [];
            $hasPeUpdatedBy = in_array('estado_updated_by_name', $peFields, true);
            $hasPeUserName  = in_array('user_name', $peFields, true);

            $estadoPorSelect = $hasPeUpdatedBy
                ? 'pe.estado_updated_by_name as estado_por'
                : ($hasPeUserName ? 'pe.user_name as estado_por' : 'NULL as estado_por');

            $q = $db->table('pedidos p')
                ->select("p.id, " .
                         ($hasShopifyId ? "p.shopify_order_id, " : "NULL as shopify_order_id, ") .
                         "p.numero, p.cliente, p.total, p.estado_envio, p.forma_envio, p.etiquetas, p.articulos, p.created_at, " .
                         "COALESCE(pe.estado,'Por preparar') as estado, $estadoPorSelect", false)
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where('p.assigned_to_user_id', $userId)
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar');

            // ✅ SOLO NO ENVIADOS (Shopify unfulfilled en tu BD suele estar NULL)
            $q->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
              ->groupEnd();

            $rows = $q->orderBy('p.created_at', 'ASC')->get()->getResultArray();

            return $this->response->setJSON([
                'ok'   => true,
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'myQueue() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================
      POST /confirmacion/pull
      -> Asigna pedidos NO enviados + Por preparar + no asignados
      -> Toma los más antiguos
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
            if ($count <= 0) $count = 5;

            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasShopifyId = in_array('shopify_order_id', $pFields, true);
            if (!$hasShopifyId) {
                // Si tu BD no tiene shopify_order_id, te lo digo claro (evita asignar mal)
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => 'La tabla pedidos no tiene la columna shopify_order_id. Indícame cuál columna guarda el ID de Shopify.'
                ]);
            }

            $db->transStart();

            // Candidatos: Por preparar + no asignados + no enviados
            $candidatos = $db->table('pedidos p')
                ->select('p.id, p.shopify_order_id')
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where("LOWER(COALESCE(pe.estado,'por preparar'))", 'por preparar')
                ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)')
                ->groupStart()
                    ->where('p.estado_envio IS NULL', null, false)
                    ->orWhere("TRIM(COALESCE(p.estado_envio,'')) = ''", null, false)
                    ->orWhere("LOWER(TRIM(p.estado_envio)) = 'unfulfilled'", null, false)
                ->groupEnd()
                ->orderBy('p.created_at', 'ASC')
                ->limit($count)
                ->get()
                ->getResultArray();

            if (!$candidatos) {
                $db->transComplete();
                return $this->response->setJSON(['ok' => true, 'assigned' => 0, 'message' => 'Sin candidatos']);
            }

            $ids = array_column($candidatos, 'id');

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            // Asegurar historial
            foreach ($candidatos as $c) {
                if (empty($c['shopify_order_id'])) continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => $c['shopify_order_id'],
                    'estado'     => 'Por preparar',
                    'user_name'  => $user,
                    'created_at' => $now
                ]);

                // Si no existe row en pedidos_estado, crea una (opcional pero recomendable)
                $existe = $db->table('pedidos_estado')->where('order_id', $c['shopify_order_id'])->countAllResults();
                if (!$existe) {
                    $db->table('pedidos_estado')->insert([
                        'order_id' => $c['shopify_order_id'],
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

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids)
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'pull() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage()
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
            ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);

        return $this->response->setJSON(['ok' => true]);
    }

    /* =====================================================
      GET /confirmacion/detalles/{id}
      -> Busca pedido por id interno o shopify_order_id
      -> Si tienes pedido_json guardado, úsalo
      -> Si NO, aquí deberías traerlo de Shopify (te dejo el hook)
    ===================================================== */
    public function detalles($id = null)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }
        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'ID inválido']);
        }

        try {
            $db = \Config\Database::connect();
            $pFields = $db->getFieldNames('pedidos') ?? [];
            $hasPedidoJson = in_array('pedido_json', $pFields, true);
            $hasImgLocales = in_array('imagenes_locales', $pFields, true);
            $hasProdImages = in_array('product_images', $pFields, true);

            $pedido = $db->table('pedidos')
                ->groupStart()
                    ->where('id', $id)
                    ->orWhere('shopify_order_id', $id)
                ->groupEnd()
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);
            }

            $shopifyId = $pedido['shopify_order_id'] ?? null;

            // ✅ A) Si existe pedido_json, úsalo
            $orderJson = null;
            if ($hasPedidoJson) {
                $orderJson = json_decode($pedido['pedido_json'] ?? '', true);
            }

            // ✅ B) Si NO hay pedido_json, deberías traerlo de Shopify aquí
            // (Dejo el hook. Si quieres, te lo hago con tu token/env exacto)
            if (!$orderJson || (empty($orderJson['line_items']) && empty($orderJson['lineItems']))) {
                // Ejemplo:
                // $orderJson = $this->fetchShopifyOrder((string)$shopifyId);
                // si no quieres aún, al menos devolvemos datos básicos:
                $orderJson = [
                    'id' => $shopifyId,
                    'name' => $pedido['numero'] ?? ('#'.$shopifyId),
                    'created_at' => $pedido['created_at'] ?? null,
                    'customer' => ['first_name' => $pedido['cliente'] ?? '', 'last_name' => ''],
                    'line_items' => [], // <-- sin detalle si no hay Shopify fetch
                ];
            }

            $imagenesLocales = $hasImgLocales ? json_decode($pedido['imagenes_locales'] ?? '[]', true) : [];
            $productImages   = $hasProdImages ? json_decode($pedido['product_images'] ?? '{}', true) : [];

            return $this->response->setJSON([
                'success' => true,
                'order' => $orderJson,
                'imagenes_locales' => is_array($imagenesLocales) ? $imagenesLocales : [],
                'product_images' => is_array($productImages) ? $productImages : [],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'detalles() error: '.$e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* =====================================================
      POST /api/pedidos/imagenes/subir
      -> Guarda imagen en pedidos.imagenes_locales (si existe)
      -> Llama validarEstadoAutomatico
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
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        $pedido = $db->table('pedidos')->where('shopify_order_id', $orderId)->get()->getRowArray();
        if (!$pedido) return $this->response->setJSON(['success' => false, 'message' => 'Pedido no encontrado']);

        if ($hasImgLocales) {
            $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
            if (!is_array($imagenes)) $imagenes = [];
            $imagenes[$index] = $url;

            $db->table('pedidos')
                ->where('shopify_order_id', $orderId)
                ->update(['imagenes_locales' => json_encode($imagenes)]);
        }

        // ✅ Recalcula estado (si tienes pedido_json; si no, no podrá saber requeridas)
        $nuevoEstado = $this->validarEstadoAutomatico((int)$pedido['id'], (string)$pedido['shopify_order_id']);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
            'estado' => $nuevoEstado
        ]);
    }

    /* =====================================================
      Estado automático
      - Si NO tienes pedido_json, esto NO podrá contar requeridas bien.
      - Recomendación: traer order completo de Shopify o agregar pedido_json.
    ===================================================== */
    private function validarEstadoAutomatico(int $pedidoId, string $shopifyId): string
    {
        $db = \Config\Database::connect();
        $pFields = $db->getFieldNames('pedidos') ?? [];
        $hasPedidoJson = in_array('pedido_json', $pFields, true);
        $hasImgLocales = in_array('imagenes_locales', $pFields, true);

        if (!$hasPedidoJson || !$hasImgLocales) {
            // no rompemos, pero no podemos calcular real
            return 'Por preparar';
        }

        $pedido = $db->table('pedidos')->where('id', $pedidoId)->get()->getRowArray();
        if (!$pedido) return 'Por preparar';

        $order = json_decode($pedido['pedido_json'] ?? '', true);
        $imagenes = json_decode($pedido['imagenes_locales'] ?? '{}', true);
        if (!is_array($imagenes)) $imagenes = [];

        $requeridas = 0;
        $cargadas   = 0;

        foreach (($order['line_items'] ?? []) as $i => $item) {
            if ($this->requiereImagen($item)) {
                $requeridas++;
                if (!empty($imagenes[$i])) $cargadas++;
            }
        }

        $nuevoEstado = ($requeridas > 0 && $requeridas === $cargadas) ? 'Confirmado' : 'Faltan archivos';

        $db->table('pedidos_estado')
            ->where('order_id', $shopifyId)
            ->update([
                'estado' => $nuevoEstado,
                'estado_updated_at' => date('Y-m-d H:i:s'),
                'estado_updated_by_name' => session('nombre') ?? 'Sistema'
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
}
