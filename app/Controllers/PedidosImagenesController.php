<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class PedidosImagenesController extends Controller
{
    public function subir()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ]);
        }

        $orderId = $this->request->getPost('order_id');
        $index   = $this->request->getPost('index');

        if (!$orderId || $index === null) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan parámetros: order_id / index'
            ]);
        }

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Archivo inválido'
            ]);
        }

        $dir = FCPATH . 'uploads/pedidos/' . $orderId . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $ext = $file->getExtension() ?: 'jpg';
        $filename = 'mod_' . $orderId . '_' . $index . '_' . time() . '.' . $ext;

        $file->move($dir, $filename);

        $relative = 'uploads/pedidos/' . $orderId . '/' . $filename;

        helper('url');
        $url = base_url($relative);

        return $this->response->setJSON([
            'success' => true,
            'url' => $url,
            'path' => $relative,
            'order_id' => (string)$orderId,
            'index' => (int)$index,
        ]);
    }
}
