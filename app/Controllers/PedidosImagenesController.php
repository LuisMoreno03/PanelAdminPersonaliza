<?php

namespace App\Controllers;

use App\Models\PedidoImagenModel;

class PedidosImagenesController extends BaseController
{
    public function subir()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderId   = (int) ($this->request->getPost('order_id') ?? 0);
        $lineIndex = (int) ($this->request->getPost('line_index') ?? -1);
        $file      = $this->request->getFile('file');

        if ($orderId <= 0 || $lineIndex < 0) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Parámetros inválidos (order_id / line_index)',
            ])->setStatusCode(200);
        }

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Archivo inválido',
            ])->setStatusCode(200);
        }

        // =====================================================
        // 1) Guardar archivo en carpeta pública
        // =====================================================
        $dir = FCPATH . "uploads/pedidos/{$orderId}/";
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ext  = strtolower($file->getExtension() ?: 'jpg');
        $name = "item_{$lineIndex}." . $ext;

        $file->move($dir, $name, true);

        $url = base_url("uploads/pedidos/{$orderId}/{$name}");
        $now = date('Y-m-d H:i:s');

        // =====================================================
        // 2) Guardar en BD (robusto: solo columnas existentes)
        // =====================================================
        try {
            $db = \Config\Database::connect();

            $table = 'pedido_imagenes'; // <-- asegúrate que este sea el nombre REAL

            // Soft-check tabla existe
            $dbName = $db->getDatabase();
            $tbl = $db->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?
                 LIMIT 1",
                [$dbName, $table]
            )->getRowArray();

            if (empty($tbl)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => "La tabla {$table} no existe",
                ])->setStatusCode(200);
            }

            // helper rápido
            $has = function(string $col) use ($db, $table): bool {
                try {
                    return $db->fieldExists($col, $table);
                } catch (\Throwable $e) {
                    return false;
                }
            };

            $userId   = (int) (session('user_id') ?? 0);
            $userName = (string) (session('nombre') ?? session('user_name') ?? 'Sistema');

            // Data base mínima
            $data = [];

            // llaves
            if ($has('order_id'))   $data['order_id'] = $orderId;
            if ($has('line_index')) $data['line_index'] = $lineIndex;

            // urls
            if ($has('local_url'))  $data['local_url']  = $url;

            // status / timestamps
            if ($has('status'))     $data['status']     = 'ready';
            if ($has('updated_at')) $data['updated_at'] = $now;
            if ($has('created_at')) $data['created_at'] = $now; // si es replace, se sobreescribe; si no quieres, lo controlamos luego

            // usuario (compatibilidad)
            if ($has('uploaded_by')) $data['uploaded_by'] = $userId ?: $userName; // depende tu diseño
            if ($has('user_id'))     $data['user_id']     = $userId ?: null;
            if ($has('user_name'))   $data['user_name']   = $userName;

            // Si tu tabla NO tiene order_id/line_index pero sí "id" único por fila:
            // (raro, pero por si acaso)
            if (empty($data) || (!isset($data['order_id']) && !isset($data['id']))) {
                // fallback: intenta con replace simple estilo "id"
                if ($has('id')) $data['id'] = "{$orderId}_{$lineIndex}";
            }

            // Si NO quieres que created_at cambie en un replace:
            if ($has('created_at')) {
                // si existe fila, no tocar created_at
                $exists = $db->table($table)
                    ->where($has('order_id') ? 'order_id' : 'id', $has('order_id') ? $orderId : "{$orderId}_{$lineIndex}")
                    ->where($has('line_index') ? 'line_index' : 'id', $has('line_index') ? $lineIndex : "{$orderId}_{$lineIndex}")
                    ->limit(1)
                    ->get()
                    ->getRowArray();

                if (!empty($exists)) {
                    unset($data['created_at']);
                }
            }

            // UPSERT
            // Ideal: UNIQUE(order_id,line_index) -> replace funciona perfecto.
            $db->table($table)->replace($data);

        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error subiendo imagen: ' . $e->getMessage(),
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
        ])->setStatusCode(200);
    }
}
