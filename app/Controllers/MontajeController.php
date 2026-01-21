<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class MontajeController extends Controller
{
    protected $db;
    protected $table = 'pedidos';

    protected $estadoCandidates = [
        'estado_bd',
        'estado',
        'status',
        'estado_pedido',
        'estado_produccion',
        'estado_montaje',
        'estado_proceso',
        'estado_general',
    ];

    protected $assignCandidates = [
        'montaje_user_id',
        'montaje_user',
        'asignado_montaje',
        'montaje_asignado_a',
        'asignado_a',
        'assigned_to',
        'user_id',
    ];

    protected $assignAtCandidates = [
        'montaje_assigned_at',
        'assigned_at',
        'asignado_at',
        'montaje_fecha_asignado',
    ];

    protected $selectCandidates = [
        'id',
        'shopify_order_id',
        'order_id',
        'numero',
        'name',
        'created_at',
        'fecha',
        'cliente',
        'customer_name',
        'total',
        'total_price',
        'etiquetas',
        'tags',
        'articulos',
        'items_count',
        'estado_envio',
        'estado_entrega',
        'fulfillment_status',
        'forma_envio',
        'forma_entrega',
        'shipping_method',
        'metodo_entrega',
        'last_status_change',
    ];

    protected $fields = [];     // lista real
    protected $fieldsMap = [];  // lower => real
    protected $estadoCol = null;
    protected $assignCol = null;
    protected $assignAtCol = null;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->resolveColumns();
    }

    protected function resolveColumns()
    {
        try {
            $fields = $this->db->getFieldNames($this->table);
            $this->fields = $fields ?: [];

            $map = [];
            foreach ($this->fields as $f) $map[strtolower($f)] = $f;
            $this->fieldsMap = $map;

            $this->estadoCol   = $this->detectEstadoCol();
            $this->assignCol   = $this->pickField($this->assignCandidates);
            $this->assignAtCol = $this->pickField($this->assignAtCandidates);
        } catch (\Throwable $e) {
            $this->fields = [];
            $this->fieldsMap = [];
            $this->estadoCol = null;
            $this->assignCol = null;
            $this->assignAtCol = null;
        }
    }

    protected function pickField(array $candidates)
    {
        foreach ($candidates as $c) {
            $k = strtolower($c);
            if (isset($this->fieldsMap[$k])) return $this->fieldsMap[$k];
        }
        return null;
    }

    protected function detectEstadoCol()
    {
        // 1) candidatos exactos
        $exact = $this->pickField($this->estadoCandidates);
        if ($exact) return $exact;

        // 2) heurística: contiene "estado"
        $estadoLike = [];
        foreach ($this->fields as $f) {
            $lf = strtolower($f);
            if (strpos($lf, 'estado') !== false) $estadoLike[] = $f;
        }
        if (!empty($estadoLike)) {
            // preferir columnas más específicas
            usort($estadoLike, function ($a, $b) {
                $la = strtolower($a);
                $lb = strtolower($b);

                $score = function ($x) {
                    $x = strtolower($x);
                    $s = 0;
                    if (strpos($x, 'pedido') !== false) $s += 3;
                    if (strpos($x, 'produ') !== false) $s += 2;
                    if (strpos($x, 'mont') !== false) $s += 2;
                    if (strpos($x, 'shopify') !== false) $s += 1;
                    return $s;
                };

                return $score($lb) <=> $score($la);
            });
            return $estadoLike[0];
        }

        // 3) heurística: contiene "status"
        foreach ($this->fields as $f) {
            $lf = strtolower($f);
            if (strpos($lf, 'status') !== false) return $f;
        }

        return null;
    }

    protected function fieldExists(string $name): bool
    {
        return isset($this->fieldsMap[strtolower($name)]);
    }

    protected function getUserKey(): string
    {
        $uid = session('user_id') ?? session('id') ?? session('uid');
        if ($uid !== null && $uid !== '') return (string)$uid;

        $u = session('username') ?? session('user_name') ?? session('user') ?? session('email');
        return $u ? (string)$u : 'Sistema';
    }

    protected function estadosDisenado(): array
    {
        return ['Diseñado', 'Disenado', 'DISEÑADO', 'DISENADO'];
    }

    public function index()
    {
        return view('montaje/index');
    }

    // ✅ DEBUG: mira columnas reales y cuál detectó
    public function debugColumns()
    {
        return $this->response->setJSON([
            'success' => true,
            'table' => $this->table,
            'columns' => $this->fields,
            'estado_col_detectada' => $this->estadoCol,
            'assign_col_detectada' => $this->assignCol,
            'assign_at_col_detectada' => $this->assignAtCol,
        ]);
    }

    // GET /montaje/my-queue
    public function myQueue()
    {
        try {
            if (!$this->estadoCol) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se encontró la columna de estado en la tabla pedidos.',
                    'columns' => $this->fields, // ✅ para que veas cómo se llama realmente
                ]);
            }

            $userKey = $this->getUserKey();

            $select = [];
            foreach ($this->selectCandidates as $c) {
                if ($this->fieldExists($c)) $select[] = $this->fieldsMap[strtolower($c)];
            }
            if (!in_array('id', array_map('strtolower', $select), true)) $select[] = $this->fieldExists('id') ? $this->fieldsMap['id'] : 'id';

            $b = $this->db->table($this->table)
                ->select(implode(',', $select))
                ->whereIn($this->estadoCol, $this->estadosDisenado())
                ->orderBy('id', 'DESC');

            if ($this->assignCol) $b->where($this->assignCol, $userKey);

            $q = $b->get();
            if ($q === false) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'Error consultando cola de montaje',
                    'error' => (string)$this->db->error()['message'],
                ]);
            }

            return $this->response->setJSON([
                'success' => true,
                'orders' => $q->getResultArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error cargando cola de montaje',
                'error' => $e->getMessage(),
            ]);
        }
    }

    // POST /montaje/pull {count:5|10}
    public function pull()
    {
        try {
            if (!$this->estadoCol) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se encontró la columna de estado en la tabla pedidos.',
                    'columns' => $this->fields,
                ]);
            }

            $payload = $this->request->getJSON(true) ?? [];
            $count = (int)($payload['count'] ?? 0);
            if (!in_array($count, [5, 10], true)) $count = 5;

            $userKey = $this->getUserKey();

            $this->db->transStart();

            $b = $this->db->table($this->table)
                ->select('id')
                ->whereIn($this->estadoCol, $this->estadosDisenado())
                ->orderBy('id', 'ASC')
                ->limit($count);

            if ($this->assignCol) {
                $b->groupStart()
                    ->where($this->assignCol, null)
                    ->orWhere($this->assignCol, '')
                    ->orWhere($this->assignCol, '0')
                ->groupEnd();
            }

            $q = $b->get();
            if ($q === false) {
                $this->db->transRollback();
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'Error consultando pedidos para pull',
                    'error' => (string)$this->db->error()['message'],
                ]);
            }

            $rows = $q->getResultArray();
            $ids = array_map(fn($r) => (int)$r['id'], $rows);

            if (!$ids) {
                $this->db->transComplete();
                return $this->response->setJSON([
                    'success' => true,
                    'pulled' => 0,
                    'message' => 'No hay pedidos en Diseñado disponibles.',
                ]);
            }

            $update = [];
            if ($this->assignCol) $update[$this->assignCol] = $userKey;
            if ($this->assignAtCol) $update[$this->assignAtCol] = date('Y-m-d H:i:s');

            if ($update) {
                $this->db->table($this->table)
                    ->whereIn('id', $ids)
                    ->whereIn($this->estadoCol, $this->estadosDisenado())
                    ->update($update);
            }

            $this->db->transComplete();

            return $this->response->setJSON([
                'success' => true,
                'pulled' => count($ids),
            ]);
        } catch (\Throwable $e) {
            if ($this->db->transStatus() === false) $this->db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error haciendo pull',
                'error' => $e->getMessage(),
            ]);
        }
    }

    // POST /montaje/subir-pedido => Por producir y se desasigna
    public function subirPedido()
    {
        try {
            if (!$this->estadoCol) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se encontró la columna de estado en la tabla pedidos.',
                    'columns' => $this->fields,
                ]);
            }

            $data = $this->request->getJSON(true) ?? [];

            $pedidoId  = trim((string)($data['pedido_id'] ?? ''));
            $shopifyId = trim((string)($data['shopify_order_id'] ?? ''));
            $orderId   = trim((string)($data['order_id'] ?? ''));

            $pedido = null;

            if ($shopifyId !== '' && $shopifyId !== '0' && $this->fieldExists('shopify_order_id')) {
                $pedido = $this->db->table($this->table)->where($this->fieldsMap['shopify_order_id'], $shopifyId)->get()->getRowArray();
            }

            if (!$pedido && $pedidoId !== '' && $pedidoId !== '0') {
                $pedido = $this->db->table($this->table)->where('id', $pedidoId)->get()->getRowArray();
            }

            if (!$pedido && $orderId !== '' && $this->fieldExists('shopify_order_id')) {
                $pedido = $this->db->table($this->table)->where($this->fieldsMap['shopify_order_id'], $orderId)->get()->getRowArray();
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

            $update = [
                $this->estadoCol => 'Por producir',
            ];

            if ($this->fieldExists('last_status_change')) {
                $update[$this->fieldsMap['last_status_change']] = json_encode([
                    'user_name' => $userKey,
                    'changed_at' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE);
            }

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
                'error' => $e->getMessage(),
            ]);
        }
    }
}
