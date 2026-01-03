<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;

class PlacasArchivosController extends BaseController
{
    private string $publicDir;

    public function __construct()
    {
        $this->publicDir = FCPATH . 'uploads/placas/';
        if (!is_dir($this->publicDir)) {
            @mkdir($this->publicDir, 0775, true);
        }
    }

    public function listar()
    {
        $model = new PlacaArchivoModel();
        $items = $model->orderBy('id', 'DESC')->findAll();

        // Agregar url pública (preview)
        foreach ($items as &$it) {
            $it['url'] = base_url('uploads/placas/' . basename($it['ruta']));
        }

        return $this->response->setJSON([
            'success' => true,
            'items'   => $items,
        ]);
    }

    public function stats()
    {
        $model = new PlacaArchivoModel();
        $hoy = date('Y-m-d');

        $totalHoy = $model->where('dia', $hoy)->countAllResults();

        return $this->response->setJSON([
            'success' => true,
            'hoy' => $hoy,
            'totalHoy' => $totalHoy,
        ]);
    }

    public function subir()
    {
        $file = $this->request->getFile('archivo');
        $nombre = trim((string) $this->request->getPost('nombre'));

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido'])->setStatusCode(400);
        }

        $maxSize = 15 * 1024 * 1024; // 15MB
        if ($file->getSize() > $maxSize) {
            return $this->response->setJSON(['success' => false, 'message' => 'Máximo 15MB'])->setStatusCode(422);
        }

        $allowed = ['pdf','png','jpg','jpeg','webp'];
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $allowed, true)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Solo PDF o imágenes (png/jpg/webp)'])->setStatusCode(422);
        }

        if ($nombre === '') {
            $nombre = pathinfo($file->getClientName(), PATHINFO_FILENAME);
        }

        $newName = $file->getRandomName();
        $file->move($this->publicDir, $newName);

        $ruta = 'uploads/placas/' . $newName;
        $dia  = date('Y-m-d');

        $model = new PlacaArchivoModel();
        $id = $model->insert([
            'nombre'   => $nombre,
            'original' => $file->getClientName(),
            'ruta'     => $ruta,
            'mime'     => $file->getClientMimeType(),
            'size'     => $file->getSize(),
            'dia'      => $dia,
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Placa subida',
            'item' => [
                'id' => $id,
                'nombre' => $nombre,
                'original' => $file->getClientName(),
                'ruta' => $ruta,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'dia' => $dia,
                'url' => base_url('uploads/placas/' . $newName),
            ]
        ]);
    }

    public function renombrar()
    {
        $id = (int) $this->request->getPost('id');
        $nombre = trim((string) $this->request->getPost('nombre'));

        if ($id <= 0 || $nombre === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Datos inválidos'])->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
        $row = $model->find($id);
        if (!$row) {
            return $this->response->setJSON(['success' => false, 'message' => 'No encontrado'])->setStatusCode(404);
        }

        $model->update($id, ['nombre' => $nombre]);

        return $this->response->setJSON(['success' => true, 'message' => 'Actualizado']);
    }

    public function eliminar()
    {
        $id = (int) $this->request->getPost('id');
        if ($id <= 0) {
            return $this->response->setJSON(['success' => false, 'message' => 'ID inválido'])->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
        $row = $model->find($id);
        if (!$row) {
            return $this->response->setJSON(['success' => false, 'message' => 'No encontrado'])->setStatusCode(404);
        }

        $fullPath = FCPATH . $row['ruta'];
        if (is_file($fullPath)) @unlink($fullPath);

        $model->delete($id);

        return $this->response->setJSON(['success' => true, 'message' => 'Eliminado']);
    }
}
