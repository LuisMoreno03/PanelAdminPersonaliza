<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class PedidosImagenesController extends Controller
{
    public function subir()
    {
        // ✅ Requiere sesión
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

        // ✅ Carpeta destino
        $dir = FCPATH . 'uploads/pedidos/' . $orderId . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // ✅ Nombre final
        $ext = $file->getExtension() ?: 'jpg';
        $filename = 'mod_' . $orderId . '_' . $index . '_' . time() . '.' . $ext;

        // ✅ Mover archivo
        $file->move($dir, $filename);

        $relative = 'uploads/pedidos/' . $orderId . '/' . $filename;

        // ✅ URL pública
        helper('url');
        $url = base_url($relative);

        // 🔥 Aquí luego podemos guardar en BD (te lo dejo listo para el siguiente paso)
        // Por ahora devolvemos url para pintar en el modal.

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Imagen subida',
            'order_id' => (string)$orderId,
            'index' => (int)$index,
            'url' => $url,
            'path' => $relative,
        ]);
    }
}
