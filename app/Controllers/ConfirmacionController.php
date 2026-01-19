<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ConfirmacionController extends Controller
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to(site_url('login'));
        }

        // Renderiza vista (tu layout + scripts)
        return view('confirmacion');
    }

    /**
     * Cola del usuario (solo "Por preparar" + entrega sin preparar)
     * GET /confirmacion/my-queue?limit=10
     */
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        $limit  = (int)($this->request->getGet('limit') ?? 10);
        $limit  = max(1, min(50, $limit));

        // ✅ IMPORTANTÍSIMO:
        // - order_id en pedidos_estado es el shopify_order_id (string)
        // - pedidos.shopify_order_id también es ese string
        // - pedidos.assigned_to_user_id controla la “cola” (igual que producción)

        $rows = $this->db->table('pedidos p')
            ->select([
                'p.id',
                'p.shopify_order_id',
                'p.numero',
                'p.fecha',
                'p.cliente',
                'p.total',
                'p.estado_envio',         // si ya lo guardas en tu tabla
                'p.forma_envio',          // aquí detectamos express
                'p.etiquetas',
                'p.articulos',
                'pe.estado as estado',
                'pe.estado_updated_at',
                'pe.estado_updated_by_name',
            ])
            ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
            ->where('p.assigned_to_user_id', $userId)
            ->where('LOWER(TRIM(pe.estado))', 'por preparar')
            // ✅ entrega "Sin preparar"
            ->groupStart()
                ->where('p.estado_envio IS NULL')
                ->orWhere('LOWER(TRIM(p.estado_envio))', 'unfulfilled')
                ->orWhere('LOWER(TRIM(p.estado_envio))', '')  // por si guardas vacío
            ->groupEnd()
            ->orderBy("CASE 
                WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                WHEN LOWER(p.etiquetas) LIKE '%express%' THEN 1
                WHEN LOWER(p.etiquetas) LIKE '%urgente%' THEN 1
                ELSE 2
            END", '', false)
            ->orderBy('p.fecha', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        // normaliza last_status_change como en dashboard
        $orders = array_map(function ($r) {
            return [
                'id'       => (string)($r['shopify_order_id'] ?? $r['id']),
                'numero'   => $r['numero'] ?? ('#' . ($r['shopify_order_id'] ?? $r['id'])),
                'fecha'    => $r['fecha'] ?? '-',
                'cliente'  => $r['cliente'] ?? '-',
                'total'    => $r['total'] ?? '-',
                'estado'   => $r['estado'] ?? 'Por preparar',
                'etiquetas'=> $r['etiquetas'] ?? '',
                'articulos'=> $r['articulos'] ?? '-',
                'estado_envio' => $r['estado_envio'] ?? null,
                'forma_envio'  => $r['forma_envio'] ?? '',
                'last_status_change' => [
                    'user_name'  => $r['estado_updated_by_name'] ?? null,
                    'changed_at' => $r['estado_updated_at'] ?? null,
                ],
            ];
        }, $rows);

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders,
            'count'   => count($orders),
        ]);
    }

    /**
     * Pull: asigna 1 pedido "Por preparar" (y sin preparar) al usuario.
     * POST /confirmacion/pull
     */
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setStatusCode(200)->setJSON([
                'success' => false,
                'message' => 'Usuario inválido',
            ]);
        }

        // Busca candidato (NO asignado)
        $candidate = $this->db->table('pedidos p')
            ->select('p.id')
            ->join('pedidos_estado pe', 'pe.order_id = p.shopify_order_id', 'left')
            ->where('p.assigned_to_user_id IS NULL', null, false)
            ->where('LOWER(TRIM(pe.estado))', 'por preparar')
            ->groupStart()
                ->where('p.estado_envio IS NULL')
                ->orWhere('LOWER(TRIM(p.estado_envio))', 'unfulfilled')
                ->orWhere('LOWER(TRIM(p.estado_envio))', '')
            ->groupEnd()
            ->orderBy("CASE 
                WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                WHEN LOWER(p.etiquetas) LIKE '%express%' THEN 1
                WHEN LOWER(p.etiquetas) LIKE '%urgente%' THEN 1
                ELSE 2
            END", '', false)
            ->orderBy('p.fecha', 'ASC')
            ->get(1)
            ->getRowArray();

        if (!$candidate) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No hay pedidos disponibles',
            ]);
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('pedidos')
            ->where('id', (int)$candidate['id'])
            ->update([
                'assigned_to_user_id' => $userId,
                'assigned_at' => $now,
            ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Pedido asignado a tu cola',
        ]);
    }

    /**
     * Devuelve todos los pedidos de tu cola (opcional)
     */
    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);

        $this->db->table('pedidos')
            ->where('assigned_to_user_id', $userId)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null,
            ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Pedidos devueltos',
        ]);
    }
}
