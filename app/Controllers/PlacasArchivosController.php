<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasArchivosController extends BaseController
{
    // =========================================================
    // Helpers internos
    // =========================================================

    private function tableFields(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];

        $db = db_connect();
        $cache[$table] = $db->getFieldNames($table) ?? [];
        return $cache[$table];
    }

    private function hasFieldFast(array $fields, string $field): bool
    {
        return in_array($field, $fields, true);
    }

    private function resolveCreatedField(string $table): ?string
    {
        $db = db_connect();
        $candidates = ['created_at', 'fecha_subida', 'uploaded_at', 'created', 'fecha'];

        foreach ($candidates as $f) {
            if ($db->fieldExists($f, $table)) return $f;
        }
        return null;
    }

    private function resolveUpdatedField(string $table): ?string
    {
        $db = db_connect();
        $candidates = ['updated_at', 'fecha_actualizacion', 'updated', 'modified_at', 'modified'];

        foreach ($candidates as $f) {
            if ($db->fieldExists($f, $table)) return $f;
        }
        return null;
    }

    private function decodeJsonSafe($value): array
    {
        if ($value === null) return [];
        if (is_array($value)) return $value;

        $str = trim((string)$value);
        if ($str === '') return [];

        $decoded = json_decode($str, true);
        if (json_last_error() !== JSON_ERROR_NONE) return [];

        return is_array($decoded) ? $decoded : [];
    }

    private function safeFilePathFromRuta(string $ruta): ?string
    {
        $ruta = ltrim($ruta, '/\\');
        if ($ruta === '') return null;

        $full = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $ruta);
        $real = realpath($full);

        if (!$real || !is_file($real)) return null;

        // seguridad extra: debe vivir dentro de WRITEPATH
        $wp = realpath(rtrim(WRITEPATH, '/\\'));
        if ($wp && strpos($real, $wp) !== 0) return null;

        return $real;
    }

    private function mapFileRow(array $r, ?string $createdField = null): array
    {
        helper('url');

        $id = (int)($r['id'] ?? 0);

        $createdValue = '';
        if ($createdField && isset($r[$createdField])) $createdValue = (string)$r[$createdField];
        else if (isset($r['created_at'])) $createdValue = (string)$r['created_at'];

        return [
            'id'          => $id,
            'lote_id'     => (string)($r['lote_id'] ?? ''),
            'lote_nombre' => (string)($r['lote_nombre'] ?? ''),
            'numero_placa'=> (string)($r['numero_placa'] ?? ''),

            'nombre'      => (string)($r['nombre'] ?? ''),
            'original'    => (string)($r['original'] ?? ''),
            'mime'        => (string)($r['mime'] ?? ''),
            'size'        => (int)($r['size'] ?? 0),
            'created_at'  => $createdValue,

            // urls
            'url'          => site_url('placas/archivos/inline/' . $id),
            'thumb_url'    => site_url('placas/archivos/inline/' . $id),
            'download_url' => site_url('placas/archivos/descargar/' . $id),
            'info_url'     => site_url('placas/archivos/info/' . $id),
        ];
    }

    // =========================================================
    // ✅ GET /placas/archivos/stats
    // =========================================================
    public function stats(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table = 'placas_archivos';

            $createdField = $this->resolveCreatedField($table);

            if (!$createdField) {
                return $this->response->setJSON([
                    'success' => true,
                    'data' => ['total' => 0],
                    'note' => 'No existe campo de fecha (created_at/fecha_subida/...) en placas_archivos.',
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

    // =========================================================
    // ✅ GET /placas/archivos/listar-por-dia
    // Devuelve días -> lotes -> items
    // =========================================================
    public function listarPorDia(): ResponseInterface
    {
        try {
            helper('url');

            $m = new PlacaArchivoModel();
            $table = 'placas_archivos';

            $createdField = $this->resolveCreatedField($table);

            if ($createdField) $rows = $m->orderBy($createdField, 'DESC')->findAll();
            else              $rows = $m->orderBy('id', 'DESC')->findAll();

            $diasMap = [];

            foreach ($rows as $r) {
                $created = $createdField ? (string)($r[$createdField] ?? '') : ((string)($r['created_at'] ?? ''));
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

                    // ✅ URLs correctas (NO 404)
                    'url'          => site_url('placas/archivos/inline/' . $id),
                    'thumb_url'    => site_url('placas/archivos/inline/' . $id),
                    'info_url'     => site_url('placas/archivos/info/' . $id),
                    'download_url' => site_url('placas/archivos/descargar/' . $id),
                ];

                $diasMap[$fecha]['total_archivos']++;
            }

            $dias = array_values(array_map(function ($d) {
                $d['lotes'] = array_values($d['lotes']);
                return $d;
            }, $diasMap));

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

    // =========================================================
    // ✅ POST /placas/archivos/subir-lote
    // Acepta cualquier formato y lo guarda
    // =========================================================
    public function subirLote(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table  = 'placas_archivos';
            $fields = $this->tableFields($table);

            $createdField = $this->resolveCreatedField($table);
            $updatedField = $this->resolveUpdatedField($table);

            $loteNombre  = trim((string)$this->request->getPost('lote_nombre'));
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

            $baseDir = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'placas' . DIRECTORY_SEPARATOR . $loteId . DIRECTORY_SEPARATOR;
            if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);

            $guardados = [];
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

                // ✅ acepta cualquier tipo y lo guarda
                $file->move($baseDir, $finalName);

                // ruta relativa a WRITEPATH
                $rutaRel = 'uploads/placas/' . $loteId . '/' . $finalName;

                // ✅ payload solo con columnas existentes
                $payload = [];

                if ($this->hasFieldFast($fields, 'lote_id'))      $payload['lote_id'] = $loteId;
                if ($this->hasFieldFast($fields, 'lote_nombre'))  $payload['lote_nombre'] = $loteNombre;
                if ($this->hasFieldFast($fields, 'numero_placa')) $payload['numero_placa'] = $numeroPlaca;

                if ($this->hasFieldFast($fields, 'pedidos_json')) $payload['pedidos_json'] = $pedidosJson ?: null;

                if ($this->hasFieldFast($fields, 'ruta'))     $payload['ruta'] = $rutaRel;
                if ($this->hasFieldFast($fields, 'original')) $payload['original'] = $originalName;
                if ($this->hasFieldFast($fields, 'mime'))     $payload['mime'] = $mime;
                if ($this->hasFieldFast($fields, 'size'))     $payload['size'] = $size;
                if ($this->hasFieldFast($fields, 'nombre'))   $payload['nombre'] = $safeBase;

                // fechas: usar el campo real que exista
                if ($createdField && $this->hasFieldFast($fields, $createdField) && !isset($payload[$createdField])) {
                    $payload[$createdField] = $now;
                }
                if ($updatedField && $this->hasFieldFast($fields, $updatedField) && !isset($payload[$updatedField])) {
                    $payload[$updatedField] = $now;
                }

                $rowId = $m->insert($payload);
                $guardados[] = ['id' => (int)$rowId, 'original' => $originalName];
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

    // =========================================================
    // ✅ GET /placas/archivos/info/{id}
    // JSON con lote + archivos + pedidos
    // =========================================================
    public function info(int $id): ResponseInterface
    {
        try {
            helper('url');

            $m = new PlacaArchivoModel();
            $table = 'placas_archivos';
            $createdField = $this->resolveCreatedField($table);

            $row = $m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'No encontrado',
                ]);
            }

            $loteId = (string)($row['lote_id'] ?? '');

            // si no hay lote, devolvemos solo el archivo
            if ($loteId === '') {
                $file = $this->mapFileRow($row, $createdField);
                $pedidos = $this->decodeJsonSafe($row['pedidos_json'] ?? null);

                return $this->response->setJSON([
                    'success' => true,
                    'lote' => [
                        'lote_id'     => null,
                        'lote_nombre' => (string)($row['lote_nombre'] ?? ''),
                        'numero_placa'=> (string)($row['numero_placa'] ?? ''),
                        'created_at'  => $file['created_at'],
                        'total_files' => 1,
                        'total_size'  => (int)($row['size'] ?? 0),
                        'pedidos'     => $pedidos,
                    ],
                    'files' => [$file],
                ]);
            }

            $rows = $m->where('lote_id', $loteId)->orderBy('id', 'ASC')->findAll();
            if (!$rows) $rows = [$row];

            $first = $rows[0];

            $pedidos = $this->decodeJsonSafe($first['pedidos_json'] ?? null);

            // created_at del lote (mínimo)
            $createdAt = '';
            $totalSize = 0;

            foreach ($rows as $r) {
                $c = $createdField ? (string)($r[$createdField] ?? '') : ((string)($r['created_at'] ?? ''));
                if ($c !== '') {
                    if ($createdAt === '' || strtotime($c) < strtotime($createdAt)) $createdAt = $c;
                }
                $totalSize += (int)($r['size'] ?? 0);
            }

            $files = array_map(fn($r) => $this->mapFileRow($r, $createdField), $rows);

            return $this->response->setJSON([
                'success' => true,
                'lote' => [
                    'lote_id'     => $loteId,
                    'lote_nombre' => (string)($first['lote_nombre'] ?? ''),
                    'numero_placa'=> (string)($first['numero_placa'] ?? ''),
                    'created_at'  => $createdAt,
                    'total_files' => count($files),
                    'total_size'  => $totalSize,
                    'pedidos'     => $pedidos,
                ],
                'files' => $files,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'info(JSON) ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================
    // ✅ GET /placas/archivos/ver/{id}  (alias por compatibilidad)
    // =========================================================
    public function ver(int $id): ResponseInterface
    {
        return $this->info($id);
    }

    // =========================================================
    // ✅ GET /placas/archivos/inline/{id}
    // Preview seguro (inline). Sirve para imagen/pdf/cualquier archivo
    // =========================================================
    public function inline(int $id): ResponseInterface
    {
        $m = new PlacaArchivoModel();
        $row = $m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = $this->safeFilePathFromRuta($ruta);
        if (!$path) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: (mime_content_type($path) ?: 'application/octet-stream');
        $originalName = $row['original'] ?: ('archivo_' . $id);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . $originalName . '"')
            ->setBody(file_get_contents($path));
    }

    // =========================================================
    // ✅ GET /placas/archivos/descargar/{id}
    // =========================================================
    public function descargar(int $id): ResponseInterface
    {
        $m = new PlacaArchivoModel();
        $row = $m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = $this->safeFilePathFromRuta($ruta);
        if (!$path) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: (mime_content_type($path) ?: 'application/octet-stream');
        $originalName = $row['original'] ?: ('archivo_' . $id);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $originalName . '"')
            ->setBody(file_get_contents($path));
    }

    // =========================================================
    // ✅ POST /placas/archivos/renombrar
    // =========================================================
    public function renombrarArchivo(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table  = 'placas_archivos';
            $fields = $this->tableFields($table);
            $updatedField = $this->resolveUpdatedField($table);
            $now = date('Y-m-d H:i:s');

            $id = (int)$this->request->getPost('id');
            $nombre = trim((string)$this->request->getPost('nombre'));

            if (!$id) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'ID inválido.',
                ]);
            }

            if ($nombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'El nombre no puede estar vacío.',
                ]);
            }

            $row = $m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Archivo no encontrado.',
                ]);
            }

            $data = [];
            if ($this->hasFieldFast($fields, 'nombre')) $data['nombre'] = $nombre;

            if ($updatedField && $this->hasFieldFast($fields, $updatedField)) {
                $data[$updatedField] = $now;
            }

            if (!$data) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No existe columna "nombre" en placas_archivos.',
                ]);
            }

            $m->update($id, $data);

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Nombre actualizado',
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'renombrarArchivo ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================
    // ✅ POST /placas/archivos/eliminar
    // =========================================================
    public function eliminarArchivo(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();

            $id = (int)$this->request->getPost('id');
            if (!$id) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'ID inválido.',
                ]);
            }

            $row = $m->find($id);
            if (!$row) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Archivo no encontrado.',
                ]);
            }

            // borrar archivo físico si existe
            $ruta = (string)($row['ruta'] ?? '');
            $path = $ruta ? $this->safeFilePathFromRuta($ruta) : null;
            if ($path && is_file($path)) {
                @unlink($path);
            }

            // borrar fila
            $m->delete($id);

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Archivo eliminado',
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'eliminarArchivo ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================
    // ✅ POST /placas/archivos/lote/renombrar
    // =========================================================
    public function renombrarLote(): ResponseInterface
    {
        try {
            $m = new PlacaArchivoModel();
            $table  = 'placas_archivos';
            $fields = $this->tableFields($table);
            $updatedField = $this->resolveUpdatedField($table);
            $now = date('Y-m-d H:i:s');

            $loteId = trim((string)$this->request->getPost('lote_id'));
            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));

            if ($loteId === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'lote_id es obligatorio.',
                ]);
            }
            if ($loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'El nombre del lote no puede estar vacío.',
                ]);
            }

            if (!$this->hasFieldFast($fields, 'lote_nombre')) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No existe columna lote_nombre en placas_archivos.',
                ]);
            }

            $data = ['lote_nombre' => $loteNombre];
            if ($updatedField && $this->hasFieldFast($fields, $updatedField)) {
                $data[$updatedField] = $now;
            }

            $m->where('lote_id', $loteId)->set($data)->update();

            return $this->response->setJSON([
                'success' => true,
                'message' => '✅ Lote renombrado',
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'renombrarLote ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
