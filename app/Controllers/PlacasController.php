<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class PlacasController extends BaseController
{
    private string $baseDir;
    private string $indexFile;

    public function __construct()
    {
        $this->baseDir   = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'placas';
        $this->indexFile = $this->baseDir . DIRECTORY_SEPARATOR . 'placas_index.json';
    }

    public function index()
    {
        return view('placas');
    }

    /**
     * ✅ Subir una placa + pedidos + archivos
     * POST /placas/subir
     * FormData:
     *  - placa_numero: "123"
     *  - pedidos: "1001,1002,1003" (o JSON ["1001","1002"])
     *  - archivos[]: múltiples archivos
     */
    public function subir(): ResponseInterface
    {
        helper(['filesystem']);

        try {
            $placaNumero = trim((string) $this->request->getPost('placa_numero'));
            $pedidosRaw  = $this->request->getPost('pedidos');

            if ($placaNumero === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Falta placa_numero',
                ]);
            }

            // ✅ pedidos puede venir como JSON o como string "1,2,3"
            $pedidos = [];
            if (is_string($pedidosRaw) && $pedidosRaw !== '') {
                $tryJson = json_decode($pedidosRaw, true);
                if (is_array($tryJson)) {
                    $pedidos = array_values(array_filter(array_map('strval', $tryJson)));
                } else {
                    $pedidos = array_values(array_filter(array_map('trim', explode(',', $pedidosRaw))));
                }
            } elseif (is_array($pedidosRaw)) {
                $pedidos = array_values(array_filter(array_map('strval', $pedidosRaw)));
            }

            if (count($pedidos) === 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Faltan pedidos (lista de # de pedido)',
                ]);
            }

            $fecha  = date('Y-m-d');
            $loteId = 'P' . preg_replace('/\D+/', '', $placaNumero) . '_' . date('Ymd_His');

            // ✅ crear carpeta del lote
            if (!is_dir($this->baseDir)) {
                mkdir($this->baseDir, 0775, true);
            }

            $loteDir = $this->baseDir . DIRECTORY_SEPARATOR . $fecha . DIRECTORY_SEPARATOR . $loteId;
            if (!is_dir($loteDir)) {
                mkdir($loteDir, 0775, true);
            }

            $uploaded = $this->request->getFiles();
            $files    = [];

            // Soporta input name="archivos[]" o cualquier otro, pero recomendado "archivos"
            $incoming = $uploaded['archivos'] ?? null;
            if ($incoming === null) {
                // intenta tomar todos los archivos del request si no viene "archivos"
                $incoming = [];
                foreach ($uploaded as $k => $v) {
                    if (is_array($v)) $incoming = array_merge($incoming, $v);
                    else $incoming[] = $v;
                }
            }

            if (!is_array($incoming)) $incoming = [$incoming];

            // ✅ guardar cada archivo
            foreach ($incoming as $f) {
                if (!$f || !$f->isValid()) continue;

                $original = $f->getClientName();
                $mime     = (string) $f->getClientMimeType();
                $size     = (int) $f->getSize();

                // nombre seguro
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
                $safeName = ($safeName === '' ? ('archivo_' . time()) : $safeName);

                // evitar colisiones
                $finalName = $safeName;
                $i = 1;
                while (is_file($loteDir . DIRECTORY_SEPARATOR . $finalName)) {
                    $finalName = pathinfo($safeName, PATHINFO_FILENAME) . "_{$i}." . pathinfo($safeName, PATHINFO_EXTENSION);
                    $i++;
                }

                $f->move($loteDir, $finalName);

                $relPath = 'uploads/placas/' . $fecha . '/' . $loteId . '/' . $finalName;

                // fileKey estable para descargar
                $fileKey = sha1($relPath);

                $files[] = [
                    'file_key'      => $fileKey,
                    'original_name' => $original,
                    'stored_name'   => $finalName,
                    'ruta'          => $relPath,
                    'mime'          => $mime,
                    'size'          => $size,
                    'created_at'    => date('c'),
                    'download_url'  => site_url('placas/descargar/' . $loteId . '/' . $fileKey),
                ];
            }

            if (count($files) === 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No se subió ningún archivo.',
                ]);
            }

            // ✅ guardar en UN SOLO ARCHIVO índice (con lock)
            $index = $this->readIndex();
            $index[] = [
                'lote_id'      => $loteId,
                'fecha'        => $fecha,
                'placa_numero' => $placaNumero,
                'pedidos'      => $pedidos,
                'archivos'     => $files,
                'created_at'   => date('c'),
            ];
            $this->writeIndex($index);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Placa subida correctamente.',
                'lote_id' => $loteId,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'Placas subir() ERROR: {msg} | {file}:{line}', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ✅ Listar TODO, ordenado por día (desc) y por created_at (desc)
     * GET /placas/listar
     */
    public function listar(): ResponseInterface
    {
        $index = $this->readIndex();

        // ordenar por fecha desc, y dentro por created_at desc
        usort($index, function ($a, $b) {
            $fa = $a['fecha'] ?? '';
            $fb = $b['fecha'] ?? '';
            if ($fa === $fb) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            }
            return strcmp($fb, $fa);
        });

        // opcional: agrupar por fecha
        $grouped = [];
        foreach ($index as $row) {
            $d = $row['fecha'] ?? 'sin_fecha';
            $grouped[$d][] = $row;
        }

        return $this->response->setJSON([
            'success' => true,
            'items'   => $index,
            'by_day'  => $grouped,
        ]);
    }

    /**
     * ✅ Archivos por lote + devuelve también placa_numero y pedidos
     * GET /placas/{loteId}/archivos
     */
    public function archivos(string $loteId): ResponseInterface
    {
        $index = $this->readIndex();

        foreach ($index as $row) {
            if (($row['lote_id'] ?? '') === $loteId) {
                return $this->response->setJSON([
                    'success' => true,
                    'lote_id' => $loteId,
                    'fecha'   => $row['fecha'] ?? null,
                    'placa'   => $row['placa_numero'] ?? null,
                    'pedidos' => $row['pedidos'] ?? [],
                    'items'   => $row['archivos'] ?? [],
                ]);
            }
        }

        return $this->response->setStatusCode(404)->setJSON([
            'success' => false,
            'message' => 'Lote no encontrado',
        ]);
    }

    /**
     * ✅ Descargar por loteId + fileKey
     * GET /placas/descargar/{loteId}/{fileKey}
     */
    public function descargar(string $loteId, string $fileKey)
    {
        $index = $this->readIndex();

        $file = null;
        foreach ($index as $row) {
            if (($row['lote_id'] ?? '') !== $loteId) continue;
            foreach (($row['archivos'] ?? []) as $f) {
                if (($f['file_key'] ?? '') === $fileKey) {
                    $file = $f;
                    break 2;
                }
            }
        }

        if (!$file) return $this->response->setStatusCode(404);

        $rel = (string) ($file['ruta'] ?? '');
        if ($rel === '') return $this->response->setStatusCode(404);

        $abs = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($rel, '/\\');
        if (!is_file($abs)) return $this->response->setStatusCode(404);

        $mime         = (string) ($file['mime'] ?? mime_content_type($abs));
        $originalName = (string) ($file['original_name'] ?? 'archivo');

        // ✅ si es WEBP -> convertir a PNG
        if ($mime === 'image/webp') {
            if (!function_exists('imagecreatefromwebp')) {
                return $this->response->setStatusCode(500)->setBody('PHP sin soporte WEBP (GD).');
            }

            $im = imagecreatefromwebp($abs);
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
            ->setBody(file_get_contents($abs));
    }

    // -------------------------
    // Helpers índice (1 solo archivo)
    // -------------------------
    private function readIndex(): array
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }

        if (!is_file($this->indexFile)) {
            file_put_contents($this->indexFile, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return [];
        }

        $raw = file_get_contents($this->indexFile);
        $arr = json_decode($raw ?: '[]', true);
        return is_array($arr) ? $arr : [];
    }

    private function writeIndex(array $data): void
    {
        $fp = fopen($this->indexFile, 'c+');
        if (!$fp) throw new \RuntimeException('No se pudo abrir el índice de placas.');

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
