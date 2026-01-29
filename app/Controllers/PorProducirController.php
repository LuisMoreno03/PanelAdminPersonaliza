<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class PorProducirController extends Controller
{
    public function index()
    {
        return view('porproducir');
    }

    /**
     * GET /porproducir/pull?limit=5|10
     * Trae 5 o 10 pedidos en estado "Diseñado"
     */
    public function pull(): ResponseInterface
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 10);
            if (!in_array($limit, [5, 10], true)) $limit = 10;

            $db = db_connect();
            $table = 'pedidos';

            // Detectar columnas reales
            $fields = $db->getFieldNames($table);

            // Helpers para escoger el primer campo existente
            $pick = function(array $candidates) use ($fields) {
                foreach ($candidates as $c) {
                    if (in_array($c, $fields, true)) return $c;
                }
                return null;
            };

            // Columnas posibles en tu BD (ajusta si tienes otras)
            $colId     = $pick(['id', 'pedido_id']);
            $colPedido = $pick(['numero_pedido', 'pedido', 'order_name', 'nombre_pedido', 'num_pedido']);
            $colCliente= $pick(['cliente', 'customer', 'customer_name', 'nombre_cliente', 'nombre']);
            $colTotal  = $pick(['total', 'importe_total', 'monto', 'precio_total']);
            $colEstado = $pick(['estado', 'status']);
            $colMetodo = $pick(['metodo_entrega', 'metodo', 'delivery_method']);
            $colCreated= $pick(['created_at', 'fecha', 'created']);
            $colUpdated= $pick(['updated_at', 'ultimo_cambio', 'updated']);

            if (!$colId || !$colEstado) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => "La tabla '$table' debe tener al menos columnas: id y estado (o status).",
                    'debug' => ['fields' => $fields],
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            // SELECT seguro con alias estándar para el JS
            $selectParts = [];
            $selectParts[] = "$colId AS id";

            if ($colPedido)  $selectParts[] = "$colPedido AS numero_pedido";
            else             $selectParts[] = "'' AS numero_pedido";

            if ($colCliente) $selectParts[] = "$colCliente AS cliente";
            else             $selectParts[] = "'' AS cliente";

            if ($colTotal)   $selectParts[] = "$colTotal AS total";
            else             $selectParts[] = "0 AS total";

            $selectParts[] = "$colEstado AS estado";

            if ($colMetodo)  $selectParts[] = "$colMetodo AS metodo_entrega";
            else             $selectParts[] = "'' AS metodo_entrega";

            if ($colCreated) $selectParts[] = "$colCreated AS created_at";
            else             $selectParts[] = "NULL AS created_at";

            if ($colUpdated) $selectParts[] = "$colUpdated AS updated_at";
            else             $selectParts[] = "NULL AS updated_at";

            $builder = $db->table($table)->select(implode(', ', $selectParts));

            // Estado "Diseñado"
            $builder->where($colEstado, 'Diseñado');

            // Orden: si hay updated_at úsalo, si no created_at, si no id
            if ($colUpdated) $builder->orderBy($colUpdated, 'ASC');
            else if ($colCreated) $builder->orderBy($colCreated, 'ASC');
            else $builder->orderBy($colId, 'ASC');

            $rows = $builder->limit($limit)->get()->getResultArray();

            return $this->response->setJSON([
                'ok'    => true,
                'limit' => $limit,
                'data'  => $rows,
                'csrf'  => $this->freshCsrf(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
                'csrf' => $this->freshCsrf(),
            ]);
        }
    }

    /**
     * POST /porproducir/update-metodo
     * Body JSON: { id, metodo_entrega }
     *
     * Si metodo_entrega == "Enviado" => estado pasa a "Enviado"
     * y remove_from_list = true
     */
    public function updateMetodo(): ResponseInterface
    {
        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) $payload = [];

            $id = (int) ($payload['id'] ?? $this->request->getPost('id'));
            $metodo = trim((string) ($payload['metodo_entrega'] ?? $this->request->getPost('metodo_entrega')));

            if ($id <= 0 || $metodo === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'ok' => false,
                    'message' => 'Datos inválidos (id / metodo_entrega)',
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            $db = db_connect();
            $table = 'pedidos';
            $fields = $db->getFieldNames($table);

            $pick = function(array $candidates) use ($fields) {
                foreach ($candidates as $c) {
                    if (in_array($c, $fields, true)) return $c;
                }
                return null;
            };

            $colId     = $pick(['id', 'pedido_id']);
            $colEstado = $pick(['estado', 'status']);
            $colMetodo = $pick(['metodo_entrega', 'metodo', 'delivery_method']);
            $colUpdated= $pick(['updated_at', 'ultimo_cambio', 'updated']);

            if (!$colId || !$colEstado) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => "La tabla '$table' debe tener id y estado/status.",
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            // Leer pedido
            $pedido = $db->table($table)
                ->select("$colId AS id, $colEstado AS estado" . ($colMetodo ? ", $colMetodo AS metodo_entrega" : ""))
                ->where($colId, $id)
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'ok' => false,
                    'message' => 'Pedido no encontrado',
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            $metodoLower = mb_strtolower($metodo);
            $nuevoEstado = $pedido['estado'];

            // REGLA: si método pasa a Enviado => estado Enviado
            if ($metodoLower === 'enviado') {
                $nuevoEstado = 'Enviado';
            }

            $dataUpdate = [];
            if ($colMetodo) $dataUpdate[$colMetodo] = $metodo;
            $dataUpdate[$colEstado] = $nuevoEstado;

            // Updated_at si existe
            if ($colUpdated) $dataUpdate[$colUpdated] = date('Y-m-d H:i:s');

            $db->table($table)->where($colId, $id)->update($dataUpdate);

            $remove = ($nuevoEstado !== 'Diseñado');

            return $this->response->setJSON([
                'ok' => true,
                'id' => $id,
                'metodo_entrega' => $metodo,
                'estado' => $nuevoEstado,
                'remove_from_list' => $remove,
                'csrf' => $this->freshCsrf(),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
                'csrf' => $this->freshCsrf(),
            ]);
        }
    }

    private function freshCsrf(): array
    {
        return [
            'token'  => csrf_hash(),
            'header' => csrf_header(),
        ];
    }
}
