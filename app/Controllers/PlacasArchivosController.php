<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasArchivosController extends BaseController
{
    private function resolveCreatedField($table): ?string
    {
        $db = db_connect();

        $candidates = ['created_at', 'fecha_subida', 'uploaded_at', 'created', 'fecha'];
        foreach ($candidates as $f) {
            if ($db->fieldExists($f, $table)) return $f;
        }
        return null;
    }

    private function hasField($table, $field): bool
    {
        return db_connect()->fieldExists($field, $table);
    }

    // ✅ GET /placas/archivos/stats
    public function stats(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table = 'placas_archivos';

            $createdField = $this->resolveCreatedField($table);

            // Si no hay campo fecha, no podemos calcular "hoy" real:
            if (!$createdField) {
                return $this->response->setJSON([
                    'success' => true,
                    'data' => ['total' => 0],
                    'note' => 'No existe created_at (o similar) en placas_archivos. Agrega created_at para stats reales.',
                ]);
            }

            $hoy = date('Y-m-d');
            $inicio = $hoy . ' 00:00:00';
            $fin    = $hoy . ' 23:59:59';

            $total = $m->where($createdField . ' >=', $inicio)
                       ->where($createdField . ' <=', $fin)
                       ->countAllResults();

            return $this->response->setJSON([
                'success' => true,
                'data' => ['total' => (int)$total],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'stats ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ GET /placas/archivos/listar-por-dia
    public function listarPorDia(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table = 'placas_archivos';

            $createdField = $this->resolveCreatedField($table);

            // ✅ Orden seguro
            if ($createdField) $rows = $m->orderBy($createdField, 'DESC')->findAll();
            else              $rows = $m->orderBy('id', 'DESC')->findAll();

            $diasMap = [];

            foreach ($rows as $r) {
                $created = $createdField ? (string)($r[$createdField] ?? '') : '';
                $fecha   = $created ? substr($created, 0, 10) : 'Sin fecha';

                $loteId     = (string)($r['lote_id'] ?? 'SIN_LOTE');
                $loteNombre = (string)($r['lote_nombre'] ?? 'Sin nombre');

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

                $diasMap[$fecha]['lotes'][$loteId]['items'][] = [
                    'id' => $id,
                    'lote_id' => $loteId,
                    'lote_nombre' => $loteNombre,
                    'numero_placa' => (string)($r['numero_placa'] ?? ''),

                    'nombre' => (string)($r['nombre'] ?? ''),
                    'original' => (string)($r['original'] ?? ''),
                    'mime' => (string)($r['mime'] ?? ''),
                    'size' => (int)($r['size'] ?? 0),

                    'created_at' => $created,

                    'view_url' => site_url('placas/archivos/ver/' . $id),
                    'download_url' => site_url('placas/archivos/descargar/' . $id),
                ];

                $diasMap[$fecha]['total_archivos']++;
            }

            $dias = array_values(array_map(function ($d) {
                $d['lotes'] = array_values($d['lotes']);
                return $d;
            }, $diasMap));

            // placas hoy (solo si hay campo fecha)
            $placasHoy = 0;
            if ($createdField) {
                $hoy = date('Y-m-d');
                $placasHoy = isset($diasMap[$hoy]) ? (int)$diasMap[$hoy]['total_archivos'] : 0;
            }

            return $this->response->setJSON([
                'success' => true,
                'placas_hoy' => $placasHoy,
                'dias' => $dias,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'listarPorDia ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST /placas/archivos/subir-lote
    public function subirLote(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table = 'placas_archivos';

            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string)$this->request->getPost('numero_placa'));
            $pedidosJson = (string)$this->request->getPost('pedidos_json');

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

            $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

            $baseDir = WRITEPATH . 'uploads/placas/' . $loteId . '/';
            if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);

            $guardados = [];

            $hasCreated = $this->hasField($table, 'created_at');
            $hasUpdated = $this->hasField($table, 'updated_at');
            $now = date('Y-m-d H:i:s');

            foreach ($archivos as $idx => $file) {
                if (!$file || !$file->isValid()) continue;

                $originalName = $file->getClientName() ?: ('archivo_' . $idx);
                $mime = $file->getClientMimeType() ?: 'application/octet-stream';
                $size = (int)($file->getSize() ?? 0);

                $ext = $file->getClientExtension();
                $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                if ($safeBase === '') $safeBase = 'archivo_' . $idx;

                $finalName = $safeBase . '_' . time() . '_' . bin2hex(random_bytes(2));
                if ($ext) $finalName .= '.' . $ext;

                // ✅ acepta cualquier tipo, se mueve igual
                $file->move($baseDir, $finalName);

                $rutaRel = 'uploads/placas/' . $loteId . '/' . $finalName;

                $payload = [
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
                ];

                if ($hasCreated && !isset($payload['created_at'])) $payload['created_at'] = $now;
                if ($hasUpdated && !isset($payload['updated_at'])) $payload['updated_at'] = $now;

                $rowId = $m->insert($payload);

                $guardados[] = [
                    'id' => (int)$rowId,
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

    // ✅ GET /placas/archivos/ver/{id}
    public function ver(int $id)
    {
        $m = new PlacaArchivoModel();
        $row = $m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = WRITEPATH . $ruta;

        if (!$ruta || !is_file($path)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: mime_content_type($path);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . ($row['original'] ?? ('archivo_' . $id)) . '"')
            ->setBody(file_get_contents($path));
    }

    // ✅ GET /placas/archivos/descargar/{id}
    public function descargar(int $id)
    {
        $m = new PlacaArchivoModel();
        $row = $m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = WRITEPATH . $ruta;

        if (!$ruta || !is_file($path)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: mime_content_type($path);
        $originalName = $row['original'] ?: ('archivo_' . $id);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $originalName . '"')
            ->setBody(file_get_contents($path));
    }
}
 