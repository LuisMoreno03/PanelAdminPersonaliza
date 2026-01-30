<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasArchivosController extends BaseController
{
    // ✅ GET /placas/archivos/stats
    public function stats(): ResponseInterface
    {
        $m = new PlacaArchivoModel();

        $hoy = date('Y-m-d');
        $inicio = $hoy . ' 00:00:00';
        $fin    = $hoy . ' 23:59:59';

        $total = $m->where('created_at >=', $inicio)
                   ->where('created_at <=', $fin)
                   ->countAllResults();

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'total' => (int) $total,
            ],
        ]);
    }

    // ✅ GET /placas/archivos/listar-por-dia
    public function listarPorDia(): ResponseInterface
    {
        $m = new PlacaArchivoModel();

        $rows = $m->orderBy('created_at', 'DESC')->findAll();

        // Agrupar: dias -> lotes -> items
        $diasMap = [];

        foreach ($rows as $r) {
            $created = (string) ($r['created_at'] ?? '');
            $fecha = $created ? substr($created, 0, 10) : 'Sin fecha';

            $loteId = (string) ($r['lote_id'] ?? 'SIN_LOTE');
            $loteNombre = (string) ($r['lote_nombre'] ?? 'Sin nombre');

            if (!isset($diasMap[$fecha])) {
                $diasMap[$fecha] = [
                    'fecha' => $fecha,
                    'total_archivos' => 0,
                    'lotes' => [],
                ];
            }

            if (!isset($diasMap[$fecha]['lotes'][$loteId])) {
                $diasMap[$fecha]['lotes'][$loteId] = [
                    'lote_id' => $loteId,
                    'lote_nombre' => $loteNombre,
                    'created_at' => $created,
                    'items' => [],
                ];
            }

            $id = (int) $r['id'];

            $item = [
                'id' => $id,
                'lote_id' => $loteId,
                'lote_nombre' => $loteNombre,

                'numero_placa' => (string) ($r['numero_placa'] ?? ''),
                'nombre' => (string) ($r['nombre'] ?? ''),
                'original' => (string) ($r['original'] ?? ''),
                'mime' => (string) ($r['mime'] ?? ''),
                'size' => (int) ($r['size'] ?? 0),
                'created_at' => $created,

                // ✅ URLs (no dependen del filesystem público)
                'view_url' => site_url('placas/archivos/ver/' . $id),
                'download_url' => site_url('placas/archivos/descargar/' . $id),
            ];

            $diasMap[$fecha]['lotes'][$loteId]['items'][] = $item;
            $diasMap[$fecha]['total_archivos']++;
        }

        // convertir lotes map a array
        $dias = array_values(array_map(function($d) {
            $d['lotes'] = array_values($d['lotes']);
            return $d;
        }, $diasMap));

        // placas hoy:
        $hoy = date('Y-m-d');
        $placasHoy = isset($diasMap[$hoy]) ? (int) $diasMap[$hoy]['total_archivos'] : 0;

        return $this->response->setJSON([
            'success' => true,
            'placas_hoy' => $placasHoy,
            'dias' => $dias,
        ]);
    }

    // ✅ POST /placas/archivos/subir-lote
    public function subirLote(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();

            $loteNombre = trim((string) $this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string) $this->request->getPost('numero_placa'));
            $pedidosJson = (string) $this->request->getPost('pedidos_json');

            if ($loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'El nombre del lote es obligatorio.',
                ]);
            }

            $files = $this->request->getFiles();
            $archivos = $files['archivos'] ?? null;

            if (!$archivos) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No se recibieron archivos.',
                ]);
            }

            if (!is_array($archivos)) $archivos = [$archivos];

            // ✅ lote_id único
            $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

            // ✅ Guardar en writable (no público)
            $baseDir = WRITEPATH . 'uploads/placas/' . $loteId . '/';
            if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);

            $guardados = [];

            foreach ($archivos as $idx => $file) {
                if (!$file || !$file->isValid()) continue;

                // ✅ acepta cualquier tipo
                $originalName = $file->getClientName() ?: ('archivo_' . $idx);
                $mime         = $file->getClientMimeType() ?: 'application/octet-stream';
                $size         = (int) ($file->getSize() ?? 0);

                $ext = $file->getClientExtension();
                $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                if ($safeBase === '') $safeBase = 'archivo_' . $idx;

                $finalName = $safeBase . '_' . time() . '_' . bin2hex(random_bytes(2));
                if ($ext) $finalName .= '.' . $ext;

                $file->move($baseDir, $finalName);

                // ruta relativa a WRITEPATH (writable)
                $rutaRel = 'uploads/placas/' . $loteId . '/' . $finalName;

                $rowId = $m->insert([
                    'lote_id'      => $loteId,
                    'lote_nombre'  => $loteNombre,
                    'numero_placa' => $numeroPlaca,
                    'pedidos_json' => $pedidosJson ?: null,
                    'pedidos_text' => null,

                    'ruta'     => $rutaRel,
                    'original' => $originalName,
                    'mime'     => $mime,
                    'size'     => $size,
                    'nombre'   => $safeBase,
                ]);

                $guardados[] = [
                    'id' => (int) $rowId,
                    'original' => $originalName,
                ];
            }

            if (!$guardados) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No se pudo guardar ningún archivo.',
                ]);
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Archivos subidos correctamente',
                'lote_id' => $loteId,
                'items'   => $guardados,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'subirLote ERROR: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST /placas/archivos/renombrar
    public function renombrarArchivo(): ResponseInterface
    {
        try {
            $id = (int) $this->request->getPost('id');
            $nombre = trim((string) $this->request->getPost('nombre'));

            if (!$id || $nombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'ID y nombre son obligatorios.',
                ]);
            }

            $m = new PlacaArchivoModel();
            $row = $m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Archivo no encontrado.',
                ]);
            }

            $m->update($id, ['nombre' => $nombre]);

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Nombre actualizado',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST /placas/archivos/lote/renombrar
    public function renombrarLote(): ResponseInterface
    {
        try {
            $loteId = trim((string) $this->request->getPost('lote_id'));
            $loteNombre = trim((string) $this->request->getPost('lote_nombre'));

            if ($loteId === '' || $loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'lote_id y lote_nombre son obligatorios.',
                ]);
            }

            $m = new PlacaArchivoModel();
            $m->where('lote_id', $loteId)->set(['lote_nombre' => $loteNombre])->update();

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Lote renombrado',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST /placas/archivos/eliminar
    public function eliminarArchivo(): ResponseInterface
    {
        try {
            $id = (int) $this->request->getPost('id');
            if (!$id) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'ID es obligatorio.',
                ]);
            }

            $m = new PlacaArchivoModel();
            $row = $m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Archivo no encontrado.',
                ]);
            }

            $ruta = (string) ($row['ruta'] ?? '');
            $abs = WRITEPATH . $ruta;

            if ($ruta && is_file($abs)) {
                @unlink($abs);
            }

            $m->delete($id);

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Eliminado',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ GET /placas/archivos/ver/{id} (inline para preview)
    public function ver(int $id)
    {
        $m = new PlacaArchivoModel();
        $row = $m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string) ($row['ruta'] ?? '');
        $path = WRITEPATH . $ruta;

        if (!$ruta || !is_file($path)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: mime_content_type($path);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . ($row['original'] ?? ('archivo_' . $id)) . '"')
            ->setBody(file_get_contents($path));
    }

    // ✅ GET /placas/archivos/descargar/{id} (attachment)
    public function descargar(int $id)
    {
        $m = new PlacaArchivoModel();
        $row = $m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string) ($row['ruta'] ?? '');
        $path = WRITEPATH . $ruta;

        if (!$ruta || !is_file($path)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: mime_content_type($path);
        $originalName = $row['original'] ?: ('archivo_' . $id);

        // ✅ Si quieres convertir WEBP a PNG al descargar:
        if ($mime === 'image/webp') {
            if (!function_exists('imagecreatefromwebp')) {
                return $this->response->setStatusCode(500)->setBody('PHP sin soporte WEBP (GD).');
            }

            $im = imagecreatefromwebp($path);
            if (!$im) return $this->response->setStatusCode(500)->setBody('No se pudo leer WEBP.');

            ob_start();
            imagepng($im, null, 9);
            imagedestroy($im);
            $pngData = ob_get_clean();

            $downloadName = preg_replace('/\.(webp)$/i', '.png', $originalName);
            if ($downloadName === $originalName) $downloadName .= '.png';

            return $this->response
                ->setHeader('Content-Type', 'image/png')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
                ->setBody($pngData);
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $originalName . '"')
            ->setBody(file_get_contents($path));
    }
}
 