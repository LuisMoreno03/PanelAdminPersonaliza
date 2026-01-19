<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\PedidosEstadoModel;

class ConfirmacionController extends Controller
{
    /* =========================================================
       VISTA
    ========================================================= */

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('confirmacion');
    }

    /* =========================================================
       MI COLA (POR PREPARAR)
       Express primero
    ========================================================= */

    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $limit = (int)($this->request->getGet('limit') ?? 10);
        if ($limit <= 0) $limit = 10;

        try {
            $db = \Config\Database::connect();

            /*
             ──────────────────────────────────────────────
             🔹 LÓGICA:
             - Estado = "Por preparar"
             - Entrega = "Sin preparar"
             - EXPRESS primero
             - Luego por fecha
             ──────────────────────────────────────────────
            */

            $rows = $db->table('pedidos p')
                ->select('
                    p.shopify_order_id   AS id,
                    p.numero,
                    p.cliente,
                    p.total,
                    p.created_at,
                    p.forma_envio,
                    pe.estado
                ')
                ->join(
                    'pedidos_estado pe',
                    'pe.order_id = p.shopify_order_id',
                    'left'
                )
                ->where('LOWER(TRIM(pe.estado))', 'por preparar')
                ->where('LOWER(TRIM(p.estado_envio))', 'sin preparar')
                ->orderBy("
                    CASE
                        WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                        ELSE 1
                    END
                ", '', false)
                ->orderBy('p.created_at', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            $orders = [];

            foreach ($rows as $r) {
                $orders[] = [
                    'id'      => (string)$r['id'],
                    'numero'  => (string)$r['numero'],
                    'fecha'   => substr((string)$r['created_at'], 0, 10),
                    'cliente' => (string)$r['cliente'],
                    'total'   => number_format((float)$r['total'], 2) . ' €',
                    'estado'  => $r['estado'] ?: 'Por preparar',
                ];
            }

            return $this->response->setJSON([
                'success' => true,
                'orders'  => $orders,
                'count'   => count($orders),
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'Confirmacion myQueue ERROR: ' . $e->getMessage());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno',
            ]);
        }
    }

    /* =========================================================
       PULL (NO asigna usuario, solo refresca cola)
    ========================================================= */

    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        // En confirmación el pull NO cambia estado
        // Solo fuerza recarga de cola
        return $this->response->setJSON([
            'success' => true,
        ]);
    }
}
