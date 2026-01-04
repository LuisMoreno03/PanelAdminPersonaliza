<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class EstadoController extends Controller
{
    public function guardar()
    {
        // Forzar JSON response
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');

        try {
            if (!session()->get('logged_in')) {
                return $this->response->setStatusCode(401)->setJSON([
                    'success' => false,
                    'message' => 'No autenticado'
                ]);
            }

            // Leer JSON (fetch application/json)
            $payload = $this->request->getJSON(true);
            $id      = isset($payload['id']) ? trim((string)$payload['id']) : '';
            $estado  = isset($payload['estado']) ? trim((string)$payload['estado']) : '';

            if ($id === '' || $estado === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Faltan campos: id/estado'
                ]);
            }

            $userName = (string) (session('nombre') ?? session('user') ?? 'Sistema');
            $now      = date('Y-m-d H:i:s');

            // ✅ Tabla local recomendada: order_status
            // order_id (varchar/int), estado (varchar), updated_at (datetime), updated_by (varchar)
            $db = \Config\Database::connect();

            // Upsert simple (si existe lo actualiza, si no lo inserta)
            $exists = $db->table('order_status')
                ->select('order_id')
                ->where('order_id', $id)
                ->get()
                ->getRowArray();

            if ($exists) {
                $db->table('order_status')
                    ->where('order_id', $id)
                    ->update([
                        'estado'     => $estado,
                        'updated_at' => $now,
                        'updated_by' => $userName,
                    ]);
            } else {
                $db->table('order_status')->insert([
                    'order_id'   => $id,
                    'estado'     => $estado,
                    'updated_at' => $now,
                    'updated_by' => $userName,
                ]);
            }

            return $this->response->setJSON([
                'success' => true,
                'order' => [
                    'id' => $id,
                    'estado' => $estado,
                    'last_status_change' => [
                        'user_name'  => $userName,
                        'changed_at' => $now,
                    ]
                ]
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'EstadoController::guardar ERROR: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error interno guardando estado',
                // ⚠️ si estás en production mejor no devolver detalle
                // 'detail' => $e->getMessage(),
            ]);
        }
    }
}
