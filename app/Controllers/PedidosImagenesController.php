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

        // carpeta pública
        $dir = FCPATH . "uploads/pedidos/{$orderId}/";
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // nombre consistente por línea
        $ext = strtolower($file->getExtension() ?: 'jpg');
        $name = "item_{$lineIndex}." . $ext;

        $file->move($dir, $name, true);

        $url = base_url("uploads/pedidos/{$orderId}/{$name}");

        // Guardar en BD
        $model = new PedidoImagenModel();
        $ok = $model->upsertImagen(
            $orderId,
            $lineIndex,
            $url,
            (int) (session('user_id') ?? 0),
            (string) (session('nombre') ?? session('user_name') ?? 'Sistema')
        );

        if (!$ok) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No se pudo guardar en BD',
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
        ])->setStatusCode(200);
    }
}
