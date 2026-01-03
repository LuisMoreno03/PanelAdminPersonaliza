<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;

class PlacasArchivosController extends BaseController
{
    private string $dir;

    public function __construct()
    {
        $this->dir = FCPATH . 'uploads/placas/';
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
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

    public function listar()
    {
        $model = new PlacaArchivoModel();
        $items = $model->orderBy('id', 'DESC')->findAll();

        foreach ($items as &$it) {
            $it['url'] = base_url($it['ruta']); // ruta ya en public/uploads/placas/...
        }

        return $this->response->setJSON([
            'success' => true,
            'items' => $items,
        ]);
    }

    public function subir()
    {
        $file = $this->request->getFile('archivo');


        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Archivo inválido'])->setStatusCode(400);
        }


        $allowed = ['pdf','png','jpg','jpeg','webp'];
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $allowed, true)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Solo PDF o imágenes'])->setStatusCode(422);
        }

        $maxSize = 15 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return $this->response->setJSON(['success' => false, 'message' => 'Máximo 15MB'])->setStatusCode(422);
        }

        // Nombre visible: "PLACA_YYYYMMDD_HHMMSS_original"
        $dia = date('Y-m-d');
        $stamp = date('Ymd_His');
        $cleanOriginal = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $file->getClientName());
        $visibleName = "PLACA_{$stamp}_{$cleanOriginal}";

        $newName = $file->getRandomName();
        $file->move($this->dir, $newName);

        $rutaPublica = 'uploads/placas/' . $newName;

        $model = new PlacaArchivoModel();
        $id = $model->insert([
            'nombre'   => $visibleName,
            'original' => $file->getClientName(),
            'ruta'     => $rutaPublica,
            'mime'     => $file->getClientMimeType(),
            'size'     => $file->getSize(),
            'dia'      => $dia,
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Placa guardada automáticamente ✅',
            'item' => [
                'id' => $id,
                'nombre' => $visibleName,
                'original' => $file->getClientName(),
                'ruta' => $rutaPublica,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'dia' => $dia,
                'url' => base_url($rutaPublica),
            ]
        ]);

    }
}
