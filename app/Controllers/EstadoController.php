<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class EstadoController extends Controller
{
    public function guardar(): ResponseInterface
    {
        try {
            // ✅ Seguridad: debe estar logueado
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON([
                    'success' => false,
                    'message' => 'No autenticado',
                ]);
            }

            // ✅ Leer JSON (o fallback POST)
            $data = $this->request->getJSON(true);
            if (!is_array($data)) $data = $this->request->getPost();

            $id = (string)($data['id'] ?? '');
            $estado = trim((string)($data['estado'] ?? ''));

            if ($id === '' || $estado === '') {
                return $this->response->setStatusCode(200)->setJSON([
                    'success' => false,
                    'message' => 'Faltan datos (id/estado)',
                ]);
            }

            $db = \Config\Database::connect();

            // ✅ Guarda estado en tu tabla pedidos_estado
            // IMPORTANTE: ajusta nombres de columnas si difieren
            $db->table('pedidos_estado')->insert([
                'id'         => $id,
                'estado'     => $estado,
                'user_id'    => session('user_id'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // ✅ Respuesta para sincronizar UI
            return $this->response->setStatusCode(200)->setJSON([
                'success' => true,
                'order' => [
                    'id' => $id,
                    'estado' => $estado,
                    'last_status_change' => [
                        'user_name'  => session('nombre') ?? 'Sistema',
                        'changed_at' => date('Y-m-d H:i:s'),
                    ],
                ],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'EstadoController::guardar ERROR: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error interno guardando estado',
            ]);
        }
    }
}
