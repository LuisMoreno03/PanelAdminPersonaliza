<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;

class PlacasArchivosController extends BaseController



{
    public function listar()
    {
        try {
            helper('url');

            $model = new PlacaArchivoModel();
            $items = $model->orderBy('id', 'DESC')->findAll();

            foreach ($items as &$it) {
                $ruta = $it['ruta'] ?? '';
                $it['url'] = base_url('placas/archivos/descargar/' . $it['id']);


                $it['created_at'] = $it['created_at'] ?? null;

                $it['original'] = $it['original']
                    ?? ($it['original_name'] ?? ($it['filename'] ?? null));

                $it['nombre'] = $it['nombre']
                    ?? ($it['original']
                        ? pathinfo($it['original'], PATHINFO_FILENAME)
                        : null
                    );

                $it['lote_id'] = $it['lote_id']
                    ?? ($it['conjunto_id'] ?? ($it['placa_id'] ?? null));
            }
            unset($it);

            return $this->response->setJSON([
                'success' => true,
                'data'    => $items
            ]);

        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ]);

            

        }
    }

    public function stats()
    {
        try {
            $db = \Config\Database::connect();
            $tabla = 'placas_archivos';

            $fields = $db->getFieldNames($tabla);
            $hasCreatedAt = in_array('created_at', $fields, true);

            $total = $db->table($tabla)->countAllResults();

            if (!$hasCreatedAt) {
                return $this->response->setJSON([
                    'success' => true,
                    'data' => [
                        'total' => $total,
                        'por_dia' => []
                    ]
                ]);
            }

            $porDia = $db->query("
                SELECT DATE(created_at) as dia, COUNT(*) as total
                FROM {$tabla}
                GROUP BY DATE(created_at)
                ORDER BY dia DESC
                LIMIT 14
            ")->getResultArray();

            return $this->response->setJSON([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'por_dia' => $porDia
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            

            

        }
    }

   public function subir()
{
    $producto    = trim((string) $this->request->getPost('producto'));
    $numeroPlaca = trim((string) $this->request->getPost('numero_placa'));

    $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $loteNombre = $numeroPlaca ? ('Placa ' . $numeroPlaca) : ('Lote ' . date('d/m/Y H:i'));

    $lista = $this->request->getFileMultiple('archivos');
    if (empty($lista)) {
        $single = $this->request->getFile('archivo');
        if ($single) $lista = [$single];
    }

    if (empty($lista)) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'No se recibieron archivos.'
        ]);
    }

    $dir = WRITEPATH . 'uploads/placas';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'No se pudo crear la carpeta de subida: ' . $dir
        ]);
    } // ✅ ESTA LLAVE suele ser la que falta

    $bloqueadas = ['php','phtml','phar','cgi','pl','asp','aspx','jsp','sh','bat','cmd','exe','dll'];

    $model = new \App\Models\PlacaArchivoModel();
    $guardados = 0;
    $errores = [];

    foreach ($lista as $file) {
        if (!$file || !$file->isValid()) {
            $errores[] = 'Archivo inválido';
            continue;
        }

        $ext  = strtolower((string) $file->getExtension());
        if (in_array($ext, $bloqueadas, true)) {
            $errores[] = $file->getClientName() . ' (extensión bloqueada)';
            continue;
        }

        $newName = time() . '_' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');

        if (!$file->move($dir, $newName)) {
            $errores[] = $file->getClientName() . ' (no se pudo mover)';
            continue;
        }

        $ruta = 'writable/uploads/placas/' . $newName;

        $ok = $model->insert([
            'nombre'       => pathinfo($file->getClientName(), PATHINFO_FILENAME),
            'producto'     => $producto ?: null,
            'numero_placa' => $numeroPlaca ?: null,
            'original'     => $file->getClientName(),
            'ruta'         => $ruta,
            'mime'         => (string) $file->getClientMimeType(),
            'size'         => (int) $file->getSize(),
            'lote_id'      => $loteId,
            'lote_nombre'  => $loteNombre,
           
        ]);

        if ($ok === false) {
            @unlink($dir . DIRECTORY_SEPARATOR . $newName);
            $dbErr = $model->db->error();
            $errores[] = $file->getClientName() . ' (BD falló: ' . ($dbErr['message'] ?? 'desconocido') . ')';
            continue;
        }

        $guardados++;
    }

    return $this->response->setJSON([
        'success' => $guardados > 0,
        'message' => $guardados ? "✅ Subidos {$guardados} archivo(s) | Lote {$loteId}" : 'No se pudo subir ningún archivo.',
        'lote_id' => $loteId,
        'errores' => $errores
    ]);
}


    public function renombrar()
    {
        $id     = (int) $this->request->getPost('id');
        $nombre = trim((string) $this->request->getPost('nombre'));

        if ($id <= 0 || $nombre === '') {
            return $this->response->setJSON(['success'=>false,'message'=>'Datos inválidos'])->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
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

        $model = new PlacaArchivoModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setJSON(['success'=>false,'message'=>'No encontrado'])->setStatusCode(404);
        }

        $fullPath = ROOTPATH . ($row['ruta'] ?? '');

        if (is_file($fullPath)) @unlink($fullPath);

        $model->delete($id);

        return $this->response->setJSON(['success'=>true,'message'=>'Eliminado ✅']);
    }

    public function renombrarLote()
    {
        $loteId = trim((string) $this->request->getPost('lote_id'));
        $nombre = trim((string) $this->request->getPost('lote_nombre'));

        if ($loteId === '' || $nombre === '') {
            return $this->response->setJSON(['success'=>false,'message'=>'Datos inválidos'])->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
        $model->where('lote_id', $loteId)->set(['lote_nombre' => $nombre])->update();

        return $this->response->setJSON(['success'=>true,'message'=>'Lote actualizado ✅']);
    }

    public function eliminarLote()
    {
        $loteId = trim((string) $this->request->getPost('lote_id'));

        if ($loteId === '') {
            return $this->response->setJSON(['success'=>false,'message'=>'Lote inválido'])->setStatusCode(422);
        }

        $model = new PlacaArchivoModel();
        $rows = $model->where('lote_id', $loteId)->findAll();

        if (!$rows) {
            return $this->response->setJSON(['success'=>false,'message'=>'Lote no encontrado'])->setStatusCode(404);
        }

        foreach ($rows as $row) {
            $fullPath = ROOTPATH . ($row['ruta'] ?? '');

            if (is_file($fullPath)) @unlink($fullPath);
        }

        $model->where('lote_id', $loteId)->delete();

        return $this->response->setJSON(['success'=>true,'message'=>'Lote eliminado ✅']);
    }


   public function descargar($archivoId)
{
    $m = new PlacaArchivoModel();
    $r = $m->find($archivoId);

    if (!$r) {
        return $this->response->setStatusCode(404)->setBody('Archivo no encontrado');
    }

    $ruta = $r['ruta'] ?? '';
    if ($ruta === '') {
        return $this->response->setStatusCode(422)->setBody('Registro incompleto: falta ruta');
    }

    $fullPath = ROOTPATH . ltrim($ruta, '/');

    if (!is_file($fullPath)) {
        return $this->response->setStatusCode(404)->setBody("No existe el archivo: {$fullPath}");
    }

    $downloadName = (string) ($r['original'] ?? $r['original_name'] ?? $r['filename'] ?? basename($fullPath));
    return $this->response->download($fullPath, null)->setFileName($downloadName);
}


}
