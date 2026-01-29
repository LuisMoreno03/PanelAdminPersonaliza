<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class PorProducirController extends Controller
{
    /**
     * GET /porproducir
     */
    public function index()
    {
        // OJO: aquí tú ya dijiste que tu vista se llama "porproducir"
        // (si realmente es views/porproducir/porProducir.php entonces sería view('porproducir/porProducir'))
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

            // Detecta campos reales
            $fields = $db->getFieldNames($table);

            $pick = function(array $candidates) use ($fields) {
                foreach ($candidates as $c) {
                    if (in_array($c, $fields, true)) return $c;
                }
                return null;
            };

            // ✅ Aquí está el FIX: detecta cuál existe de verdad
            $colFulfillment = $pick(['fulfillment_status', 'fulfillmen_status', 'fulfilment_status', 'status']);
            if (!$colFulfillment) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => "No se encontró columna de estado de fulfillment. Busca: fulfillment_status / fulfillmen_status / status.",
                    'debug' => ['fields' => $fields],
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            // Otros campos (según tu tabla)
            $colNumero     = $pick(['numero']);
            $colCliente    = $pick(['cliente']);
            $colTotal      = $pick(['total']);
            $colMetodo     = $pick(['forma_envio']);
            $colEntrega    = $pick(['estado_envio']);
            $colEtiquetas  = $pick(['etiquetas']);
            $colArticulos  = $pick(['articulos']);
            $colCreated    = $pick(['created_at']);
            $colUpdated    = $pick(['last_change_at', 'updated_at', 'synced_at']);

            // Select con alias estándar para tu JS
            $select = [
                'id',
                ($colNumero ? "$colNumero AS numero_pedido" : "'' AS numero_pedido"),
                ($colCliente ? "$colCliente AS cliente" : "'' AS cliente"),
                ($colTotal ? "$colTotal AS total" : "0 AS total"),
                "`$colFulfillment` AS estado",
                ($colMetodo ? "$colMetodo AS metodo_entrega" : "'' AS metodo_entrega"),
                ($colEntrega ? "$colEntrega AS entrega" : "'' AS entrega"),
                ($colEtiquetas ? "$colEtiquetas AS etiquetas" : "'' AS etiquetas"),
                ($colArticulos ? "$colArticulos AS articulos" : "0 AS articulos"),
                ($colCreated ? "$colCreated AS created_at" : "NULL AS created_at"),
                ($colUpdated ? "$colUpdated AS updated_at" : "NULL AS updated_at"),
            ];

            $builder = $db->table($table)
                ->select(implode(', ', $select), false)
                ->where($colFulfillment, 'Diseñado');

            if ($colUpdated) $builder->orderBy($colUpdated, 'ASC');
            else $builder->orderBy('id', 'ASC');

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
     * Body: { id, metodo_entrega }
     *
     * Regla:
     * - Si metodo_entrega == "Enviado":
     *      estado_envio = "Enviado"
     *      (fulfillment_status o fulfillmen_status) = "Enviado"
     *      remove_from_list = true
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

            // Detecta columna fulfillment real
            $colFulfillment = $pick(['fulfillment_status', 'fulfillmen_status', 'fulfilment_status', 'status']);
            if (!$colFulfillment) {
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'message' => "No se encontró columna de estado de fulfillment.",
                    'csrf' => $this->freshCsrf(),
                ]);
            }

            $colMetodo  = $pick(['forma_envio']);
            $colEntrega = $pick(['estado_envio']);
            $colUpdated = $pick(['last_change_at', 'updated_at']);

            // Leer pedido
            $pedido = $db->table($table)
                ->select("id, `$colFulfillment` AS estado, " .
                    ($colMetodo ? "$colMetodo AS forma_envio, " : "") .
                    ($colEntrega ? "$colEntrega AS estado_envio" : "'' AS estado_envio")
                , false)
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

            $metodoLower = mb_strtolower($metodo);
            $nuevoEstadoFulfillment = $pedido['estado'];
            $nuevoEstadoEnvio = $pedido['estado_envio'];

            if ($metodoLower === 'enviado') {
                $nuevoEstadoEnvio = 'Enviado';
                $nuevoEstadoFulfillment = 'Enviado';
            }

            $now = date('Y-m-d H:i:s');

            $update = [];
            if ($colMetodo)  $update[$colMetodo] = $metodo;
            if ($colEntrega) $update[$colEntrega] = $nuevoEstadoEnvio;
            $update[$colFulfillment] = $nuevoEstadoFulfillment;
            if ($colUpdated) $update[$colUpdated] = $now;

            $db->table($table)->where('id', $id)->update($update);

            $remove = ($nuevoEstadoFulfillment !== 'Diseñado');

            return $this->response->setJSON([
                'ok' => true,
                'id' => $id,
                'metodo_entrega' => $metodo,
                'estado_envio' => $nuevoEstadoEnvio,
                'estado' => $nuevoEstadoFulfillment,
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
