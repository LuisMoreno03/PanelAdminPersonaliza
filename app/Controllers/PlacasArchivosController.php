<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasArchivosController extends BaseController
{
    protected $db;
    protected PlacaArchivoModel $m;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->m  = new PlacaArchivoModel();
        helper(['url', 'filesystem']);
    }

    // ===================== HELPERS =====================

    private function jsonOk($payload, int $code = 200): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON($payload);
    }

    private function jsonFail(string $message, int $code = 500, array $extra = []): ResponseInterface
    {
        return $this->jsonOk(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), $code);
    }

    private function normalizePathForWriteUploads(string $ruta): string
    {
        // ruta debe ser RELATIVA a WRITEPATH/uploads
        $ruta = ltrim($ruta, '/\\');
        $ruta = str_replace(['..', '\\'], ['', '/'], $ruta);
        return $ruta;
    }

    private function fileFullPathFromRuta(string $ruta): string
    {
        $ruta = $this->normalizePathForWriteUploads($ruta);
        return rtrim(WRITEPATH, '/\\') . '/uploads/' . $ruta;
    }

    private function decodeJsonList($val): array
    {
        if ($val === null) return [];
        if (is_array($val)) return array_values(array_filter(array_map('strval', $val)));

        $s = trim((string)$val);
        if ($s === '') return [];

        $j = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
            return array_values(array_filter(array_map('strval', $j)));
        }

        // fallback csv
        $arr = preg_split('/[\n,]+/', $s);
        $arr = array_values(array_filter(array_map('trim', $arr)));
        return $arr;
    }

    private function makePublicUrlFromRuta(?string $ruta): ?string
    {
        if (!$ruta) return null;
        return base_url('writable/uploads/' . ltrim($ruta, '/'));
        // Si tu servidor sirve writable/uploads como /uploads, cambia a:
        // return base_url($ruta);
    }

    private function guessMime(string $path, ?string $fallback = null): string
    {
        if ($fallback) return $fallback;
        if (is_file($path)) {
            $m = @mime_content_type($path);
            if ($m) return $m;
        }
        return 'application/octet-stream';
    }



    
    // ===================== ENDPOINTS =====================

    /**
     * GET /placas/archivos/stats
     */
    public function stats(): ResponseInterface
    {
        try {
            $today = date('Y-m-d');
            $total = $this->m->where("DATE(created_at) =", $today)->countAllResults();

            return $this->jsonOk([
                'success' => true,
                'data' => ['total' => $total],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * GET /placas/archivos/listar-por-dia
     * Devuelve: dias[] => lotes[] => items[]
     */
    public function listarPorDia(): ResponseInterface
    {
        try {
            $rows = $this->m->orderBy('created_at', 'DESC')->findAll();

            // preparar items con url y download
            foreach ($rows as &$r) {
                $r['url']          = $this->makePublicUrlFromRuta($r['ruta'] ?? null);
                $r['download_url'] = site_url('placas/archivos/descargar/' . $r['id']);
                $r['thumb_url']    = $r['url']; // puedes reemplazar por thumbnail real

                // meta
                $r['productos'] = $this->decodeJsonList($r['productos_json'] ?? null);
                $r['pedidos']   = $this->decodeJsonList($r['pedidos_json'] ?? null);
            }
            unset($r);

            // agrupar por día y por lote
            $diasMap = []; // 'YYYY-MM-DD' => ['fecha'=>..., 'lotes'=>..., 'total_archivos'=>...]
            $placasHoy = 0;

            foreach ($rows as $it) {
                $created = (string)($it['created_at'] ?? '');
                $dayKey  = $created ? substr($created, 0, 10) : date('Y-m-d');

                if ($dayKey === date('Y-m-d')) $placasHoy++;

                if (!isset($diasMap[$dayKey])) {
                    $diasMap[$dayKey] = [
                        'fecha' => $dayKey,
                        'total_archivos' => 0,
                        'lotes' => [],
                    ];
                }

                $diasMap[$dayKey]['total_archivos']++;

                $loteId = (string)($it['lote_id'] ?? 'SIN_LOTE');
                if (!isset($diasMap[$dayKey]['lotes'][$loteId])) {
                    $diasMap[$dayKey]['lotes'][$loteId] = [
                        'lote_id'     => $loteId,
                        'lote_nombre' => (string)($it['lote_nombre'] ?? ''),
                        'created_at'  => (string)($it['created_at'] ?? ''),
                        'productos'   => $it['productos'] ?? [],
                        'pedidos'     => $it['pedidos'] ?? [],
                        'items'       => [],
                    ];
                }

                $diasMap[$dayKey]['lotes'][$loteId]['items'][] = [
                    'id'         => (int)$it['id'],
                    'lote_id'    => $loteId,
                    'lote_nombre'=> (string)($it['lote_nombre'] ?? ''),
                    'original'   => (string)($it['original'] ?? ''),
                    'nombre'     => (string)($it['nombre'] ?? ''),
                    'mime'       => (string)($it['mime'] ?? ''),
                    'size'       => (int)($it['size'] ?? 0),
                    'created_at' => (string)($it['created_at'] ?? ''),
                    'is_primary' => (int)($it['is_primary'] ?? 0),
                    'ruta'       => (string)($it['ruta'] ?? ''),
                    'url'        => (string)($it['url'] ?? ''),
                    'thumb_url'  => (string)($it['thumb_url'] ?? ''),
                    'download_url' => (string)($it['download_url'] ?? ''),
                ];
            }

            // convertir lotes map -> array y ordenar
            $dias = array_values(array_map(function ($d) {
                $d['lotes'] = array_values($d['lotes']);
                // ordenar lotes por fecha desc (si quieres)
                usort($d['lotes'], fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
                return $d;
            }, $diasMap));

            // ordenar días desc
            usort($dias, fn($a,$b) => strcmp($b['fecha'], $a['fecha']));

            return $this->jsonOk([
                'success' => true,
                'placas_hoy' => $placasHoy,
                'dias' => $dias,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * ✅ GET /placas/archivos/productos-por-producir
     *
     * Tu error: "Unknown column 'estado' in WHERE"
     * => aquí detectamos columnas/tablas sin asumir "estado".
     *
     * Devuelve items:
     * { id, pedido_numero, producto, cantidad, label }
     */
    public function productosPorProducir(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            $tables = $db->listTables();

            // Preferimos SIEMPRE tu tabla interna, NO Shopify
            $preferred = ['pedidos', 'pedidos_internos', 'pedidos_cache', 'panel_pedidos'];
            $orderTable = null;

            foreach ($preferred as $t) {
                if (in_array($t, $tables, true)) { $orderTable = $t; break; }
            }

            // Fallback: detectar por nombre
            if (!$orderTable) {
                foreach ($tables as $t) {
                    $lt = strtolower($t);
                    if (strpos($lt, 'pedido') !== false) { $orderTable = $t; break; }
                }
            }

            if (!$orderTable) {
                return $this->response->setJSON([
                    'success' => true,
                    'items'   => [],
                    'message' => 'No se encontró tabla interna de pedidos.',
                ]);
            }

            $cols = $db->getFieldNames($orderTable) ?: [];

            $pick = function(array $names) use ($cols) {
                foreach ($names as $n) if (in_array($n, $cols, true)) return $n;
                return null;
            };

            // ✅ Columnas típicas del panel interno
            $idCol     = $pick(['id', 'pedido_id', 'order_id']);
            $numeroCol = $pick(['numero', 'number', 'pedido_numero', 'numero_pedido', 'order_number', 'folio', 'codigo']);
            $estadoCol = $pick(['estado', 'estado_interno', 'estado_actual', 'status']);
            $clienteCol= $pick(['cliente', 'cliente_nombre', 'nombre_cliente', 'customer', 'customer_name']);
            $fechaCol  = $pick(['fecha', 'created_at', 'created', 'fecha_creacion']);
            $artCol    = $pick(['articulos', 'items', 'items_count', 'total_items']);

            if (!$idCol) $idCol = 'id';

            // ✅ Estado deseado (por defecto: Por preparar)
            $estadoWanted = trim((string)($this->request->getGet('estado') ?? 'Por preparar'));
            $estadoWantedLike = strtolower($estadoWanted);

            $b = $db->table($orderTable);

            // SELECT mínimo
            $select = ["$idCol as id"];
            if ($numeroCol)  $select[] = "$numeroCol as numero";
            if ($estadoCol)  $select[] = "$estadoCol as estado";
            if ($clienteCol) $select[] = "$clienteCol as cliente";
            if ($fechaCol)   $select[] = "$fechaCol as fecha";
            if ($artCol)     $select[] = "$artCol as articulos";

            $b->select(implode(',', $select));

            // ✅ Filtro por estado interno = Por preparar (robusto)
            if ($estadoCol) {
                // Si tu campo es texto: "Por preparar"
                $b->groupStart()
                ->like($estadoCol, $estadoWantedLike) // captura "Por preparar", "por preparar", etc.
                ->orLike($estadoCol, 'prepar')        // extra robustez por si cambia
                ->groupEnd();
            }

            // Orden
            if ($fechaCol) $b->orderBy($fechaCol, 'DESC');
            else $b->orderBy($idCol, 'DESC');

            $b->limit(300);

            $rows = $b->get()->getResultArray();

            // ✅ Formateo: usar el numero REAL (#PEDIDO0000...) si existe
            $items = array_map(function($r) {
                $numero = (string)($r['numero'] ?? '');
                $estado = (string)($r['estado'] ?? '');
                $cliente = (string)($r['cliente'] ?? '');
                $fecha = (string)($r['fecha'] ?? '');
                $articulos = $r['articulos'] ?? null;

                // Si viene solo un número, lo convertimos a #PEDIDO0000 (por si acaso)
                if ($numero !== '' && strpos($numero, '#') !== 0 && preg_match('/^\d+$/', $numero)) {
                    $numero = '#PEDIDO' . str_pad($numero, 4, '0', STR_PAD_LEFT);
                }

                // Si viene "pedido0001" sin #, se lo ponemos
                if ($numero !== '' && stripos($numero, 'pedido') === 0 && strpos($numero, '#') !== 0) {
                    $numero = '#'.strtoupper($numero);
                }

                $label = $numero !== '' ? $numero : ('Pedido #' . (string)($r['id'] ?? ''));
                if ($cliente !== '') $label .= ' — ' . $cliente;
                if ($estado !== '')  $label .= ' — ' . $estado;

                return [
                    'id'      => (string)($r['id'] ?? ''),
                    'numero'  => $numero,
                    'estado'  => $estado,
                    'cliente' => $cliente,
                    'fecha'   => $fecha,
                    'articulos' => $articulos,
                    'label'   => $label,
                ];
            }, $rows);

            return $this->response->setJSON([
                'success' => true,
                'items'   => $items,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'productosPorProducir() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /placas/archivos/subir-lote
     * fields:
     * - lote_nombre (req)
     * - numero_placa (opt)
     * - productos (json array string) (opt)
     * - pedidos   (json array string) (opt)
     * - archivos[] (req)
     */
    public function subirLote(): ResponseInterface
    {
        try {
            $loteNombre  = trim((string)$this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string)$this->request->getPost('numero_placa'));

            if ($loteNombre === '') {
                return $this->jsonFail('El nombre del lote es obligatorio.', 400);
            }

            $files = $this->request->getFiles();
            $archivos = $files['archivos'] ?? null;

            if (!$archivos) {
                return $this->jsonFail('No se recibieron archivos.', 400);
            }

            // Normalizar a array
            if (!is_array($archivos)) $archivos = [$archivos];
            $archivos = array_values(array_filter($archivos, fn($f) => $f && $f->isValid()));

            if (!count($archivos)) {
                return $this->jsonFail('Selecciona uno o más archivos válidos.', 400);
            }

            $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

            // meta
            $productos = $this->request->getPost('productos'); // JSON string
            $pedidos   = $this->request->getPost('pedidos');   // JSON string

            $baseDir = 'placas/' . $loteId;
            $targetDir = rtrim(WRITEPATH, '/\\') . '/uploads/' . $baseDir;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            $first = true;
            $saved = 0;

            foreach ($archivos as $f) {
                /** @var \CodeIgniter\HTTP\Files\UploadedFile $f */
                $origName = $f->getClientName();
                $mime     = $f->getClientMimeType();
                $size     = $f->getSize();

                $newName = $f->getRandomName();
                $f->move($targetDir, $newName);

                $rutaRel = $baseDir . '/' . $newName;

                $nombreSinExt = pathinfo($origName, PATHINFO_FILENAME);

                $this->m->insert([
                    'lote_id'      => $loteId,
                    'lote_nombre'  => $loteNombre,
                    'numero_placa' => $numeroPlaca,

                    'ruta'     => $rutaRel,
                    'mime'     => $mime,
                    'size'     => (int)$size,
                    'original' => $origName,
                    'nombre'   => $nombreSinExt,

                    'is_primary' => $first ? 1 : 0,

                    // ✅ el Model convierte a productos_json/pedidos_json
                    'productos' => $productos,
                    'pedidos'   => $pedidos,
                ]);

                $first = false;
                $saved++;
            }

            return $this->jsonOk([
                'success' => true,
                'message' => "✅ Lote creado ($loteId) con $saved archivo(s).",
                'lote_id' => $loteId,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * POST /placas/archivos/renombrar
     * fields: id, nombre
     */
    public function renombrar(): ResponseInterface
    {
        try {
            $id = (int)$this->request->getPost('id');
            $nombre = trim((string)$this->request->getPost('nombre'));

            if (!$id) return $this->jsonFail('ID inválido.', 400);
            if ($nombre === '') return $this->jsonFail('El nombre no puede estar vacío.', 400);

            $row = $this->m->find($id);
            if (!$row) return $this->jsonFail('No existe.', 404);

            $this->m->update($id, ['nombre' => $nombre]);

            return $this->jsonOk(['success' => true, 'message' => '✅ Renombrado']);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * POST /placas/archivos/eliminar
     * fields: id
     */
    public function eliminar(): ResponseInterface
    {
        try {
            $id = (int)$this->request->getPost('id');
            if (!$id) return $this->jsonFail('ID inválido.', 400);

            $row = $this->m->find($id);
            if (!$row) return $this->jsonFail('No existe.', 404);

            $path = $this->fileFullPathFromRuta((string)($row['ruta'] ?? ''));
            if (is_file($path)) @unlink($path);

            $this->m->delete($id);

            return $this->jsonOk(['success' => true, 'message' => '✅ Eliminado']);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * POST /placas/archivos/lote/renombrar
     * fields: lote_id, lote_nombre
     */
    public function renombrarLote(): ResponseInterface
    {
        try {
            $loteId = trim((string)$this->request->getPost('lote_id'));
            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));

            if ($loteId === '') return $this->jsonFail('lote_id requerido', 400);
            if ($loteNombre === '') return $this->jsonFail('El nombre no puede estar vacío', 400);

            $this->m->where('lote_id', $loteId)->set(['lote_nombre' => $loteNombre])->update();

            return $this->jsonOk(['success' => true, 'message' => '✅ Lote renombrado']);
        } catch (\Throwable $e) {
            return $this->jsonFail($e->getMessage(), 500);
        }
    }

    /**
     * GET /placas/archivos/descargar/{id}
     * Incluye WEBP->PNG
     */
    public function descargar($id)
    {
        $row = $this->m->find((int)$id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = $this->fileFullPathFromRuta($ruta);
        if (!is_file($path)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: $this->guessMime($path);
        $originalName = $row['original'] ?: ('archivo_' . $id);

        // WEBP -> PNG
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

    /**
     * GET /placas/archivos/descargar-png/{id}
     */
    public function descargarPng($id)
    {
        return $this->convertImageDownload((int)$id, 'png');
    }

    /**
     * GET /placas/archivos/descargar-jpg/{id}
     */
    public function descargarJpg($id)
    {
        return $this->convertImageDownload((int)$id, 'jpg');
    }

    private function convertImageDownload(int $id, string $format)
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $path = $this->fileFullPathFromRuta((string)($row['ruta'] ?? ''));
        if (!is_file($path)) return $this->response->setStatusCode(404);

        $mime = $row['mime'] ?: $this->guessMime($path);
        if (strpos($mime, 'image/') !== 0) {
            return $this->response->setStatusCode(400)->setBody('No es una imagen.');
        }

        // cargar imagen según mime
        $im = null;
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($path);
        if ($mime === 'image/png')  $im = @imagecreatefrompng($path);
        if ($mime === 'image/jpeg') $im = @imagecreatefromjpeg($path);

        if (!$im) return $this->response->setStatusCode(500)->setBody('No se pudo convertir la imagen.');

        $base = $row['original'] ?: ('archivo_' . $id);
        $base = preg_replace('/\.[^.]+$/', '', $base);
        $downloadName = $base . '.' . ($format === 'jpg' ? 'jpg' : 'png');

        ob_start();
        if ($format === 'jpg') {
            imagejpeg($im, null, 92);
            $outMime = 'image/jpeg';
        } else {
            imagepng($im, null, 9);
            $outMime = 'image/png';
        }
        imagedestroy($im);
        $bin = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', $outMime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
            ->setBody($bin);
    }

    /**
     * GET /placas/archivos/descargar-png-lote/{loteId}
     * ZIP de PNGs
     */
    public function descargarPngLote($loteId)
    {
        return $this->zipLoteImages((string)$loteId, 'png');
    }

    /**
     * GET /placas/archivos/descargar-jpg-lote/{loteId}
     * ZIP de JPGs
     */
    public function descargarJpgLote($loteId)
    {
        return $this->zipLoteImages((string)$loteId, 'jpg');
    }

    private function zipLoteImages(string $loteId, string $format)
    {
        $rows = $this->m->where('lote_id', $loteId)->orderBy('id', 'ASC')->findAll();
        if (!$rows) return $this->response->setStatusCode(404)->setBody('Lote vacío.');

        $zipPath = WRITEPATH . 'cache/' . 'lote_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $loteId) . '_' . $format . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->response->setStatusCode(500)->setBody('No se pudo crear ZIP.');
        }

        foreach ($rows as $r) {
            $path = $this->fileFullPathFromRuta((string)($r['ruta'] ?? ''));
            if (!is_file($path)) continue;

            $mime = $r['mime'] ?: $this->guessMime($path);
            if (strpos($mime, 'image/') !== 0) continue;

            // cargar imagen
            $im = null;
            if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($path);
            if ($mime === 'image/png')  $im = @imagecreatefrompng($path);
            if ($mime === 'image/jpeg') $im = @imagecreatefromjpeg($path);
            if (!$im) continue;

            $base = $r['original'] ?: ('archivo_' . $r['id']);
            $base = preg_replace('/\.[^.]+$/', '', $base);
            $nameInZip = $base . '_' . $r['id'] . '.' . ($format === 'jpg' ? 'jpg' : 'png');

            ob_start();
            if ($format === 'jpg') imagejpeg($im, null, 92);
            else imagepng($im, null, 9);
            $bin = ob_get_clean();
            imagedestroy($im);

            $zip->addFromString($nameInZip, $bin);
        }

        $zip->close();

        $downloadName = 'lote_' . $loteId . '_' . strtoupper($format) . '.zip';

        return $this->response
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
            ->setBody(file_get_contents($zipPath));
    }
}
