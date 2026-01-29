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
        return view('porproducir/porProducir');
    }

    /**
     * GET /porproducir/pull?limit=5|10
     * Trae 5 o 10 pedidos con fulfillmen_status = "Diseñado"
     */
    public function pull(): ResponseInterface
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 10);
            if (!in_array($limit, [5, 10], true)) $limit = 10;

            $db = db_connect();

            // Tabla y columnas reales (según tu captura)
            $rows = $db->table('pedidos')
                ->select([
                    'id',
                    'numero AS numero_pedido',
                    'cliente',
                    'total',
                    'fulfillmen_status AS estado',
                    'forma_envio AS metodo_entrega',
                    'estado_envio AS entrega',
                    'etiquetas',
                    'articulos',
                    'created_at',
                    'last_change_at AS updated_at',
                ])
                ->where('fulfillmen_status', 'Diseñado')
                ->orderBy('last_change_at', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

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
     * Body JSON o form-data:
     *  - id
     *  - metodo_entrega  (esto actualiza forma_envio)
     *
     * Regla:
     *  - Si metodo_entrega == "Enviado":
     *      estado_envio = "Enviado"
     *      fulfillmen_status = "Enviado"
     *      remove_from_list = true
     */
    public function updateMetodo(): ResponseInterface
    {
        try {
            // Acepta JSON o form-data
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

            // Buscar pedido
            $pedido = $db->table('pedidos')
                ->select('id, fulfillmen_status, forma_envio, estado_envio')
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

            // Valores por defecto (mantener si no aplica regla)
            $nuevoFulfillmen = $pedido['fulfillmen_status'];
            $nuevoEstadoEnvio = $pedido['estado_envio'];

            // REGLA: si método pasa a Enviado => estado_envio y fulfillmen_status pasan a Enviado
            if ($metodoLower === 'enviado') {
                $nuevoEstadoEnvio = 'Enviado';
                $nuevoFulfillmen  = 'Enviado';
            }

            $now = date('Y-m-d H:i:s');

            // Update en BD
            $db->table('pedidos')
                ->where('id', $id)
                ->update([
                    'forma_envio'       => $metodo,
                    'estado_envio'      => $nuevoEstadoEnvio,
                    'fulfillmen_status' => $nuevoFulfillmen,
                    'last_change_at'    => $now,
                ]);

            // Si ya no está en Diseñado, se quita de la lista
            $remove = ($nuevoFulfillmen !== 'Diseñado');

            return $this->response->setJSON([
                'ok'               => true,
                'id'               => $id,
                'metodo_entrega'   => $metodo,
                'estado_envio'     => $nuevoEstadoEnvio,
                'estado'           => $nuevoFulfillmen,
                'remove_from_list' => $remove,
                'csrf'             => $this->freshCsrf(),
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
