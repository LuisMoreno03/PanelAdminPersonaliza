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
public function detalles($orderId = null)
{
    if (!session()->get('logged_in')) {
        return $this->response
            ->setStatusCode(401)
            ->setJSON(['success' => false, 'error' => 'No autenticado']);
    }

    if (!$orderId) {
        return $this->response
            ->setStatusCode(400)
            ->setJSON(['success' => false, 'error' => 'ID inválido']);
    }

    try {
        $db = \Config\Database::connect();

        // 🔹 Pedido base
        $pedido = $db->table('pedidos')
            ->where('id', $orderId)
            ->orWhere('shopify_order_id', $orderId)
            ->get()
            ->getRowArray();

        if (!$pedido) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['success' => false, 'error' => 'Pedido no encontrado']);
        }

        // 🔹 Aquí reutilizas EXACTAMENTE la misma lógica
        // que ya usabas en dashboard/detalles
        // (Shopify, line_items, imágenes, etc.)

        // ⚠️ EJEMPLO BÁSICO (ajústalo a tu implementación real)
        $orderJson = json_decode($pedido['pedido_json'] ?? '{}', true);

        return $this->response->setJSON([
            'success' => true,
            'order'   => $orderJson,
            'imagenes_locales' => json_decode($pedido['imagenes_locales'] ?? '{}', true),
            'product_images'   => json_decode($pedido['product_images'] ?? '{}', true),
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'Confirmacion detalles ERROR: ' . $e->getMessage());

        return $this->response
            ->setStatusCode(500)
            ->setJSON([
                'success' => false,
                'error' => 'Error cargando detalles'
            ]);
    }
}

}
