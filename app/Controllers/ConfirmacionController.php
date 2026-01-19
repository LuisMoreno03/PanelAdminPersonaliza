<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmacionController extends BaseController
{
    public function index()
    {
        return view('confirmacion', [
            'etiquetasPredeterminadas' => []
        ]);
    }

    // =========================
    // GET /confirmacion/my-queue
    // =========================
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado'
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        if ($userId <= 0) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Usuario inválido'
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // ⚡ QUERY SIMPLE Y SEGURA
            $rows = $db->table('pedidos p')
                ->select([
                    'p.id',
                    'p.numero',
                    'p.cliente',
                    'p.total',
                    'p.estado_envio',
                    'p.forma_envio',
                    'p.etiquetas',
                    'p.articulos',
                    'p.created_at',
                    'p.shopify_order_id',
                    'pe.estado AS estado_bd',
                    'pe.estado_updated_at AS estado_actualizado',
                    'pe.estado_updated_by_name AS estado_por',
                ])
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
                ->where('p.assigned_to_user_id', $userId)
                ->where('LOWER(pe.estado)', 'por preparar')
                ->groupStart()
                    ->where('p.estado_envio IS NULL')
                    ->orWhereNotIn('LOWER(p.estado_envio)', ['fulfilled', 'entregado', 'enviado', 'complete'])
                ->groupEnd()
                ->orderBy("
                    CASE
                        WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                        WHEN LOWER(p.forma_envio) LIKE '%urgente%' THEN 0
                        WHEN LOWER(p.forma_envio) LIKE '%priority%' THEN 0
                        ELSE 1
                    END
                ", '', false)
                ->orderBy('p.created_at', 'ASC')
                ->get()
                ->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: []
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController myQueue ERROR: ' . $e->getMessage());

            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando confirmación'
            ]);
        }
    }

    // =========================
    // POST /confirmacion/pull
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado'
            ]);
        }

        $userId   = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? 'Usuario');

        if ($userId <= 0) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Usuario inválido'
            ]);
        }

        $payload = $this->request->getJSON(true) ?? [];
        $count   = (int)($payload['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // ⚡ QUERY SEGURA (SIN HISTORIAL)
            $candidatos = $db->table('pedidos p')
                ->select('p.id, p.shopify_order_id')
                ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')

                // ✅ SOLO por preparar (fallback)
                ->where("LOWER(COALESCE(pe.estado, 'por preparar')) = 'por preparar'", null, false)

                // ❌ EXCLUIR fulfilled / enviados
                ->groupStart()
                    ->where('p.estado_envio IS NULL')
                    ->orWhereNotIn('LOWER(p.estado_envio)', [
                        'fulfilled',
                        'entregado',
                        'enviado',
                        'complete'
                    ])
                ->groupEnd()

                // ❌ NO asignados aún
                ->groupStart()
                    ->where('p.assigned_to_user_id IS NULL')
                    ->orWhere('p.assigned_to_user_id', 0)
                ->groupEnd()

                // 🚀 PRIORIDAD ENVÍO EXPRESS
                ->orderBy("
                    CASE
                        WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                        WHEN LOWER(p.forma_envio) LIKE '%urgente%' THEN 0
                        WHEN LOWER(p.forma_envio) LIKE '%priority%' THEN 0
                        ELSE 1
                    END
                ", '', false)

                ->orderBy('p.created_at', 'ASC')
                ->limit($count)
                ->get()
                ->getResultArray();


            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'assigned' => 0
                ]);
            }

            $ids = array_column($candidatos, 'id');

            // ✅ ASIGNAR
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now
                ]);

            // 🧾 HISTORIAL (solo escritura, sin lectura)
            foreach ($candidatos as $c) {
                if (empty($c['shopify_order_id'])) continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$c['shopify_order_id'],
                    'estado'     => 'Por preparar',
                    'user_id'    => $userId,
                    'user_name'  => $userName,
                    'created_at' => $now,
                    'pedido_json'=> null
                ]);
            }

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids),
                'ids' => $ids
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController pull ERROR: ' . $e->getMessage());

            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error asignando pedidos'
            ]);
        }
    }

    // =========================
    // POST /confirmacion/return-all
    // =========================
    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
        }

        $userId = (int)(session('user_id') ?? 0);
        if ($userId <= 0) {
            return $this->response->setJSON(['ok' => false]);
        }

        try {
            \Config\Database::connect()
                ->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null
                ]);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'ConfirmacionController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false]);
        }
    }
    // =========================
// GET /confirmacion/detalles/{id}
// =========================
public function detalles($orderId)
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderId = trim((string)$orderId);
        if ($orderId === '' || $orderId === '0') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'ID inválido',
            ]);
        }

        try {
            if (!$this->shop || !$this->token) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Faltan credenciales Shopify',
                ])->setStatusCode(200);
            }

            // 1) Traer pedido desde Shopify
            $urlOrder = "https://{$this->shop}/admin/api/{$this->apiVersion}/orders/" . urlencode($orderId) . ".json";
            $resp = $this->curlShopify($urlOrder, 'GET');

            if ($resp['status'] >= 400 || $resp['status'] === 0) {
                log_message('error', 'DETALLES ORDER HTTP ' . $resp['status'] . ': ' . ($resp['body'] ?? ''));
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Error consultando pedido en Shopify',
                    'status'  => $resp['status'],
                ])->setStatusCode(200);
            }

            $json  = json_decode($resp['body'], true) ?: [];
            $order = $json['order'] ?? null;

            if (!$order) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ])->setStatusCode(200);
            }

            // ✅ 2) OVERRIDE ESTADO desde BD (pedidos_estado)
            // Shopify no tiene tu "Por producir", esto es interno.
            try {
                $estadoModel = new \App\Models\PedidosEstadoModel();
                $rowEstado   = $estadoModel->getEstadoPedido((string)$orderId);

                if (!empty($rowEstado) && !empty($rowEstado['estado'])) {
                    $order['estado'] = $this->normalizeEstado((string)$rowEstado['estado']);

                    $changedAt = $rowEstado['estado_updated_at'] ?? null;
                    if (!$changedAt && !empty($rowEstado['actualizado'])) {
                        $changedAt = $rowEstado['actualizado'];
                    }

                    $order['last_status_change'] = [
                        'user_name'  => $rowEstado['estado_updated_by_name'] ?? 'Sistema',
                        'changed_at' => $changedAt,
                    ];
                }
            } catch (\Throwable $e) {
                log_message('error', 'DETALLES override estado ERROR: ' . $e->getMessage());
            }

            // 3) Product images
            $lineItems  = $order['line_items'] ?? [];
            $productIds = [];

            foreach ($lineItems as $li) {
                if (!empty($li['product_id'])) {
                    $productIds[(string)$li['product_id']] = true;
                }
            }
            $productIds = array_keys($productIds);

            $productImages = [];
            foreach ($productIds as $pid) {
                $urlProd = "https://{$this->shop}/admin/api/{$this->apiVersion}/products/{$pid}.json?fields=id,image,images";
                $rP = $this->curlShopify($urlProd, 'GET');
                if ($rP['status'] >= 400 || $rP['status'] === 0) continue;

                $jP = json_decode($rP['body'], true) ?: [];
                $p  = $jP['product'] ?? null;
                if (!$p) continue;

                $img = '';
                if (!empty($p['image']['src'])) $img = $p['image']['src'];
                elseif (!empty($p['images'][0]['src'])) $img = $p['images'][0]['src'];

                if ($img) $productImages[(string)$pid] = $img;
            }

            // 4) Imágenes locales
            $imagenesLocales = [];
            try {
                $db = \Config\Database::connect();

                $rows = $db->table('pedido_imagenes')
                    ->select('line_index, local_url')
                    ->where('order_id', (string)$orderId)
                    ->get()
                    ->getResultArray();

                foreach ($rows as $r) {
                    $idx = (int)($r['line_index'] ?? -1);
                    $url = trim((string)($r['local_url'] ?? ''));
                    if ($idx >= 0 && $url !== '') {
                        $imagenesLocales[$idx] = $url;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'DETALLES imagenesLocales ERROR: ' . $e->getMessage());
            }

            return $this->response->setJSON([
                'success'          => true,
                'order'            => $order, // ✅ ahora viene con estado override si existe
                'product_images'   => $productImages,
                'imagenes_locales' => $imagenesLocales,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'DETALLES ERROR: ' . $e->getMessage() . ' :: ' . $e->getFile() . ':' . $e->getLine());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno cargando detalles',
            ])->setStatusCode(200);
        }
    }

}
