<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class MontajeController extends Controller
{
    protected $db;
    protected $table = 'pedidos';

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // =========================
    // VIEW
    // =========================
    public function index()
    {
        // Tu vista actual (si ya la tienes, deja esto igual)
        return view('montaje/index');
    }

    // =========================
    // API: MY QUEUE (solo Diseñado + asignados a mí)
    // GET /montaje/my-queue
    // =========================
    public function myQueue()
    {
        try {
            [$estadoCol, $assignCol] = $this->resolveColumns();
            if (!$estadoCol) {
                return $this->jsonFail("No se pudo detectar la columna de estado en la tabla {$this->table}.");
            }

            $userKey = $this->getUserKey();

            $builder = $this->db->table($this->table);

            // Filtro por estado "Diseñado" (tolerante a Diseñ/Disen, mayúsculas/minúsculas)
            $this->whereEstadoDisenado($builder, $estadoCol);

            // Si existe columna de asignación, filtramos por mi usuario
            if ($assignCol && $userKey !== null && $userKey !== '') {
                $builder->where($assignCol, $userKey);
            }

            // Orden (si existe updated_at o id)
            $fields = $this->db->getFieldNames($this->table);
            if (in_array('updated_at', $fields, true)) $builder->orderBy('updated_at', 'DESC');
            elseif (in_array('id', $fields, true)) $builder->orderBy('id', 'DESC');

            // Selección segura de campos existentes
            $select = $this->buildSafeSelect($fields, $estadoCol);

            $rows = $builder->select($select)->limit(200)->get()->getResultArray();

            // Normaliza salida para el JS
            $orders = array_map(function ($r) use ($estadoCol) {
                return $this->normalizeOrderRow($r, $estadoCol);
            }, $rows);

            return $this->jsonOk([
                'orders' => $orders,
                'meta' => [
                    'estado_col' => $estadoCol,
                    'assign_col' => $assignCol,
                    'user_key'   => $this->safeMeta($this->getUserKey()),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail("Error cargando cola de montaje", $e->getMessage());
        }
    }

    // =========================
    // API: PULL (trae 5 o 10 en Diseñado)
    // POST /montaje/pull {count}
    // =========================
    public function pull()
    {
        try {
            $payload = $this->request->getJSON(true) ?? $this->request->getPost();
            $count = (int)($payload['count'] ?? 5);
            if ($count !== 5 && $count !== 10) $count = 5;

            [$estadoCol, $assignCol] = $this->resolveColumns();
            if (!$estadoCol) {
                return $this->jsonFail("No se pudo detectar la columna de estado en la tabla {$this->table}.");
            }

            $userKey = $this->getUserKey();
            if (($assignCol) && ($userKey === null || $userKey === '')) {
                // si hay asignación por usuario, necesitamos userKey para asignar
                return $this->jsonFail("Sesión inválida: no se detectó user_id para asignar pedidos.");
            }

            $fields = $this->db->getFieldNames($this->table);
            $idCol  = $this->detectIdCol($fields);
            if (!$idCol) {
                return $this->jsonFail("No se pudo detectar la PK (id/pedido_id) en {$this->table}.");
            }

            $this->db->transStart();

            // 1) Buscar candidatos en Diseñado y NO asignados (si existe assignCol)
            $b = $this->db->table($this->table);
            $this->whereEstadoDisenado($b, $estadoCol);

            if ($assignCol) {
                $b->groupStart()
                    ->where($assignCol, null)
                    ->orWhere($assignCol, '')
                    ->orWhere($assignCol, 0)
                  ->groupEnd();
            }

            // Orden
            if (in_array('updated_at', $fields, true)) $b->orderBy('updated_at', 'ASC');
            elseif (in_array('created_at', $fields, true)) $b->orderBy('created_at', 'ASC');
            else $b->orderBy($idCol, 'ASC');

            $rows = $b->select($idCol)->limit($count)->get()->getResultArray();
            $ids = array_values(array_filter(array_map(fn($r) => $r[$idCol] ?? null, $rows)));

            if (!$ids) {
                $this->db->transComplete();
                return $this->jsonOk([
                    'pulled' => 0,
                    'message' => 'No hay pedidos disponibles en Diseñado para asignar.',
                ]);
            }

            // 2) Asignar a este usuario (si existe columna de asignación)
            if ($assignCol) {
                $upd = [$assignCol => $userKey];
                // si hay columna de timestamp de asignación
                if (in_array('montaje_assigned_at', $fields, true)) $upd['montaje_assigned_at'] = date('Y-m-d H:i:s');
                if (in_array('assigned_at', $fields, true))        $upd['assigned_at'] = date('Y-m-d H:i:s');

                $this->db->table($this->table)->whereIn($idCol, $ids)->update($upd);
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->jsonFail("No se pudo completar el pull (transacción fallida).");
            }

            return $this->jsonOk([
                'pulled' => count($ids),
                'ids'    => $ids,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail("Error haciendo pull", $e->getMessage());
        }
    }

    // =========================
    // API: CARGADO -> pasa a "Por producir" y lo quita de tu cola
    // POST /montaje/subir-pedido {order_id}
    // =========================
    public function subirPedido()
    {
        try {
            $payload = $this->request->getJSON(true) ?? $this->request->getPost();

            $orderId = trim((string)($payload['order_id'] ?? $payload['pedido_id'] ?? $payload['shopify_order_id'] ?? ''));
            if ($orderId === '') return $this->jsonFail("order_id es requerido.");

            [$estadoCol, $assignCol] = $this->resolveColumns();
            if (!$estadoCol) {
                return $this->jsonFail("No se pudo detectar la columna de estado en la tabla {$this->table}.");
            }

            $fields = $this->db->getFieldNames($this->table);
            $idCol  = $this->detectIdCol($fields);

            // Resolver registro por: shopify_order_id si existe, si no por PK
            $b = $this->db->table($this->table);

            $shopifyCol = $this->detectShopifyCol($fields);
            if ($shopifyCol) {
                $b->groupStart()
                    ->where($shopifyCol, $orderId)
                    ->orWhere(($idCol ?: 'id'), $orderId)
                  ->groupEnd();
            } else {
                if (!$idCol) return $this->jsonFail("No se detectó columna id ni shopify_order_id para actualizar.");
                $b->where($idCol, $orderId);
            }

            // Update: estado -> Por producir + desasignar
            $upd = [
                $estadoCol => 'Por producir',
            ];

            if ($assignCol) $upd[$assignCol] = null;

            // last change (si existen columnas típicas)
            $now = date('Y-m-d H:i:s');
            if (in_array('estado_actualizado', $fields, true)) $upd['estado_actualizado'] = $now;
            if (in_array('estado_changed_at', $fields, true))  $upd['estado_changed_at'] = $now;

            $userName = $this->getUserName();
            if (in_array('estado_por', $fields, true))         $upd['estado_por'] = $userName;
            if (in_array('estado_changed_by', $fields, true))  $upd['estado_changed_by'] = $userName;

            // JSON last_status_change si existe
            if (in_array('last_status_change', $fields, true)) {
                $upd['last_status_change'] = json_encode([
                    'user_name'  => $userName,
                    'changed_at' => $now,
                    'from'       => 'Diseñado',
                    'to'         => 'Por producir',
                ], JSON_UNESCAPED_UNICODE);
            }

            $ok = $b->update($upd);

            if (!$ok) {
                $err = $this->db->error();
                return $this->jsonFail("No se pudo actualizar el pedido.", $err['message'] ?? null);
            }

            return $this->jsonOk([
                'message' => 'Pedido marcado como Cargado → Por producir.',
                'new_estado' => 'Por producir',
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail("Error marcando como cargado", $e->getMessage());
        }
    }

    // =========================
    // DEBUG (para saber qué columna está usando)
    // GET /montaje/debug-status
    // =========================
    public function debugStatus()
    {
        try {
            [$estadoCol, $assignCol] = $this->resolveColumns(true);

            $fields = $this->db->getFieldNames($this->table);
            $idCol  = $this->detectIdCol($fields);

            $sample = [];
            if ($estadoCol) {
                $b = $this->db->table($this->table);

                $sel = [$estadoCol . ' AS estado'];
                if ($idCol) $sel[] = $idCol . ' AS id';
                if ($assignCol) $sel[] = $assignCol . ' AS asignado';

                $rows = $b->select(implode(',', $sel))
                    ->where($estadoCol . ' IS NOT NULL', null, false)
                    ->limit(30)
                    ->get()->getResultArray();

                $sample = $rows;
            }

            return $this->jsonOk([
                'table' => $this->table,
                'estado_col' => $estadoCol,
                'assign_col' => $assignCol,
                'fields_count' => count($fields),
                'sample' => $sample,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail("debugStatus error", $e->getMessage());
        }
    }

    // =========================
    // Helpers
    // =========================
    private function resolveColumns(bool $forceRecalc = false): array
    {
        static $cache = null;
        if ($cache && !$forceRecalc) return $cache;

        $fields = $this->db->getFieldNames($this->table);

        // 1) Detect estado column by VALUES (más confiable)
        $estadoCol = $this->detectEstadoColByValues($fields);

        // 2) Detect assign column (montaje)
        $assignCol = $this->detectAssignCol($fields);

        $cache = [$estadoCol, $assignCol];
        return $cache;
    }

    private function detectEstadoColByValues(array $fields): ?string
    {
        // candidatos: columnas con "estado"/"status" + algunas comunes
        $candidates = [];
        $preferred = [
            'estado', 'estado_pedido', 'estado_produccion', 'estado_bd',
            'status', 'pedido_estado', 'workflow_estado', 'stage', 'fase',
        ];

        foreach ($preferred as $c) {
            if (in_array($c, $fields, true)) $candidates[] = $c;
        }
        foreach ($fields as $f) {
            $lf = strtolower($f);
            if (str_contains($lf, 'estado') || str_contains($lf, 'status') || str_contains($lf, 'stage') || str_contains($lf, 'fase')) {
                if (!in_array($f, $candidates, true)) $candidates[] = $f;
            }
        }

        if (!$candidates) return null;

        // scoring por valores reales
        $best = null;
        $bestScore = -1;

        foreach ($candidates as $col) {
            try {
                $rows = $this->db->table($this->table)
                    ->select($col . ' AS v')
                    ->where($col . ' IS NOT NULL', null, false)
                    ->limit(200)
                    ->get()->getResultArray();

                $vals = array_map(fn($r) => strtolower(trim((string)($r['v'] ?? ''))), $rows);
                $vals = array_values(array_filter($vals, fn($x) => $x !== ''));

                if (!$vals) continue;

                $score = 0;

                // puntos por encontrar estados típicos del workflow
                foreach ($vals as $v) {
                    if (str_contains($v, 'dise')) $score += 5;            // Diseñado / Diseño / Disenado
                    if (str_contains($v, 'por producir')) $score += 4;
                    if (str_contains($v, 'confirm')) $score += 2;
                    if (str_contains($v, 'fabric')) $score += 2;
                    if (str_contains($v, 'enviado')) $score += 1;
                    if (str_contains($v, 'repetir')) $score += 1;
                }

                // penaliza si parece numérico puro
                $numericish = 0;
                foreach (array_slice($vals, 0, 50) as $v) {
                    if ($v !== '' && ctype_digit(str_replace(['-', '_'], '', $v))) $numericish++;
                }
                if ($numericish > 10) $score -= 5;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $col;
                }
            } catch (\Throwable $e) {
                // ignora columna si falla
            }
        }

        return $best;
    }

    private function detectAssignCol(array $fields): ?string
    {
        // Prioriza columnas específicas de montaje
        $preferred = [
            'montaje_user_id', 'montaje_usuario_id', 'montaje_asignado_a', 'montaje_asignado',
            'assigned_montaje', 'asignado_montaje', 'usuario_montaje',
            'assigned_to', 'asignado_a', 'user_id_asignado',
        ];

        foreach ($preferred as $c) {
            if (in_array($c, $fields, true)) return $c;
        }

        // Heurística: contiene montaje y user/usuario
        foreach ($fields as $f) {
            $lf = strtolower($f);
            if (str_contains($lf, 'montaje') && (str_contains($lf, 'user') || str_contains($lf, 'usuario') || str_contains($lf, 'asign'))) {
                return $f;
            }
        }

        return null;
    }

    private function detectIdCol(array $fields): ?string
    {
        $candidates = ['id', 'pedido_id', 'order_id'];
        foreach ($candidates as $c) {
            if (in_array($c, $fields, true)) return $c;
        }
        return null;
    }

    private function detectShopifyCol(array $fields): ?string
    {
        $candidates = ['shopify_order_id', 'shopifyId', 'shopify_id'];
        foreach ($candidates as $c) {
            if (in_array($c, $fields, true)) return $c;
        }
        return null;
    }

    private function whereEstadoDisenado($builder, string $estadoCol): void
    {
        // tolerante: Diseñ* o Disen* (sin acento)
        $builder->groupStart()
            ->like($estadoCol, 'Diseñ', 'both', null, true)
            ->orLike($estadoCol, 'Disen', 'both', null, true)
            ->orLike($estadoCol, 'Dise',  'both', null, true)
        ->groupEnd();
    }

    private function buildSafeSelect(array $fields, string $estadoCol): string
    {
        $want = [
            'id', 'pedido_id', 'shopify_order_id',
            'numero', 'name',
            'fecha', 'created_at',
            'cliente', 'customer_name',
            'total', 'total_price',
            'articulos', 'items_count',
            'estado_envio', 'fulfillment_status',
            'forma_envio', 'shipping_method',
            'last_status_change', 'estado_por', 'estado_actualizado',
        ];

        $select = [];
        foreach ($want as $c) {
            if (in_array($c, $fields, true)) $select[] = $c;
        }

        // siempre incluir la columna de estado detectada
        if (!in_array($estadoCol, $select, true)) $select[] = $estadoCol;

        // si no hay nada, fallback
        if (!$select) return '*';

        return implode(',', array_unique($select));
    }

    private function normalizeOrderRow(array $r, string $estadoCol): array
    {
        $id = $r['id'] ?? $r['pedido_id'] ?? null;

        return [
            'id' => $id,
            'shopify_order_id' => $r['shopify_order_id'] ?? $r['order_id'] ?? null,
            'numero' => $r['numero'] ?? $r['name'] ?? ($id ? "#".$id : ""),
            'fecha' => $r['fecha'] ?? $r['created_at'] ?? null,
            'cliente' => $r['cliente'] ?? $r['customer_name'] ?? null,
            'total' => $r['total'] ?? $r['total_price'] ?? null,
            'estado' => $r[$estadoCol] ?? null,
            'articulos' => $r['articulos'] ?? $r['items_count'] ?? null,
            'estado_envio' => $r['estado_envio'] ?? $r['fulfillment_status'] ?? null,
            'forma_envio' => $r['forma_envio'] ?? $r['shipping_method'] ?? null,
            'last_status_change' => $r['last_status_change'] ?? [
                'user_name' => $r['estado_por'] ?? null,
                'changed_at' => $r['estado_actualizado'] ?? null,
            ],
        ];
    }

    private function getUserKey()
    {
        // Ajusta a tu sesión real. Esto cubre la mayoría de setups.
        $s = session();
        return $s->get('user_id')
            ?? $s->get('id')
            ?? $s->get('usuario_id')
            ?? $s->get('uid')
            ?? $s->get('email')
            ?? $s->get('username')
            ?? null;
    }

    private function getUserName(): string
    {
        $s = session();
        return (string)($s->get('nombre') ?? $s->get('name') ?? $s->get('username') ?? 'Sistema');
    }

    private function safeMeta($v)
    {
        // evita filtrar datos sensibles por accidente; solo para debug
        if ($v === null) return null;
        $s = (string)$v;
        if (strlen($s) > 32) return substr($s, 0, 10) . '…';
        return $s;
    }

    private function jsonOk(array $data = [])
    {
        // compat: success + ok
        $payload = array_merge(['success' => true, 'ok' => true], $data);
        return $this->response->setJSON($payload);
    }

    private function jsonFail(string $message, ?string $error = null)
    {
        $payload = ['success' => false, 'ok' => false, 'message' => $message];
        if ($error) $payload['error'] = $error;
        return $this->response->setStatusCode(500)->setJSON($payload);
    }
}
