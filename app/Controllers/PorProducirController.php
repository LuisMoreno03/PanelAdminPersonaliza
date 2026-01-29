<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class PorProducirController extends Controller
{
    /**
     * GET /porproducir
     * Vista: views/porproducir/porProducir.php
     */
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
        $limit = (int) ($this->request->getGet('limit') ?? 10);
        if (!in_array($limit, [5, 10], true)) $limit = 10;

        // Ajusta el nombre de la tabla/campos a tu BD real
        $rows = db_connect()
            ->table('pedidos')
            ->select('
                id,
                numero_pedido,
                cliente,
                total,
                estado,
                metodo_entrega,
                created_at,
                updated_at
            ')
            ->where('estado', 'Diseñado')
            ->orderBy('updated_at', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'ok'    => true,
            'limit' => $limit,
            'data'  => $rows,
        ]);
    }

    /**
     * POST /porproducir/update-metodo
     * Body JSON: { id, metodo_entrega }
     *
     * Si metodo_entrega == "Enviado" => estado pasa automáticamente a "Enviado"
     * y debe salir de la lista (remove_from_list = true)
     */
    public function updateMetodo(): ResponseInterface
    {
        // Acepta JSON o form-data
        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = [];

        $id = $payload['id'] ?? $this->request->getPost('id');
        $metodo = $payload['metodo_entrega'] ?? $this->request->getPost('metodo_entrega');

        $id = (int) $id;
        $metodo = trim((string) $metodo);

        if ($id <= 0 || $metodo === '') {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'ok'      => false,
                    'message' => 'Datos inválidos (id / metodo_entrega)',
                    'csrf'    => $this->freshCsrf(),
                ]);
        }

        $db = db_connect();
        $builder = $db->table('pedidos');

        // Bloque transacción para evitar carreras
        $db->transStart();

        $pedido = $builder
            ->select('id, estado, metodo_entrega')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$pedido) {
            $db->transComplete();
            return $this->response
                ->setStatusCode(404)
                ->setJSON([
                    'ok'      => false,
                    'message' => 'Pedido no encontrado',
                    'csrf'    => $this->freshCsrf(),
                ]);
        }

        $metodoLower = mb_strtolower($metodo);
        $nuevoEstado = $pedido['estado'];

        // REGLA: si método pasa a Enviado => estado Enviado
        if ($metodoLower === 'enviado') {
            $nuevoEstado = 'Enviado';
        }

        // Update
        $builder
            ->where('id', $id)
            ->update([
                'metodo_entrega' => $metodo,
                'estado'         => $nuevoEstado,
                // Si tu tabla no tiene updated_at, quita esta línea
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok'      => false,
                    'message' => 'Error al actualizar el pedido',
                    'csrf'    => $this->freshCsrf(),
                ]);
        }

        // Si ya no está en Diseñado, debe salir de "Por producir"
        $remove = ($nuevoEstado !== 'Diseñado');

        return $this->response->setJSON([
            'ok'              => true,
            'id'              => $id,
            'metodo_entrega'  => $metodo,
            'estado'          => $nuevoEstado,
            'remove_from_list'=> $remove,
            'csrf'            => $this->freshCsrf(),
        ]);
    }

    /**
     * Devuelve CSRF actualizado (útil si CI rota token).
     */
    private function freshCsrf(): array
    {
        return [
            'token'  => csrf_hash(),
            'header' => csrf_header(),
        ];
    }
}
