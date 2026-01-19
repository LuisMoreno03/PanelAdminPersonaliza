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
                ->where('LOWER(pe.estado)', 'por preparar')
                ->groupStart()
                    ->where('p.assigned_to_user_id IS NULL')
                    ->orWhere('p.assigned_to_user_id', 0)
                ->groupEnd()
                ->orderBy("
                    CASE
                        WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
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
}
