<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class PedidosController extends BaseController
{
    // ✅ GET /placas/pedidos/por-producir
    public function porProducir(): ResponseInterface
    {
        try {
            $db = db_connect();

            // ✅ Detectar tabla real (ajusta si sabes el nombre exacto)
            $candidatas = ['pedidos', 'orders', 'ordenes'];
            $tabla = null;
            foreach ($candidatas as $t) {
                if ($db->tableExists($t)) { $tabla = $t; break; }
            }

            if (!$tabla) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'No se encontró tabla de pedidos (pedidos/orders/ordenes).',
                ]);
            }

            // ✅ Detectar columnas
            $colEstado = $db->fieldExists('estado_interno', $tabla) ? 'estado_interno'
                      : ($db->fieldExists('estado', $tabla) ? 'estado' : null);

            if (!$colEstado) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => "No existe columna estado_interno ni estado en {$tabla}.",
                ]);
            }

            $colNumero  = $db->fieldExists('numero', $tabla) ? 'numero'
                       : ($db->fieldExists('number', $tabla) ? 'number' : 'id');

            $colCliente = $db->fieldExists('cliente', $tabla) ? 'cliente'
                       : ($db->fieldExists('customer', $tabla) ? 'customer' : null);

            $colFecha   = $db->fieldExists('fecha', $tabla) ? 'fecha'
                       : ($db->fieldExists('created_at', $tabla) ? 'created_at' : null);

            $q = trim((string) $this->request->getGet('q'));

            $builder = $db->table($tabla);
            $builder->select('id');

            $builder->select($colNumero . ' as numero');
            if ($colCliente) $builder->select($colCliente . ' as cliente');
            if ($colFecha)   $builder->select($colFecha . ' as fecha');

            $builder->where($colEstado, 'Por producir');
            $builder->orderBy('id', 'DESC');
            $builder->limit(500);

            if ($q !== '') {
                $builder->groupStart();
                $builder->like($colNumero, $q);
                if ($colCliente) $builder->orLike($colCliente, $q);
                $builder->groupEnd();
            }

            $orders = $builder->get()->getResultArray();

            return $this->response->setJSON([
                'success' => true,
                'orders' => $orders,
            ]);

        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
