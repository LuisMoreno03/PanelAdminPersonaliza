<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;
use App\Models\PedidosEstadoModel; // <- lo creas (abajo)

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

        // OJO: Shopify ID puede ser BIGINT => PHP int 64-bit OK en hosting normal
        $orderId    = (int) ($this->request->getPost('order_id') ?? 0);
        $lineIndex  = (int) ($this->request->getPost('line_index') ?? -1);

        // Para estado automático (lo manda el front)
        $requiredTotal = (int) ($this->request->getPost('required_total') ?? 0);

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
                'errors'  => $file->getErrorString(),
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

        // Guardado físico (más robusto)
        $dir = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pedidos' . DIRECTORY_SEPARATOR . $orderId;

        if (!is_dir($dir)) {
            // en hostings a veces 0755 falla => usa 0775
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'message' => 'No se pudo crear carpeta de uploads (permisos).',
                    'dir' => $dir,
                ]);
            }
        }

        // nombre seguro
        $name = 'mod_' . $lineIndex . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

        try {
            $file->move($dir, $name, true);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error moviendo el archivo',
                'error' => $e->getMessage(),
            ]);
        }

        if (!$file->hasMoved()) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'No se pudo guardar el archivo (hasMoved=false).',
            ]);
        }

        $relative = 'uploads/pedidos/' . $orderId . '/' . $name;
        $url      = base_url($relative);

        // Guardado en BD (upsert)
        $model = new PedidoImagenModel();

        $userId   = session()->get('id') ? (int) session()->get('id') : null;
        $userName = session()->get('nombre') ? (string) session()->get('nombre') : null;

        $ok = $model->upsertImagen($orderId, $lineIndex, $url, $userId, $userName);

        if (!$ok) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'No se pudo guardar en BD',
            ]);
        }

        // ==========================================================
        // ✅ Estado automático según imágenes completas
        // - required_total lo manda el frontend
        // - si required_total=0 => no tocamos estado
        // ==========================================================
        $estadoImagenes = null;
        $savedCount = null;

        if ($requiredTotal > 0) {
            $imagenesLocales = $model->getByOrder($orderId);
            $savedCount = count($imagenesLocales);

            // Si guardas SOLO imágenes requeridas, este conteo sirve perfecto.
            // Si en el futuro guardas también “no requeridas”, entonces hay que comparar por índices requeridos.
            if ($savedCount >= $requiredTotal) {
                $estadoImagenes = 'Producción';
            } else {
                $estadoImagenes = 'A medias';
            }

            // Guardar estado en tu BD (tabla pedidos_estado)
            $estadoModel = new PedidosEstadoModel();
            $estadoModel->setEstadoImagenes($orderId, $estadoImagenes, $userId, $userName);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Imagen guardada',
            'url' => $url,
            'relative' => $relative,
            'order_id' => $orderId,
            'line_index' => $lineIndex,
            'estado_imagenes' => $estadoImagenes,
            'required_total' => $requiredTotal,
            'saved_count' => $savedCount,
        ]);
    }
}
