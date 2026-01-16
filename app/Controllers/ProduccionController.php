<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Config\Database;
use CodeIgniter\Controller;

class ProduccionController extends BaseController
{
    private string $estadoEntrada   = 'Confirmado';
    private string $estadoProduccion = 'Producción';

    public function index()
    {
        return view('produccion');
    }
    /**
     * Config: estados que Producción puede "traer"
     * En tu caso quieres Confirmado
     */
    private array $pullEstados = ['Confirmado'];

    // =========================
    // GET /produccion/my-queue
    // =========================
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int) (session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // Cola = pedidos asignados al usuario + estado "produccion" (o el que definas)
            // Aquí lo alineamos con lo que tú quieres: que Producción trabaje con Confirmado (o Por producir)
            // Ajusta el WHERE según tu flujo.
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
                    p.assigned_at,
                    pe.estado AS estado_bd,
                    pe.actualizado AS estado_actualizado
                FROM pedidos p
                JOIN pedidos_estado pe
                  ON pe.order_id = CAST(p.shopify_order_id AS UNSIGNED)
                WHERE p.assigned_to_user_id = ?
                  AND LOWER(TRIM(pe.estado)) IN ('confirmado','por producir','produccion','por producir ')
                ORDER BY pe.actualizado ASC
            ", [$userId])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController myQueue ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando cola',
            ]);
        }
    }

    // =========================
    // POST /produccion/pull
    // body: {count: 5|10}
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int) (session('user_id') ?? 0);
        $userName = (string) (session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int) ($data['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();

            // Traer pedidos disponibles:
            // - estado Confirmado (en pedidos_estado)
            // - y NO asignados (assigned_to_user_id null o 0)
            //
            // Nota: esto depende de que pedidos esté poblada con shopify_order_id.
            $estadoListSql = "'" . implode("','", array_map(fn($s)=>strtolower($s), $this->pullEstados)) . "'";

            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p
                JOIN pedidos_estado pe
                  ON pe.order_id = CAST(p.shopify_order_id AS UNSIGNED)
                WHERE LOWER(TRIM(pe.estado)) IN ($estadoListSql)
                  AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY pe.actualizado ASC
                LIMIT {$count}
            ")->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);
            $now = date('Y-m-d H:i:s');

            // Asignar en pedidos
            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->where("(assigned_to_user_id IS NULL OR assigned_to_user_id = 0)", null, false)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at'         => $now,
                ]);

            // Opcional: guardar historial (si quieres)
            // Aquí puedes insertar en pedidos_estado_historial o order_status_history.
            // Lo dejo comentado para no romperte nada.
            
            foreach ($candidatos as $c) {
                $db->table('order_status_history')->insert([
                    'order_id'     => (string)$c['shopify_order_id'],
                    'prev_estado'  => 'Confirmado',
                    'nuevo_estado' => 'Produccion',
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'ip'           => $this->request->getIPAddress(),
                    'user_agent'   => substr((string)$this->request->getUserAgent(), 0, 250),
                    'created_at'   => $now,
                ]);
            }
            

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'No se pudo asignar (transacción falló)',
                ]);
            }

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => count($ids),
                'ids' => $ids,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno asignando pedidos',
            ]);
        }
    }

    // =========================
    // POST /produccion/return-all
    // =========================
    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int) (session('user_id') ?? 0);
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
                    'assigned_at'         => null,
                ]);

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Pedidos devueltos',
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno devolviendo pedidos',
            ]);
        }
    }
}
