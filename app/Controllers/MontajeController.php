<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class MontajeController extends Controller
{
    protected $db;
    protected $table = 'pedidos';

    // Candidatos para “asignación” (ajusta aquí si tu BD tiene otro nombre)
    protected $assignCandidates = [
        'montaje_user_id',
        'montaje_user',
        'asignado_montaje',
        'montaje_asignado_a',
        'asignado_a',
        'assigned_to',
        'user_id',
    ];

    // Candidatos para “timestamp de asignación” (opcional)
    protected $assignAtCandidates = [
        'montaje_assigned_at',
        'assigned_at',
        'asignado_at',
        'montaje_fecha_asignado',
    ];

    protected $assignCol = null;
    protected $assignAtCol = null;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->resolveAssignColumns();
    }

    protected function resolveAssignColumns()
    {
        try {
            $fields = $this->db->getFieldNames($this->table);
            $fields = array_map('strtolower', $fields);

            foreach ($this->assignCandidates as $c) {
                if (in_array(strtolower($c), $fields, true)) {
                    $this->assignCol = $c;
                    break;
                }
            }

            foreach ($this->assignAtCandidates as $c) {
                if (in_array(strtolower($c), $fields, true)) {
                    $this->assignAtCol = $c;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->assignCol = null;
            $this->assignAtCol = null;
        }
    }

    protected function getUserKey()
    {
        // intenta sacar algo estable del usuario logueado
        $uid = session('user_id') ?? session('id') ?? session('uid');
        if ($uid !== null && $uid !== '') return (string)$uid;

        $u = session('username') ?? session('user_name') ?? session('user') ?? session('email');
        return $u ? (string)$u : 'Sistema';
    }

    public function index()
    {
        return view('montaje/index');
    }

    // =========================
    // GET /montaje/my-queue
    // Devuelve SOLO Diseñado y asignados a mi (si existe campo)
    // =========================
    public function myQueue()
    {
        try {
            $userKey = $this->getUserKey();

            $b = $this->db->table($this->table)
                ->select('id, shopify_order_id, numero, created_at, cliente, total, estado_bd, etiquetas, articulos, estado_envio, forma_envio, last_status_change')
                ->groupStart()
                    ->where('estado_bd', 'Diseñado')
                    ->orWhere('estado_bd', 'Disenado')
                ->groupEnd()
                ->orderBy('id', 'DESC');

            if ($this->assignCol) {
                $b->where($this->assignCol, $userKey);
            }

            $rows = $b->get()->getResultArray();

            return $this->response->setJSON([
                'success' => true,
                'orders'  => $rows,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error cargando cola de montaje',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/pull
    // body: {count: 5|10}
    // Toma pedidos Diseñado (no asignados si existe campo) y los asigna al usuario
    // =========================
    public function pull()
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];
            $count = (int)($payload['count'] ?? 0);
            if (!in_array($count, [5, 10], true)) $count = 5;

            $userKey = $this->getUserKey();

            $this->db->transStart();

            $b = $this->db->table($this->table)
                ->select('id')
                ->groupStart()
                    ->where('estado_bd', 'Diseñado')
                    ->orWhere('estado_bd', 'Disenado')
                ->groupEnd()
                ->orderBy('id', 'ASC')
                ->limit($count);

            if ($this->assignCol) {
                // solo no asignados
                $b->groupStart()
                    ->where($this->assignCol, null)
                    ->orWhere($this->assignCol, '')
                    ->orWhere($this->assignCol, '0')
                ->groupEnd();
            }

            $ids = array_map(fn($r) => (int)$r['id'], $b->get()->getResultArray());

            if (!$ids) {
                $this->db->transComplete();
                return $this->response->setJSON([
                    'success' => true,
                    'pulled'  => 0,
                    'message' => 'No hay pedidos en Diseñado disponibles.',
                ]);
            }

            $update = [];
            if ($this->assignCol) $update[$this->assignCol] = $userKey;
            if ($this->assignAtCol) $update[$this->assignAtCol] = date('Y-m-d H:i:s');

            if ($update) {
                $this->db->table($this->table)
                    ->whereIn('id', $ids)
                    ->groupStart()
                        ->where('estado_bd', 'Diseñado')
                        ->orWhere('estado_bd', 'Disenado')
                    ->groupEnd()
                    ->update($update);
            }

            $this->db->transComplete();

            return $this->response->setJSON([
                'success' => true,
                'pulled'  => count($ids),
            ]);
        } catch (\Throwable $e) {
            if ($this->db->transStatus() === false) $this->db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error haciendo pull',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // POST /montaje/subir-pedido
    // Marca el pedido como "Por producir" y lo saca de tu lista
    // body JSON: { pedido_id, shopify_order_id, order_id }
    // =========================
    public function subirPedido()
    {
        try {
            $data = $this->request->getJSON(true) ?? [];

            $pedidoId  = trim((string)($data['pedido_id'] ?? ''));
            $shopifyId = trim((string)($data['shopify_order_id'] ?? ''));
            $orderId   = trim((string)($data['order_id'] ?? ''));

            $b = $this->db->table($this->table);
            $pedido = null;

            if ($shopifyId !== '' && $shopifyId !== '0') {
                $pedido = $b->where('shopify_order_id', $shopifyId)->get()->getRowArray();
            }

            if (!$pedido && $pedidoId !== '' && $pedidoId !== '0') {
                $pedido = $this->db->table($this->table)->where('id', $pedidoId)->get()->getRowArray();
            }

            if (!$pedido && $orderId !== '') {
                $pedido = $this->db->table($this->table)->where('shopify_order_id', $orderId)->get()->getRowArray();
                if (!$pedido && ctype_digit($orderId)) {
                    $pedido = $this->db->table($this->table)->where('id', (int)$orderId)->get()->getRowArray();
                }
            }

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ]);
            }

            $id = (int)$pedido['id'];
            $userKey = $this->getUserKey();

            $last = json_encode([
                'user_name'  => $userKey,
                'changed_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);

            $update = [
                'estado_bd'          => 'Por producir',
                'last_status_change' => $last,
            ];

            // opcional: “desasignar” para liberar tu cola
            if ($this->assignCol) $update[$this->assignCol] = null;

            $this->db->table($this->table)->where('id', $id)->update($update);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Pedido → Por producir',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error pasando a Por producir',
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
