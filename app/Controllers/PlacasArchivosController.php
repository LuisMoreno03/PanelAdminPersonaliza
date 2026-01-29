<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasArchivosController extends BaseController
{
    private PlacaArchivoModel $m;

    public function __construct()
    {
        $this->m = new PlacaArchivoModel();
        helper(['url']);
    }

    /* =========================================================
       GET /placas/archivos/stats
    ========================================================= */
    public function stats(): ResponseInterface
    {
        $hoy = date('Y-m-d');
        $count = $this->m
            ->where('created_at >=', $hoy . ' 00:00:00')
            ->where('created_at <=', $hoy . ' 23:59:59')
            ->countAllResults();

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'total' => $count
            ]
        ]);
    }

    /* =========================================================
       GET /placas/archivos/listar-por-dia
       Devuelve:
       - placas_hoy
       - dias: [{fecha, total_archivos, lotes:[{lote_id,lote_nombre,created_at,pedidos,productos,items:[] }]}]
    ========================================================= */
    public function listarPorDia(): ResponseInterface
    {
        $rows = $this->m->orderBy('created_at', 'DESC')->findAll();

        $placasHoy = 0;
        $hoy = date('Y-m-d');

        // agrupar
        $dias = []; // fecha => [lotes => [loteId => loteData]]
        foreach ($rows as $r) {
            $created = $r['created_at'] ?? null;
            $fecha = $created ? substr($created, 0, 10) : 'sin_fecha';

            if ($fecha === $hoy) $placasHoy++;

            $loteId = (string)($r['lote_id'] ?? '');
            if ($loteId === '') continue;

            if (!isset($dias[$fecha])) $dias[$fecha] = ['fecha' => $fecha, 'lotes' => []];

            if (!isset($dias[$fecha]['lotes'][$loteId])) {
                $dias[$fecha]['lotes'][$loteId] = [
                    'lote_id' => $loteId,
                    'lote_nombre' => $r['lote_nombre'] ?? '',
                    'created_at' => $created,
                    'pedidos' => $this->jsonToArray($r['pedidos_json'] ?? null),
                    'productos' => $this->jsonToArray($r['productos_json'] ?? null),
                    'items' => []
                ];
            }

            // item
            $item = $this->rowToItem($r);

            // is_primary: primer archivo del lote es principal (si ninguno marcado)
            if ((int)($r['is_primary'] ?? 0) === 1) {
                $item['is_primary'] = 1;
            } else {
                $item['is_primary'] = 0;
            }

            $dias[$fecha]['lotes'][$loteId]['items'][] = $item;
        }

        // normalizar: convertir lotes assoc a array + asegurar principal
        $diasArr = [];
        foreach ($dias as $d) {
            $lotesArr = array_values($d['lotes']);

            // asegurar principal por lote si ninguno tiene is_primary=1
            foreach ($lotesArr as &$lote) {
                $hasPrimary = false;
                foreach ($lote['items'] as $it) {
                    if (!empty($it['is_primary'])) { $hasPrimary = true; break; }
                }
                if (!$hasPrimary && !empty($lote['items'][0])) {
                    $lote['items'][0]['is_primary'] = 1;
                }

                $lote['total'] = count($lote['items']);
            }
            unset($lote);

            // ordenar lotes por created_at desc
            usort($lotesArr, function($a,$b){
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            });

            $totalArchivos = 0;
            foreach ($lotesArr as $l) $totalArchivos += count($l['items']);

            $diasArr[] = [
                'fecha' => $d['fecha'],
                'total_archivos' => $totalArchivos,
                'lotes' => $lotesArr,
            ];
        }

        // ordenar días desc
        usort($diasArr, fn($a,$b) => strcmp($b['fecha'], $a['fecha']));

        return $this->response->setJSON([
            'success' => true,
            'placas_hoy' => $placasHoy,
            'dias' => $diasArr
        ]);
    }

    /* =========================================================
       POST /placas/archivos/subir-lote
       FormData:
         - lote_nombre (obligatorio)
         - numero_placa (opcional)
         - pedidos (opcional "1,2,3" o JSON)
         - productos (opcional líneas o coma)
         - archivos[] (obligatorio, multi)
    ========================================================= */
    public function subirLote(): ResponseInterface
    {
        try {
            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string)$this->request->getPost('numero_placa'));

            $pedidosRaw = $this->request->getPost('pedidos');
            $productosRaw = $this->request->getPost('productos');

            if ($loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'El nombre del lote es obligatorio.'
                ]);
            }

            $pedidos = $this->parseList($pedidosRaw);
            $productos = $this->parseList($productosRaw);

            // lote_id único
            $loteId = 'L' . date('Ymd_His') . '_' . substr(sha1($loteNombre . microtime(true)), 0, 8);

            // carpeta destino
            $fecha = date('Y-m-d');
            $destDir = WRITEPATH . 'uploads/placas/' . $fecha . '/' . $loteId;
            if (!is_dir($destDir)) mkdir($destDir, 0775, true);

            // archivos
            $files = $this->request->getFiles();
            $incoming = $files['archivos'] ?? null;

            if (!$incoming) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No llegaron archivos (archivos[]).'
                ]);
            }

            if (!is_array($incoming)) $incoming = [$incoming];

            $saved = 0;
            $isFirst = true;

            foreach ($incoming as $f) {
                if (!$f || !$f->isValid()) continue;

                $original = $f->getClientName();
                $mime = (string)$f->getClientMimeType();
                $size = (int)$f->getSize();

                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
                if ($safeName === '') $safeName = 'archivo_' . time();

                $finalName = $safeName;
                $i = 1;
                while (is_file($destDir . '/' . $finalName)) {
                    $finalName = pathinfo($safeName, PATHINFO_FILENAME) . "_{$i}." . pathinfo($safeName, PATHINFO_EXTENSION);
                    $i++;
                }

                $f->move($destDir, $finalName);

                $rel = 'uploads/placas/' . $fecha . '/' . $loteId . '/' . $finalName;

                // nombre visible sin extensión
                $nombre = pathinfo($original, PATHINFO_FILENAME);

                $this->m->insert([
                    'lote_id' => $loteId,
                    'lote_nombre' => $loteNombre,
                    'pedidos_json' => $pedidos ? json_encode($pedidos, JSON_UNESCAPED_UNICODE) : null,
                    'productos_json' => $productos ? json_encode($productos, JSON_UNESCAPED_UNICODE) : ($numeroPlaca ? json_encode([$numeroPlaca], JSON_UNESCAPED_UNICODE) : null),

                    'original' => $original,
                    'nombre' => $nombre,
                    'mime' => $mime,
                    'size' => $size,
                    'ruta' => $rel,

                    'is_primary' => $isFirst ? 1 : 0,
                ]);

                $isFirst = false;
                $saved++;
            }

            if ($saved === 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No se pudo guardar ningún archivo.'
                ]);
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => "✅ Subidos correctamente ({$saved})",
                'lote_id' => $loteId,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'subirLote ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =========================================================
       POST /placas/archivos/renombrar
       id, nombre
    ========================================================= */
    public function renombrarArchivo(): ResponseInterface
    {
        $id = (int)$this->request->getPost('id');
        $nombre = trim((string)$this->request->getPost('nombre'));

        if (!$id || $nombre === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Faltan datos.'
            ]);
        }

        $row = $this->m->find($id);
        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Archivo no encontrado.'
            ]);
        }

        $this->m->update($id, ['nombre' => $nombre]);

        return $this->response->setJSON([
            'success' => true,
            'message' => '✅ Nombre actualizado'
        ]);
    }

    /* =========================================================
       POST /placas/archivos/eliminar
       id
    ========================================================= */
    public function eliminarArchivo(): ResponseInterface
    {
        $id = (int)$this->request->getPost('id');
        if (!$id) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Falta id.'
            ]);
        }

        $row = $this->m->find($id);
        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Archivo no encontrado.'
            ]);
        }

        $abs = WRITEPATH . ltrim((string)$row['ruta'], '/\\');
        if (is_file($abs)) @unlink($abs);

        $this->m->delete($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => '✅ Eliminado'
        ]);
    }

    /* =========================================================
       POST /placas/archivos/lote/renombrar
       lote_id, lote_nombre
    ========================================================= */
    public function renombrarLote(): ResponseInterface
    {
        $loteId = trim((string)$this->request->getPost('lote_id'));
        $nombre = trim((string)$this->request->getPost('lote_nombre'));

        if ($loteId === '' || $nombre === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Faltan datos.'
            ]);
        }

        $this->m->where('lote_id', $loteId)->set(['lote_nombre' => $nombre])->update();

        return $this->response->setJSON([
            'success' => true,
            'message' => '✅ Lote renombrado'
        ]);
    }

    /* =========================================================
       GET /placas/archivos/descargar/{id}
    ========================================================= */
    public function descargar(int $id)
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $abs = WRITEPATH . ltrim((string)$row['ruta'], '/\\');
        if (!is_file($abs)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: mime_content_type($abs);
        $originalName = $row['original'] ?: ('archivo_'.$id);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="'.$originalName.'"')
            ->setBody(file_get_contents($abs));
    }

    /* =========================================================
       GET /placas/archivos/descargar-png/{id}
    ========================================================= */
    public function descargarPng(int $id)
    {
        return $this->convertImageDownload($id, 'png');
    }

    /* =========================================================
       GET /placas/archivos/descargar-jpg/{id}
    ========================================================= */
    public function descargarJpg(int $id)
    {
        return $this->convertImageDownload($id, 'jpg');
    }

    /* =========================================================
       GET /placas/archivos/descargar-png-lote/{loteId}
       Devuelve ZIP con PNG
    ========================================================= */
    public function descargarPngLote(string $loteId)
    {
        return $this->zipLoteConverted($loteId, 'png');
    }

    /* =========================================================
       GET /placas/archivos/descargar-jpg-lote/{loteId}
       Devuelve ZIP con JPG
    ========================================================= */
    public function descargarJpgLote(string $loteId)
    {
        return $this->zipLoteConverted($loteId, 'jpg');
    }

    /* =========================================================
       (Opcional) GET /placas/archivos/pedidos/productos?ids=1,2,3
       -> aquí debes conectar tu sistema real de pedidos.
    ========================================================= */
    public function productosDePedidos(): ResponseInterface
    {
        $ids = (string)$this->request->getGet('ids');
        $idsArr = $this->parseList($ids);

        // ⚠️ Aquí debes conectar tu tabla real de pedidos.
        // Por ahora devolvemos “mock” para que la UI funcione.
        $productos = [];
        foreach ($idsArr as $id) {
            $productos[] = "Producto del pedido #{$id}";
        }

        return $this->response->setJSON([
            'success' => true,
            'pedidos' => $idsArr,
            'productos' => $productos
        ]);
    }

    /* =======================
       Helpers internos
    ======================= */
    private function rowToItem(array $r): array
    {
        $ruta = (string)($r['ruta'] ?? '');
        $url  = $ruta !== '' ? base_url($ruta) : null;

        return [
            'id' => (int)$r['id'],
            'lote_id' => (string)($r['lote_id'] ?? ''),
            'lote_nombre' => (string)($r['lote_nombre'] ?? ''),
            'original' => (string)($r['original'] ?? ''),
            'nombre' => (string)($r['nombre'] ?? ''),
            'mime' => (string)($r['mime'] ?? ''),
            'size' => (int)($r['size'] ?? 0),
            'ruta' => $ruta,
            'url' => $url,
            'created_at' => (string)($r['created_at'] ?? ''),
            'thumb_url' => $url, // si es imagen sirve como thumb
            'download_url' => site_url('placas/archivos/descargar/' . $r['id']),
        ];
    }

    private function jsonToArray(?string $json): array
    {
        if (!$json) return [];
        $arr = json_decode($json, true);
        return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
    }

    private function parseList($raw): array
    {
        if ($raw === null) return [];
        if (is_array($raw)) {
            return array_values(array_filter(array_map(fn($x) => trim((string)$x), $raw)));
        }

        $raw = trim((string)$raw);
        if ($raw === '') return [];

        $tryJson = json_decode($raw, true);
        if (is_array($tryJson)) {
            return array_values(array_filter(array_map(fn($x) => trim((string)$x), $tryJson)));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw))));
    }

    private function convertImageDownload(int $id, string $fmt)
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $abs = WRITEPATH . ltrim((string)$row['ruta'], '/\\');
        if (!is_file($abs)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: mime_content_type($abs);
        if (strpos($mime, 'image/') !== 0) {
            return $this->response->setStatusCode(400)->setBody('No es una imagen.');
        }

        $im = $this->createImageFromAny($abs, $mime);
        if (!$im) return $this->response->setStatusCode(500)->setBody('No se pudo convertir la imagen.');

        ob_start();
        if ($fmt === 'png') {
            imagepng($im, null, 9);
            $outMime = 'image/png';
            $ext = 'png';
        } else {
            imagejpeg($im, null, 92);
            $outMime = 'image/jpeg';
            $ext = 'jpg';
        }
        imagedestroy($im);
        $data = ob_get_clean();

        $baseName = $row['original'] ?: ('archivo_'.$id);
        $baseName = preg_replace('/\.[^.]+$/', '', $baseName);
        $downloadName = $baseName . '.' . $ext;

        return $this->response
            ->setHeader('Content-Type', $outMime)
            ->setHeader('Content-Disposition', 'attachment; filename="'.$downloadName.'"')
            ->setBody($data);
    }

    private function zipLoteConverted(string $loteId, string $fmt)
    {
        if (!class_exists(\ZipArchive::class)) {
            return $this->response->setStatusCode(500)->setBody('ZipArchive no está disponible en PHP.');
        }

        $rows = $this->m->where('lote_id', $loteId)->orderBy('id', 'ASC')->findAll();
        if (!$rows) return $this->response->setStatusCode(404)->setBody('Lote no encontrado.');

        $zipPath = WRITEPATH . 'uploads/tmp_' . $loteId . '_' . time() . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return $this->response->setStatusCode(500)->setBody('No se pudo crear ZIP.');
        }

        foreach ($rows as $r) {
            $abs = WRITEPATH . ltrim((string)$r['ruta'], '/\\');
            if (!is_file($abs)) continue;

            $mime = $r['mime'] ?: mime_content_type($abs);
            if (strpos($mime, 'image/') !== 0) continue;

            $im = $this->createImageFromAny($abs, $mime);
            if (!$im) continue;

            ob_start();
            if ($fmt === 'png') imagepng($im, null, 9);
            else imagejpeg($im, null, 92);
            imagedestroy($im);
            $bin = ob_get_clean();

            $base = $r['original'] ?: ('archivo_'.$r['id']);
            $base = preg_replace('/\.[^.]+$/', '', $base);
            $filename = $base . '.' . ($fmt === 'png' ? 'png' : 'jpg');

            $zip->addFromString($filename, $bin);
        }

        $zip->close();

        $downloadName = "lote_{$loteId}_" . ($fmt === 'png' ? 'PNG' : 'JPG') . ".zip";
        $data = file_get_contents($zipPath);
        @unlink($zipPath);

        return $this->response
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="'.$downloadName.'"')
            ->setBody($data);
    }

    private function createImageFromAny(string $path, string $mime)
    {
        // webp
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($path);
        }

        // png
        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($path);
        }

        // jpeg
        if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($path);
        }

        // gif
        if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
            return @imagecreatefromgif($path);
        }

        // fallback por contenido
        if (function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($path);
            if ($raw) return @imagecreatefromstring($raw);
        }

        return null;
    }
}
