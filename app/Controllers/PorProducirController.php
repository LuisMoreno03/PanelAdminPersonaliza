<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class PorProducirController extends Controller
{
    public function index()
    {
        return view('porproducir'); // tu view actual
    }

    /**
     * GET /porproducir/pull?limit=5|10
     * Ahora: trae TODOS los pedidos en estado "Por producir"
     * (si mandas limit, lo respeta, si no, trae todos)
     */
    public function pull(): ResponseInterface
    {
        try {
            $db = db_connect();
            $table = 'pedidos';
            $fields = $db->getFieldNames($table);

            $pick = function(array $candidates) use ($fields) {
                foreach ($candidates as $c) {
                    if (in_array($c, $fields, true)) return $c;
                }
                return null;
            };

            // Estado real (detecta el nombre correcto)
            $colEstado = $pick(['fulfillment_status', 'fulfillmen_status', 'fulfilment_status', 'status']);
            if (!$colEstado) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => "No se encontró columna de estado (fulfillment_status / fulfillmen_status).",
                    'debug' => ['fields' => $fields],
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            // Columnas reales
            $colNumero   = $pick(['numero']);
            $colCliente  = $pick(['cliente']);
            $colTotal    = $pick(['total']);
            $colMetodo   = $pick(['forma_envio']);
            $colEntrega  = $pick(['estado_envio']);
            $colEtiquetas= $pick(['etiquetas']);
            $colArticulos= $pick(['articulos']);
            $colCreated  = $pick(['created_at']);
            $colUpdated  = $pick(['last_change_at', 'updated_at', 'synced_at']);

            // Si viene limit (5/10) lo aplicamos, si no, traemos todos
            $limitParam = $this->request->getGet('limit');
            $useLimit = false;
            $limit = 0;

            if ($limitParam !== null && $limitParam !== '') {
                $limit = (int) $limitParam;
                if ($limit > 0) $useLimit = true;
            }

            // SELECT con alias estándar para el JS
            $select = [
                'id',
                ($colNumero ? "$colNumero AS numero_pedido" : "'' AS numero_pedido"),
                ($colCliente ? "$colCliente AS cliente" : "'' AS cliente"),
                ($colTotal ? "$colTotal AS total" : "0 AS total"),
                "`$colEstado` AS estado",
                ($colMetodo ? "$colMetodo AS metodo_entrega" : "'' AS metodo_entrega"),
                ($colEntrega ? "$colEntrega AS entrega" : "'' AS entrega"),
                ($colEtiquetas ? "$colEtiquetas AS etiquetas" : "'' AS etiquetas"),
                ($colArticulos ? "$colArticulos AS articulos" : "0 AS articulos"),
                ($colCreated ? "$colCreated AS created_at" : "NULL AS created_at"),
                ($colUpdated ? "$colUpdated AS updated_at" : "NULL AS updated_at"),
            ];

            $builder = $db->table($table)
                ->select(implode(', ', $select), false)
                ->where($colEstado, 'Por producir');

            // Orden
            if ($colUpdated) $builder->orderBy($colUpdated, 'ASC');
            else $builder->orderBy('id', 'ASC');

            if ($useLimit) {
                $builder->limit($limit);
            }

            $rows = $builder->get()->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'total' => count($rows),
                'data' => $rows,
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

    /**
     * POST /porproducir/update-metodo
     * Body: { id, metodo_entrega }
     *
     * Si metodo_entrega == "Enviado":
     *  - estado_envio = "Enviado"
     *  - fulfillment_status = "Enviado"
     *  - remove_from_list = true
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

            $colEstado  = $pick(['fulfillment_status', 'fulfillmen_status', 'fulfilment_status', 'status']);
            $colMetodo  = $pick(['forma_envio']);
            $colEntrega = $pick(['estado_envio']);
            $colUpdated = $pick(['last_change_at', 'updated_at']);

            if (!$colEstado) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => 'No se encontró columna de estado fulfillment.',
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            // Buscar pedido
            $pedido = $db->table($table)
                ->select("id, `$colEstado` AS estado", false)
                ->where('id', $id)
                ->get()
                ->getRowArray();

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'ok' => false,
                    'message' => 'Pedido no encontrado',
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            $now = date('Y-m-d H:i:s');
            $metodoLower = mb_strtolower($metodo);

            $update = [];

            // Siempre actualizamos método si existe columna
            if ($colMetodo) $update[$colMetodo] = $metodo;

            // Si pasa a enviado => cambia estado y sale de la lista
            $nuevoEstado = $pedido['estado'];
            $nuevoEstadoEnvio = null;
            $remove = false;

            if ($metodoLower === 'enviado') {
                $nuevoEstado = 'Enviado';
                $update[$colEstado] = 'Enviado';
                $remove = true;

                if ($colEntrega) {
                    $nuevoEstadoEnvio = 'Enviado';
                    $update[$colEntrega] = 'Enviado';
                }
            }

            if ($colUpdated) $update[$colUpdated] = $now;

            $db->table($table)->where('id', $id)->update($update);

            return $this->response->setJSON([
                'ok' => true,
                'id' => $id,
                'metodo_entrega' => $metodo,
                'estado' => $nuevoEstado,
                'estado_envio' => $nuevoEstadoEnvio,
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
