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
    $producto = trim((string) $this->request->getPost('producto'));
    $numeroPlaca = trim((string) $this->request->getPost('numero_placa'));

    // 1) Puede venir un solo archivo (archivo) o múltiples (archivos[])
    $files = $this->request->getFiles();
    $lista = [];

    if (isset($files['archivos'])) {
        $lista = $files['archivos'];            // múltiples
    } elseif ($this->request->getFile('archivo')) {
        $lista = [$this->request->getFile('archivo')]; // uno solo (compat)
    }

    if (!$lista || !is_array($lista)) {
        return $this->response->setJSON(['success' => false, 'message' => 'No se recibieron archivos.']);
    }

    $model = new \App\Models\PlacaArchivoModel();

    $guardados = 0;
    $errores = [];

    foreach ($lista as $file) {
        if (!$file || !$file->isValid()) {
            $errores[] = 'Archivo inválido';
            continue;
        }

        // Validación básica
        $mime = $file->getClientMimeType();
        $permitidos = ['image/jpeg','image/png','image/webp','application/pdf'];
        if (!in_array($mime, $permitidos, true)) {
            $errores[] = $file->getName().' (tipo no permitido)';
            continue;
        }

        $newName = time().'_'.bin2hex(random_bytes(8)).'.'.$file->getExtension();
        $dir = FCPATH.'uploads/placas';

        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        if (!$file->move($dir, $newName)) {
            $errores[] = $file->getName().' (no se pudo mover)';
            continue;
        }

        $ruta = 'uploads/placas/'.$newName;

        $model->insert([
            'nombre'        => pathinfo($file->getName(), PATHINFO_FILENAME),
            'producto'      => $producto ?: null,
            'numero_placa'  => $numeroPlaca ?: null,
            'original'      => $file->getName(),
            'ruta'          => $ruta,
            'mime'          => $mime,
            'size'          => $file->getSize(),
        ]);

        $guardados++;
    }

    return $this->response->setJSON([
        'success' => $guardados > 0,
        'message' => $guardados > 0
            ? "✅ Subidos: {$guardados}".(count($errores) ? " | Errores: ".count($errores) : '')
            : 'No se pudo subir ningún archivo.',
        'errores' => $errores
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



