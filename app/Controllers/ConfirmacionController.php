<?php

namespace App\Controllers;

use App\Controllers\BaseController;


class ConfirmacionController extends BaseController
{
    private string $estadoEntrada = 'Por preparar';
    private string $estadoSalida  = 'Confirmado';

    public function index()
    {
        return view('confirmacion');
    }

    // =========================
    // GET /confirmacion/my-queue
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
                'error' => 'Sin user_id',
            ]);
        }

        try {
            $db = \Config\Database::connect();

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

                    COALESCE(
                        CAST(h.estado AS CHAR),
                        CAST(pe.estado AS CHAR),
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
                    ) x ON x.order_id = h1.order_id AND x.max_created = h1.created_at
                ) h ON h.order_id = p.id

                WHERE p.assigned_to_user_id = ?
                AND LOWER(TRIM(COALESCE(h.estado, pe.estado))) = 'por preparar'

                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, p.created_at) ASC
            ", [$userId])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion myQueue ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno',
            ]);
        }
    }

    // =========================
    // POST /confirmacion/pull
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
        }

        $userId   = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? 'Usuario');

        $count = (int)($this->request->getJSON(true)['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $candidatos = $db->query("
                SELECT p.id, p.shopify_order_id
                FROM pedidos p
                INNER JOIN pedidos_estado_historial h
                    ON h.order_id = p.id OR h.order_id = p.shopify_order_id
                WHERE LOWER(TRIM(h.estado)) = 'por preparar'
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY h.created_at ASC
                LIMIT {$count}
            ")->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON(['ok' => true, 'assigned' => 0]);
            }

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now,
                ]);

            foreach ($candidatos as $c) {
                $db->table('pedidos_estado_historial')->insert([
                    'order_id'   => (string)$c['shopify_order_id'],
                    'estado'     => 'Por preparar',
                    'user_id'    => $userId,
                    'user_name'  => $userName,
                    'created_at' => $now,
                ]);
            }

            return $this->response->setJSON(['ok' => true, 'assigned' => count($ids)]);

        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false]);
        }
    }

    // =========================
    // POST /confirmacion/return-all
    // =========================
    public function returnAll()
    {
        $userId = (int)(session('user_id') ?? 0);

        \Config\Database::connect()
            ->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null,
            ]);

        return $this->response->setJSON(['ok' => true]);
    }
}
