<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasArchivosController extends BaseController
{
    protected PlacaArchivoModel $m;

    public function __construct()
    {
        $this->m = new PlacaArchivoModel();
    }

    // ✅ GET /placas/archivos/stats
    public function stats(): ResponseInterface
    {
        $hoy = date('Y-m-d');
        $inicio = $hoy . ' 00:00:00';
        $fin    = $hoy . ' 23:59:59';

        $total = $this->m->where('created_at >=', $inicio)
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
        $rows = $this->m->orderBy('created_at', 'DESC')->findAll();

        // Agrupar: dias -> lotes -> items
        $diasMap = [];

        foreach ($rows as $r) {
            $created = (string)($r['created_at'] ?? '');
            $fecha = $created ? substr($created, 0, 10) : 'Sin fecha';

            $loteId = (string)($r['lote_id'] ?? 'SIN_LOTE');
            if ($loteId === '') $loteId = 'SIN_LOTE';

            $loteNombre = (string)($r['lote_nombre'] ?? 'Sin nombre');
            if (trim($loteNombre) === '') $loteNombre = 'Sin nombre';

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

            $id = (int)($r['id'] ?? 0);
            if (!$id) continue;

            // ✅ URL que usará el frontend para mostrar preview
            $url = site_url('placas/archivos/ver/' . $id);

            $mime = (string)($r['mime'] ?? '');
            $thumbUrl = null;

            // Si tu frontend usa thumb_url, por ahora devolvemos el mismo URL para imágenes.
            if (strpos($mime, 'image/') === 0) {
                $thumbUrl = $url;
            }

            $item = [
                'id' => $id,
                'lote_id' => $loteId,
                'lote_nombre' => $loteNombre,

                'numero_placa' => (string)($r['numero_placa'] ?? ''),
                'nombre'       => (string)($r['nombre'] ?? ''),
                'original'     => (string)($r['original'] ?? ''),
                'mime'         => $mime,
                'size'         => (int)($r['size'] ?? 0),
                'created_at'   => $created,

                // ✅ claves que tu JS espera
                'url'       => $url,
                'thumb_url' => $thumbUrl,

                // (opcionales)
                'view_url'     => $url,
                'download_url' => site_url('placas/archivos/descargar/' . $id),
            ];

            $diasMap[$fecha]['lotes'][$loteId]['items'][] = $item;
            $diasMap[$fecha]['total_archivos']++;
        }

        // convertir lotes map a array
        $dias = array_values(array_map(function ($d) {
            $d['lotes'] = array_values($d['lotes']);
            return $d;
        }, $diasMap));

        // placas hoy:
        $hoy = date('Y-m-d');
        $placasHoy = isset($diasMap[$hoy]) ? (int)$diasMap[$hoy]['total_archivos'] : 0;

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
            $loteNombre  = trim((string)$this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string)$this->request->getPost('numero_placa'));

            if ($loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'El nombre del lote es obligatorio.',
                ]);
            }

            // ✅ forma correcta para múltiples archivos: archivos[]
            $archivos = $this->request->getFileMultiple('archivos');

            if (!$archivos || !is_array($archivos) || !count($archivos)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No se recibieron archivos.',
                ]);
            }

            // ✅ lote_id único
            $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

            // ✅ Guardar en writable (no público)
            $baseDir = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads/placas/' . $loteId . '/';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0775, true);
            }

            $guardados = [];

            foreach ($archivos as $idx => $file) {
                if (!$file || !$file->isValid()) continue;

                $originalName = $file->getClientName() ?: ('archivo_' . $idx);
                $mime         = $file->getClientMimeType() ?: 'application/octet-stream';
                $size         = (int)($file->getSize() ?? 0);

                $ext = $file->getClientExtension();
                $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                if (!$safeBase) $safeBase = 'archivo_' . $idx;

                $finalName = $safeBase . '_' . time() . '_' . bin2hex(random_bytes(2));
                if ($ext) $finalName .= '.' . $ext;

                // move acepta destino absoluto
                $file->move($baseDir, $finalName);

                // ruta relativa a WRITEPATH
                $rutaRel = 'uploads/placas/' . $loteId . '/' . $finalName;

                // ✅ OJO: NO insertamos pedidos_text ni pedidos_json para evitar el error de columna
                $rowId = $this->m->insert([
                    'lote_id'      => $loteId,
                    'lote_nombre'  => $loteNombre,
                    'numero_placa' => $numeroPlaca,

                    'ruta'     => $rutaRel,
                    'original' => $originalName,
                    'mime'     => $mime,
                    'size'     => $size,
                    'nombre'   => $safeBase,
                ]);

                $rowId = (int)$rowId;

                $guardados[] = [
                    'id' => $rowId,
                    'original' => $originalName,
                    'url' => site_url('placas/archivos/ver/' . $rowId),
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
    public function renombrar(): ResponseInterface
    {
        try {
            $id = (int)$this->request->getPost('id');
            $nombre = trim((string)$this->request->getPost('nombre'));

            if (!$id || $nombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'ID y nombre son obligatorios.',
                ]);
            }

            $row = $this->m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Archivo no encontrado.',
                ]);
            }

            $this->m->update($id, ['nombre' => $nombre]);

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
            $loteId = trim((string)$this->request->getPost('lote_id'));
            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));

            if ($loteId === '' || $loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'lote_id y lote_nombre son obligatorios.',
                ]);
            }

            $this->m->where('lote_id', $loteId)->set(['lote_nombre' => $loteNombre])->update();

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
    public function eliminar(): ResponseInterface
    {
        try {
            $id = (int)$this->request->getPost('id');
            if (!$id) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'ID es obligatorio.',
                ]);
            }

            $row = $this->m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Archivo no encontrado.',
                ]);
            }

            $ruta = (string)($row['ruta'] ?? '');
            $abs = $ruta ? (WRITEPATH . $ruta) : '';

            if ($abs && is_file($abs)) {
                @unlink($abs);
            }

            $this->m->delete($id);

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
    public function ver(int $id): ResponseInterface
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = $ruta ? (WRITEPATH . $ruta) : '';

        if (!$path || !is_file($path)) return $this->response->setStatusCode(404);

        $mime = (string)($row['mime'] ?? '');
        if ($mime === '') {
            $mime = @mime_content_type($path) ?: 'application/octet-stream';
        }

        $filename = (string)($row['original'] ?? ('archivo_' . $id));

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody(file_get_contents($path));
    }

    // ✅ GET /placas/archivos/descargar/{id} (attachment)
    public function descargar(int $id): ResponseInterface
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = $ruta ? (WRITEPATH . $ruta) : '';

        if (!$path || !is_file($path)) return $this->response->setStatusCode(404);

        $mime = (string)($row['mime'] ?? '');
        if ($mime === '') {
            $mime = @mime_content_type($path) ?: 'application/octet-stream';
        }

        $originalName = (string)($row['original'] ?? ('archivo_' . $id));

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $originalName . '"')
            ->setBody(file_get_contents($path));
    }
}
