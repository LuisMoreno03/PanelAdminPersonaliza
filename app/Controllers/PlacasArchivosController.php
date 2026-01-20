<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;

use App\Models\PlacaLoteModel;

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
$loteNombreManual = trim((string) $this->request->getPost('lote_nombre'));

    $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    
    
    $loteNombre = $loteNombreManual !== ''
    ? $loteNombreManual
    : ($numeroPlaca ? ('Placa ' . $numeroPlaca) : ('Lote ' . date('d/m/Y H:i')));

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
    try {
        $loteId = trim((string) $this->request->getPost('lote_id'));
        $nombre = trim((string) $this->request->getPost('lote_nombre'));

        if ($loteId === '' || $nombre === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan datos: lote_id / lote_nombre',
                'received' => ['lote_id' => $loteId, 'lote_nombre' => $nombre],
            ]);
        }

        $model = new \App\Models\PlacaArchivoModel();

        // ✅ update por lote
        $ok = $model->where('lote_id', $loteId)
                    ->set(['lote_nombre' => $nombre])
                    ->update();

        if ($ok === false) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error al actualizar lote',
                'errors'  => $model->errors(),
            ]);
        }

        
        return $this->response->setJSON([
            'success' => true,
            'message' => '✅ Lote renombrado',
            'data'    => ['lote_id' => $loteId, 'lote_nombre' => $nombre],
        ]);

    } catch (\Throwable $e) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Excepción: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }
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



// POST /placas/archivos/subir-lote
    public function subirLote()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        // ✅ nombre manual obligatorio
    $loteNombre = trim((string) $this->request->getPost('lote_nombre'));
    if ($loteNombre === '') {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'El nombre del lote es obligatorio.',
        ]);
    }

        $files = $this->request->getFiles();
        $arr = $files['archivos'] ?? null;

        if (!$arr) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'No llegaron archivos (campo: archivos[])',
            ]);
        }

        // Normaliza a array
        $uploaded = is_array($arr) ? $arr : [$arr];

    $fecha = date('Y-m-d');
    $now   = date('Y-m-d H:i:s');

    $userId   = session()->get('user_id') ?? null;
    $userName = session()->get('user_name') ?? session()->get('nombre') ?? null;

    // 1) Crear el lote (guardando nombre manual)
    $lotes = new PlacaLoteModel();
    $loteId = $lotes->insert([
        'fecha'            => $fecha,
        'nombre'           => $loteNombre, // ✅ NUEVO en placas_lotes
        'uploaded_by'      => $userId,
        'uploaded_by_name' => $userName,
        'created_at'       => $now,
    ], true);

    if (!$loteId) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'No se pudo crear el lote',
        ]);
    }

    // 2) Guardar físicamente
    $publicBase = FCPATH . 'uploads/placas/' . $fecha . '/lote_' . $loteId . '/';
    if (!is_dir($publicBase)) {
        @mkdir($publicBase, 0775, true);
    }

    $archivosModel = new PlacaArchivoModel();
    $guardados = [];

    foreach ($uploaded as $file) {
        if (!$file || !$file->isValid()) continue;

        $original   = $file->getClientName();
        $nombreBase = pathinfo($original, PATHINFO_FILENAME); // ✅ AÑADIR

        $mime     = $file->getClientMimeType();
        $sizeKb   = (int) ceil($file->getSize() / 1024);

        $safeName  = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $original);
        $finalName = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;

        $file->move($publicBase, $finalName);

        $relative = 'uploads/placas/' . $fecha . '/lote_' . $loteId . '/' . $finalName;

        $id = $archivosModel->insert([
            'nombre'           => $nombreBase,
            'lote_id'          => $loteId,
            'lote_nombre'      => $loteNombre, // ✅ GUARDA TAMBIÉN EN ARCHIVOS (para listar fácil)
            'ruta'             => $relative,
            'original_name'    => $original,
            'size_kb'          => $sizeKb,
            'mime'             => $mime,
            'fecha'            => $fecha,
            'uploaded_by'      => $userId,
            'uploaded_by_name' => $userName,
            'created_at'       => $now,
        ], true);

        $guardados[] = [
            'id' => $id,
            'lote_id' => $loteId,
            'lote_nombre' => $loteNombre,
            'ruta' => $relative,
            'url'  => base_url('placas/archivos/descargar/' . $id),
            'original_name' => $original,
            'size_kb' => $sizeKb,
        ];
    }

    return $this->response->setJSON([
        'success' => true,
        'message' => 'Lote creado y archivos subidos',
        'lote_id' => $loteId,
        'lote_nombre' => $loteNombre,
        'fecha' => $fecha,
        'items' => $guardados
    ]);
}
    
    public function listarPorDia()
{
    try {
        helper('url');
        $db = \Config\Database::connect();

        // Detectar columnas disponibles
        $fields = $db->getFieldNames('placas_archivos');

        $hasNombre      = in_array('nombre', $fields, true);
        $hasOriginal     = in_array('original', $fields, true);
        $hasOriginalName = in_array('original_name', $fields, true);
        $hasFilename     = in_array('filename', $fields, true);
        $hasSize         = in_array('size', $fields, true);
        $hasSizeKb       = in_array('size_kb', $fields, true);
        $hasCreatedAt    = in_array('created_at', $fields, true);

        $select = [
            '`id`',
            '`lote_id`',
            '`lote_nombre`',
            '`ruta`',
            '`mime`',
        ];

        if ($hasCreatedAt) $select[] = '`created_at`';

        // ✅ ORIGINAL fallback con backticks
        if ($hasOriginal || $hasOriginalName || $hasFilename) {
            $origParts = [];
            if ($hasOriginal)     $origParts[] = "NULLIF(`original`, '')";
            if ($hasOriginalName) $origParts[] = "NULLIF(`original_name`, '')";
            if ($hasFilename)     $origParts[] = "NULLIF(`filename`, '')";
            $select[] = "COALESCE(" . implode(',', $origParts) . ") AS `original`";
        } else {
            $select[] = "NULL AS `original`";
        }

        // ✅ nombre si existe
        if ($hasNombre) {
            $select[] = "`nombre`";
        } else {
            $select[] = "NULL AS `nombre`";
        }

        // ✅ size fallback con backticks
        if ($hasSize || $hasSizeKb) {
            $sizeParts = [];
            if ($hasSize)   $sizeParts[] = "NULLIF(`size`, 0)";
            if ($hasSizeKb) $sizeParts[] = "(NULLIF(`size_kb`, 0) * 1024)";
            $select[] = "COALESCE(" . implode(',', $sizeParts) . ", 0) AS `size`";
        } else {
            $select[] = "0 AS `size`";
        }

        $sql = "
            SELECT " . implode(', ', $select) . "
            FROM `placas_archivos`
            ORDER BY " . ($hasCreatedAt ? "`created_at` DESC," : "") . " `lote_id` DESC, `id` DESC
        ";

        $rows = $db->query($sql)->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $created = $r['created_at'] ?? null;
            $fecha = $created ? date('Y-m-d', strtotime($created)) : 'sin-fecha';

           $loteId = (string)($r['lote_id'] ?? 'sin-lote');

            $loteNombre = trim((string)($r['lote_nombre'] ?? ''));
        if ($loteNombre === '') {
            $loteNombre = 'Sin nombre'; // ✅ sin fallback al número
}

            if (!isset($out[$fecha])) {
                $out[$fecha] = [
                    'fecha' => $fecha,
                    'total_archivos' => 0,
                    'lotes' => []
                ];
            }

            if (!isset($out[$fecha]['lotes'][$loteId])) {
                $out[$fecha]['lotes'][$loteId] = [
                    'lote_id' => $loteId,
                    'lote_nombre' => $loteNombre,
                    'created_at' => $created,
                    'uploaded_by_name' => null,
                    'items' => []
                ];
            }

            $out[$fecha]['lotes'][$loteId]['items'][] = [
                'id' => (int)$r['id'],
                'nombre' => $r['nombre'] ?? null,
                'original' => $r['original'] ?? null,
                'mime' => $r['mime'] ?? null,
                'size' => (int)($r['size'] ?? 0),
                'created_at' => $created,
                'ruta' => $r['ruta'] ?? null,
                'url'  => base_url('placas/archivos/descargar/' . $r['id']),
            ];

            $out[$fecha]['total_archivos']++;
        }

        $final = [];
        foreach ($out as $block) {
            $block['lotes'] = array_values($block['lotes']);
            $final[] = $block;
        }

        $today = date('Y-m-d');
        $hoyCount = 0;
        foreach ($final as $b) {
            if ($b['fecha'] === $today) { $hoyCount = (int)$b['total_archivos']; break; }
        }

        return $this->response->setJSON([
            'success' => true,
            'hoy' => $today,
            'placas_hoy' => $hoyCount,
            'dias' => $final
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




// DESCARGAR FOTOS Y ARCHIVOS JPG/PNG (FOTOS) //

public function descargarPng($archivoId)
{
    return $this->descargarConvertido($archivoId, 'png');
}

public function descargarJpg($archivoId)
{
    return $this->descargarConvertido($archivoId, 'jpg');
}

private function descargarConvertido($archivoId, $format = 'png')
{
    $format = strtolower($format) === 'jpg' ? 'jpg' : 'png';

    $m = new PlacaArchivoModel();
    $r = $m->find($archivoId);

    if (!$r) return $this->response->setStatusCode(404)->setBody('Archivo no encontrado');

    $ruta = $r['ruta'] ?? '';
    if ($ruta === '') return $this->response->setStatusCode(422)->setBody('Registro incompleto: falta ruta');

    $fullPath = ROOTPATH . ltrim($ruta, '/');
    if (!is_file($fullPath)) return $this->response->setStatusCode(404)->setBody("No existe el archivo: {$fullPath}");

    // Nombre de descarga (no vacío)
    $baseName = trim((string)($r['nombre'] ?? ''));

    if ($baseName === '') {
        $orig = (string)($r['original'] ?? $r['original_name'] ?? $r['filename'] ?? ('archivo_' . $archivoId));
        $baseName = trim(pathinfo($orig, PATHINFO_FILENAME));
    }
    if ($baseName === '') $baseName = 'archivo_' . $archivoId;

    $baseName = preg_replace('/[^a-zA-Z0-9\-_ ]/', '_', $baseName);
    $downloadName = $baseName . '.' . $format;

    // ✅ Detectar si ya es del mismo formato
    $mime = strtolower((string)($r['mime'] ?? ''));
    $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

    $isPng = ($ext === 'png') || str_contains($mime, 'png');
    $isJpg = in_array($ext, ['jpg', 'jpeg'], true)
        || str_contains($mime, 'jpeg')
        || str_contains($mime, 'jpg');

    $isSame = ($format === 'png' && $isPng) || ($format === 'jpg' && $isJpg);

    if ($isSame) {
        return $this->response->download($fullPath, null)->setFileName($downloadName);
    }

    // Convertir (preferir Imagick)
    try {
        if (class_exists(\Imagick::class)) {
            $im = new \Imagick();
            $im->readImage($fullPath);

            if ($im->getNumberImages() > 1) $im->setIteratorIndex(0);

            $im->setImageColorspace(\Imagick::COLORSPACE_RGB);

            if ($format === 'jpg') {
                $im->setImageFormat('jpeg');
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality(92);
                $contentType = 'image/jpeg';
            } else {
                $im->setImageFormat('png');
                $contentType = 'image/png';
            }

            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();

            return $this->response
                ->setHeader('Content-Type', $contentType)
                ->setHeader(
                    'Content-Disposition',
                    'attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
                )
                ->setBody($blob);
        }

        // Fallback GD
        $img = @imagecreatefromstring(file_get_contents($fullPath));
        if (!$img) return $this->response->setStatusCode(415)->setBody('No se pudo convertir (requiere Imagick)');

        ob_start();
        if ($format === 'png') {
            imagepng($img);
            $contentType = 'image/png';
        } else {
            imagejpeg($img, null, 92);
            $contentType = 'image/jpeg';
        }
        $blob = ob_get_clean();
        imagedestroy($img);

        return $this->response
            ->setHeader('Content-Type', $contentType)
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
            )
            ->setBody($blob);

    } catch (\Throwable $e) {
        return $this->response->setStatusCode(500)->setBody('Error convirtiendo: ' . $e->getMessage());
    }
}


// DESCARGAR ZIP POR LOTE (PNG/JPG)
public function descargarPngLote($loteId)
{
    return $this->descargarZipLote($loteId, 'png');
}

public function descargarJpgLote($loteId)
{
    return $this->descargarZipLote($loteId, 'jpg');
}

private function descargarZipLote($loteId, $format = 'png')
{
    $format = strtolower($format) === 'jpg' ? 'jpg' : 'png';

    $m = new PlacaArchivoModel();
    $rows = $m->where('lote_id', $loteId)->findAll();

    if (!$rows) return $this->response->setStatusCode(404)->setBody('Lote no encontrado');

    $zip = new \ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'lote_') . '.zip';

    if ($zip->open($tmp, \ZipArchive::CREATE) !== true) {
        return $this->response->setStatusCode(500)->setBody('No se pudo crear ZIP');
    }

    foreach ($rows as $r) {
        $ruta = $r['ruta'] ?? '';
        if (!$ruta) continue;

        $fullPath = ROOTPATH . ltrim($ruta, '/');
        if (!is_file($fullPath)) continue;

        $aid = (int)($r['id'] ?? 0);

        $baseName = trim((string)($r['nombre'] ?? ''));
        if ($baseName === '') {
            $orig = (string)($r['original'] ?? $r['original_name'] ?? $r['filename'] ?? ('archivo_' . $aid));
            $baseName = trim(pathinfo($orig, PATHINFO_FILENAME));
        }
        if ($baseName === '') $baseName = 'archivo_' . $aid;

        $baseName = preg_replace('/[^a-zA-Z0-9\-_ ]/', '_', $baseName);

        // Detectar si ya es del mismo formato
        $mime = strtolower((string)($r['mime'] ?? ''));
        $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        $isPng = ($ext === 'png') || str_contains($mime, 'png');
        $isJpg = in_array($ext, ['jpg', 'jpeg'], true)
            || str_contains($mime, 'jpeg')
            || str_contains($mime, 'jpg');

        $isSame = ($format === 'png' && $isPng) || ($format === 'jpg' && $isJpg);

        if ($isSame) {
            $zip->addFile($fullPath, $baseName . '.' . $format);
            continue;
        }

        // Convertir con Imagick si existe
        if (class_exists(\Imagick::class)) {
            $im = new \Imagick();
            $im->readImage($fullPath);
            if ($im->getNumberImages() > 1) $im->setIteratorIndex(0);
            $im->setImageColorspace(\Imagick::COLORSPACE_RGB);

            if ($format === 'jpg') {
                $im->setImageFormat('jpeg');
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality(92);
            } else {
                $im->setImageFormat('png');
            }

            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();

            $zip->addFromString($baseName . '.' . $format, $blob);
        }
    }

    $zip->close();

    return $this->response->download($tmp, null)
        ->setFileName("lote_{$loteId}_{$format}.zip");
}
