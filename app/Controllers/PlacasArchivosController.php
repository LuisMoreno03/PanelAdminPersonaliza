<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;

class PlacasArchivosController extends BaseController
{
    private string $uploadDir;

    public function __construct()
    {
        $this->uploadDir = WRITEPATH . 'uploads/placas/';
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, true);
        }
    }

    public function listar()
    {
        $model = new PlacaArchivoModel();
        $items = $model->orderBy('id', 'DESC')->findAll();

        // url pública para descargar: usaremos un endpoint simple via base_url('writable/...') NO funciona por seguridad
        // En hosting, lo correcto es servir desde /public/uploads. Alternativa rápida: copiar a public/uploads.
        // Para mantenerlo simple: devolvemos solo metadata (sin link), y tú decides si expones descarga.

        return $this->response->setJSON([
            'success' => true,
            'items' => $items
        ]);
    }

    public function subir()
    {
        $file = $this->request->getFile('archivo');
        $nombre = trim((string) $this->request->getPost('nombre'));

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido'])->setStatusCode(400);
        }

        // Validaciones
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file->getSize() > $maxSize) {
            return $this->response->setJSON(['success' => false, 'message' => 'Máximo 10MB'])->setStatusCode(422);
        }

        $allowed = ['pdf','png','jpg','jpeg','webp','doc','docx','xls','xlsx'];
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $allowed, true)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Tipo no permitido'])->setStatusCode(422);
        }

        // Nombre visible (si no envías, usamos el original)
        if ($nombre === '') {
            $nombre = pathinfo($file->getName(), PATHINFO_FILENAME);
        }

        // Guardar en disco con nombre random para evitar colisiones
        $newName = $file->getRandomName();
        $file->move($this->uploadDir, $newName);

        $ruta = 'uploads/placas/' . $newName; // relativo a WRITEPATH

        $model = new PlacaArchivoModel();
        $id = $model->insert([
            'nombre'   => $nombre,
            'original' => $file->getClientName(),
            'ruta'     => $ruta,
            'mime'     => $file->getClientMimeType(),
            'size'     => $file->getSize(),
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Archivo subido',
            'id' => $id
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

        return $this->response->setJSON(['success' => true, 'message' => 'Nombre actualizado']);
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

        // borrar archivo físico
        $fullPath = WRITEPATH . $row['ruta'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $model->delete($id);

        return $this->response->setJSON(['success' => true, 'message' => 'Eliminado']);
    }
}
