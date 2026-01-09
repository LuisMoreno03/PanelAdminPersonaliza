<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;

class PedidosImagenesController extends BaseController
{
    public function subir(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderId = (int) ($this->request->getPost('order_id') ?? 0);
        $lineIndex = (int) ($this->request->getPost('line_index') ?? -1);

        $file = $this->request->getFile('file');

        if ($orderId <= 0 || $lineIndex < 0 || !$file) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan parámetros: order_id / line_index / file',
            ]);
        }

        if (!$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Archivo inválido',
            ]);
        }

        $ext = strtolower($file->getExtension() ?: '');
        $allowed = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed, true)) {
            return $this->response->setStatusCode(415)->setJSON([
                'success' => false,
                'message' => 'Formato no permitido. Usa JPG/PNG/WEBP.',
            ]);
        }

        // Guardado físico
        $dir = FCPATH . 'uploads/pedidos/' . $orderId;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $name = 'mod_' . $lineIndex . '_' . date('Ymd_His') . '.' . $ext;
        $file->move($dir, $name, true);

        $relative = 'uploads/pedidos/' . $orderId . '/' . $name;
        $url = base_url($relative);

        // Guardado en BD (upsert)
        $model = new PedidoImagenModel();
        $userId = session()->get('id') ? (int) session()->get('id') : null;
        $userName = session()->get('nombre') ? (string) session()->get('nombre') : null;

        $ok = $model->upsertImagen($orderId, $lineIndex, $url, $userId, $userName);

        if (!$ok) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'No se pudo guardar en BD',
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Imagen guardada',
            'url' => $url,
            'relative' => $relative,
            'order_id' => $orderId,
            'line_index' => $lineIndex,
        ]);
    }
}
