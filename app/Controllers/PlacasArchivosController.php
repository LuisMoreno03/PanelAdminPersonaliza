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
            'nombre'   => pathinfo($file->getName(), PATHINFO_FILENAME),
            'original' => $file->getName(),
            'ruta'     => $ruta,
            'mime'     => $file->getClientMimeType(),
            'size'     => $file->getSize(),
      
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Placa subida correctamente'
        ]);
    }
}
