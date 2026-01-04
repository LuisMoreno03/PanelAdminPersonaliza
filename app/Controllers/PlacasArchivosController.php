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
        $it['dia'] = !empty($it['created_at'])
            ? date('Y-m-d', strtotime($it['created_at']))
            : null;
    }
    unset($it);

    // 🔹 Agrupar por lote_id
    $grupos = [];

    foreach ($items as $it) {
        $key = $it['lote_id'] ?: 'SIN_LOTE';

        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'lote_id'     => $key,
                'lote_nombre' => $it['lote_nombre'] ?? null,
                'created_at'  => $it['created_at'] ?? null,
                'items'       => []
            ];
        }

        $grupos[$key]['items'][] = $it;
    }

    return $this->response->setJSON([
        'success' => true,
        'grupos'  => array_values($grupos)
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

        $totalHoy = $model->where('DATE(created_at)', $hoy)->countAllResults();

        return $this->response->setJSON([
            'success'  => true,
            'totalHoy' => $totalHoy
        ]);
    }

    /**
     * SUBIR ARCHIVO(S)
     * POST /placas/archivos/subir
     *
     * Acepta:
     * - archivo (uno)
     * - archivos[] (múltiples)
     */
    public function subir()
{
    $producto    = trim((string) $this->request->getPost('producto'));
    $numeroPlaca = trim((string) $this->request->getPost('numero_placa'));
    $loteNombre  = trim((string) $this->request->getPost('lote_nombre'));

    // 🔹 ID único del grupo/lote
    $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));

    // 🔹 Multi o single upload
    $lista = $this->request->getFileMultiple('archivos');
    if (empty($lista)) {
        $single = $this->request->getFile('archivo');
        if ($single) $lista = [$single];
    }

    if (empty($lista)) {
        return $this->response->setJSON([
            'success' => false,
            'message' => 'No se recibieron archivos.'
        ])->setStatusCode(422);
    }

    $model = new \App\Models\PlacaArchivoModel();

    $dir = FCPATH . 'uploads/placas';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $bloqueadas = [
        'php','phtml','phar','cgi','pl','asp','aspx','jsp',
        'sh','bat','cmd','exe','dll'
    ];

    $guardados = 0;
    $errores = [];

    foreach ($lista as $file) {
        if (!$file || !$file->isValid()) {
            $errores[] = 'Archivo inválido';
            continue;
        }

        $ext = strtolower((string) $file->getExtension());
        if (in_array($ext, $bloqueadas, true)) {
            $errores[] = $file->getName().' (extensión bloqueada)';
            continue;
        }

        if ($file->getSize() > 25 * 1024 * 1024) {
            $errores[] = $file->getName().' (máx 25MB)';
            continue;
        }

        $mime = (string) $file->getClientMimeType();
        $newName = time().'_'.bin2hex(random_bytes(8)).($ext ? '.'.$ext : '');

        if (!$file->move($dir, $newName)) {
            $errores[] = $file->getName().' (no se pudo mover)';
            continue;
        }

        $ruta = 'uploads/placas/'.$newName;

        $model->insert([
            'nombre'       => pathinfo($file->getName(), PATHINFO_FILENAME),
            'producto'     => $producto ?: null,
            'numero_placa' => $numeroPlaca ?: null,
            'original'     => $file->getName(),
            'ruta'         => $ruta,
            'mime'         => $mime,
            'size'         => (int) $file->getSize(),
            'lote_id'      => $loteId,
            'lote_nombre'  => $loteNombre ?: null,
        ]);

        $guardados++;
    }

    return $this->response->setJSON([
        'success' => $guardados > 0,
        'message' => $guardados
            ? "✅ Subidos {$guardados} archivo(s) | Lote {$loteId}"
            : 'No se pudo subir ningún archivo.',
        'lote_id' => $loteId,
        'errores' => $errores
    ]);
}

    /**
     * RENOMBRAR ARCHIVO
     * POST /placas/archivos/renombrar
     */
    public function renombrar()
    {
        $id     = (int) $this->request->getPost('id');
        $nombre = trim((string) $this->request->getPost('nombre'));

        if ($id <= 0 || $nombre === '') {
            return $this->response->setJSON(['success'=>false,'message'=>'Datos inválidos'])
                ->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setJSON(['success'=>false,'message'=>'No encontrado'])
                ->setStatusCode(404);
        }

        $model->update($id, ['nombre' => $nombre]);

        return $this->response->setJSON(['success'=>true,'message'=>'Nombre actualizado ✅']);
    }

    /**
     * ELIMINAR ARCHIVO
     * POST /placas/archivos/eliminar
     */
    public function eliminar()
    {
        $id = (int) $this->request->getPost('id');

        if ($id <= 0) {
            return $this->response->setJSON(['success'=>false,'message'=>'ID inválido'])
                ->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setJSON(['success'=>false,'message'=>'No encontrado'])
                ->setStatusCode(404);
        }

        $fullPath = FCPATH . $row['ruta'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $model->delete($id);

        return $this->response->setJSON(['success'=>true,'message'=>'Eliminado ✅']);
    }
}
