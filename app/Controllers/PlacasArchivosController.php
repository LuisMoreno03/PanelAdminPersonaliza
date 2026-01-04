<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;

class PlacasArchivosController extends BaseController
{
    /**
     * LISTAR ARCHIVOS
     * GET /placas/archivos/listar
     */
    public function listar()
    {
        $model = new PlacaArchivoModel();

        $items = $model->orderBy('id', 'DESC')->findAll();

        foreach ($items as &$it) {
            $it['url'] = base_url($it['ruta']);
            $it['dia'] = date('Y-m-d', strtotime($it['created_at']));
        }

        return $this->response->setJSON([
            'success' => true,
            'items'   => $items
        ]);
    }

    /**
     * STATS
     * GET /placas/archivos/stats
     */
    public function stats()
    {
        $model = new PlacaArchivoModel();

        $hoy = date('Y-m-d');

        $totalHoy = $model
            ->where('DATE(created_at)', $hoy)
            ->countAllResults();

        return $this->response->setJSON([
            'success' => true,
            'totalHoy' => $totalHoy
        ]);
    }

    /**
     * SUBIR ARCHIVO
     * POST /placas/archivos/subir
     */
    public function subir()
    {
        $file = $this->request->getFile('archivo');


        if (!$file || !$file->isValid()) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Archivo inválido'
            ]);
        }

        $nombreFinal = $file->getRandomName();
        $ruta = 'uploads/placas/' . $nombreFinal;

        $file->move(FCPATH . 'uploads/placas', $nombreFinal);

        $model = new PlacaArchivoModel();
        $model->insert([
            'nombre'       => pathinfo($file->getName(), PATHINFO_FILENAME),
            'producto'     => $producto ?: null,
            'numero_placa' => $numeroPlaca ?: null,
            'original'     => $file->getName(),
            'ruta'         => $ruta,
            'mime'         => $file->getClientMimeType(),
            'size'         => $file->getSize(),
      
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Placa subida correctamente'
        ]);

    }

    /**
     * RENOMBRAR ARCHIVO
     * POST /placas/archivos/renombrar
     */
    public function renombrar()
{
    $id = (int) $this->request->getPost('id');
    $nombre = trim((string) $this->request->getPost('nombre'));

    if ($id <= 0 || $nombre === '') {
        return $this->response->setJSON(['success'=>false,'message'=>'Datos inválidos'])->setStatusCode(422);
    }

    $model = new \App\Models\PlacaArchivoModel();
    $row = $model->find($id);
    if (!$row) {
        return $this->response->setJSON(['success'=>false,'message'=>'No encontrado'])->setStatusCode(404);
    }

    $model->update($id, ['nombre' => $nombre]);

    return $this->response->setJSON(['success'=>true,'message'=>'Nombre actualizado ✅']);
}

public function eliminar()
{
    $id = (int) $this->request->getPost('id');
    if ($id <= 0) {
        return $this->response->setJSON(['success'=>false,'message'=>'ID inválido'])->setStatusCode(422);
    }

    $model = new \App\Models\PlacaArchivoModel();
    $row = $model->find($id);
    if (!$row) {
        return $this->response->setJSON(['success'=>false,'message'=>'No encontrado'])->setStatusCode(404);
    }

    $fullPath = FCPATH . $row['ruta'];
    if (is_file($fullPath)) @unlink($fullPath);

    $model->delete($id);

    return $this->response->setJSON(['success'=>true,'message'=>'Eliminado ✅']);
}
    }  



