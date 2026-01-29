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

    /* ===========================
       GET /placas/archivos/stats
    =========================== */
    public function stats(): ResponseInterface
    {
        try {
            $hoy = date('Y-m-d');
            $count = $this->m
                ->where('created_at >=', $hoy . ' 00:00:00')
                ->where('created_at <=', $hoy . ' 23:59:59')
                ->countAllResults();

            return $this->response->setJSON([
                'success' => true,
                'data' => ['total' => $count]
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'stats ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* ==========================================
       GET /placas/archivos/listar-por-dia
       - dias[] con lotes[] y items[]
    ========================================== */
    public function listarPorDia(): ResponseInterface
    {
        try {
            $rows = $this->m->orderBy('created_at', 'DESC')->findAll();

            $placasHoy = 0;
            $hoy = date('Y-m-d');

            $dias = []; // fecha => data
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

                $item = $this->rowToItem($r);
                $item['is_primary'] = ((int)($r['is_primary'] ?? 0) === 1) ? 1 : 0;

                $dias[$fecha]['lotes'][$loteId]['items'][] = $item;
            }

            // normalizar
            $diasArr = [];
            foreach ($dias as $d) {
                $lotesArr = array_values($d['lotes']);

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

            usort($diasArr, fn($a,$b) => strcmp($b['fecha'], $a['fecha']));

            return $this->response->setJSON([
                'success' => true,
                'placas_hoy' => $placasHoy,
                'dias' => $diasArr
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'listarPorDia ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =========================================================
       ✅ NUEVO
       GET /placas/archivos/productos-por-producir
       Devuelve productos que estén en estado "Por producir"
       Formato:
       {success:true, items:[{id,label,pedido_id?,pedido_numero?,producto?,cantidad?}]}
    ========================================================= */
    public function productosPorProducir(): ResponseInterface
    {
        try {
            // =====================================================
            // OPCIÓN REAL (RECOMENDADA):
            // Aquí debes traer tus pedidos/productos desde tu BD.
            //
            // Ejemplo (si existe tu modelo):
            // $pm = new \App\Models\PedidoModel();
            // $rows = $pm->where('estado', 'Por producir')->orderBy('id', 'DESC')->findAll();
            //
            // Y mapear a items con label.
            // =====================================================

            // Intento “no romper” si aún no existe el modelo real:
            $items = [];

            $pedidoModelClass = '\\App\\Models\\PedidoModel';
            if (class_exists($pedidoModelClass)) {
                /** @var \CodeIgniter\Model $pm */
                $pm = new $pedidoModelClass();

                // Ajusta campos reales: estado/estatus, numero, etc.
                $rows = $pm->where('estado', 'Por producir')->orderBy('id', 'DESC')->findAll();

                foreach ($rows as $r) {
                    // Ajusta a tu estructura: ejemplo genérico
                    $pedidoId = $r['id'] ?? null;
                    $pedidoNumero = $r['numero'] ?? ($r['pedido'] ?? $pedidoId);

                    // Si en tu tabla el producto está en una columna:
                    $producto = $r['producto'] ?? ($r['titulo'] ?? 'Producto');

                    $cantidad = $r['cantidad'] ?? 1;

                    $label = "Pedido #{$pedidoNumero} — {$producto} x{$cantidad}";

                    $items[] = [
                        'id' => (string)($pedidoId ?? uniqid()),
                        'pedido_id' => $pedidoId,
                        'pedido_numero' => (string)$pedidoNumero,
                        'producto' => (string)$producto,
                        'cantidad' => (int)$cantidad,
                        'label' => $label
                    ];
                }

                return $this->response->setJSON([
                    'success' => true,
                    'items' => $items
                ]);
            }

            // Fallback (mock) para que la UI no reviente
            $items = [
                ['id' => 'mock1', 'pedido_numero' => '9095', 'producto' => 'Cuadro 20x30', 'cantidad' => 1, 'label' => 'Pedido #9095 — Cuadro 20x30 x1'],
                ['id' => 'mock2', 'pedido_numero' => '9102', 'producto' => 'Marco Negro', 'cantidad' => 2, 'label' => 'Pedido #9102 — Marco Negro x2'],
            ];

            return $this->response->setJSON([
                'success' => true,
                'items' => $items,
                'note' => '⚠️ Esto es un mock. Conecta tu modelo real (PedidoModel) para datos reales.'
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'productosPorProducir ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =========================================================
       POST /placas/archivos/subir-lote
       FormData:
         - lote_nombre (obligatorio)
         - numero_placa (opcional)
         - pedidos (opcional: "1,2,3" o JSON)
         - productos (opcional: texto o JSON)
         - productos_ids[] (opcional: si seleccionas del listado)
         - archivos[] (obligatorio, multi)
    ========================================================= */
    public function subirLote(): ResponseInterface
    {
        try {
            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string)$this->request->getPost('numero_placa'));

            $pedidosRaw = $this->request->getPost('pedidos');
            $productosRaw = $this->request->getPost('productos');

            // ✅ selección desde UI (multi-select / chips)
            $productosIds = $this->request->getPost('productos_ids');
            if (!is_array($productosIds)) {
                // también puede venir como "productos_ids[]"
                $productosIds = $this->request->getPost('productos_ids[]');
            }

            if ($loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'El nombre del lote es obligatorio.'
                ]);
            }

            $pedidos = $this->parseList($pedidosRaw);

            // productos por texto
            $productosTexto = $this->parseList($productosRaw);

            // productos seleccionados (IDs/labels)
            $productosSeleccionados = [];
            if (is_array($productosIds)) {
                foreach ($productosIds as $p) {
                    $p = trim((string)$p);
                    if ($p !== '') $productosSeleccionados[] = $p;
                }
            }

            // decide productos_final
            // prioridad: seleccionados; si no, texto; si no, numeroPlaca
            $productosFinal = [];
            if (!empty($productosSeleccionados)) $productosFinal = $productosSeleccionados;
            else if (!empty($productosTexto)) $productosFinal = $productosTexto;
            else if ($numeroPlaca !== '') $productosFinal = [$numeroPlaca];

            $loteId = 'L' . date('Ymd_His') . '_' . substr(sha1($loteNombre . microtime(true)), 0, 8);

            $fecha = date('Y-m-d');
            $destDir = WRITEPATH . 'uploads/placas/' . $fecha . '/' . $loteId;
            if (!is_dir($destDir)) mkdir($destDir, 0775, true);

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
                $nombre = pathinfo($original, PATHINFO_FILENAME);

                $this->m->insert([
                    'lote_id' => $loteId,
                    'lote_nombre' => $loteNombre,
                    'pedidos_json' => $pedidos ? json_encode($pedidos, JSON_UNESCAPED_UNICODE) : null,
                    'productos_json' => $productosFinal ? json_encode($productosFinal, JSON_UNESCAPED_UNICODE) : null,

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
    ========================================================= */
    public function renombrarArchivo(): ResponseInterface
    {
        try {
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
        } catch (\Throwable $e) {
            log_message('error', 'renombrarArchivo ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =========================================================
       POST /placas/archivos/eliminar
    ========================================================= */
    public function eliminarArchivo(): ResponseInterface
    {
        try {
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
        } catch (\Throwable $e) {
            log_message('error', 'eliminarArchivo ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* =========================================================
       POST /placas/archivos/lote/renombrar
    ========================================================= */
    public function renombrarLote(): ResponseInterface
    {
        try {
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
        } catch (\Throwable $e) {
            log_message('error', 'renombrarLote ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
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

    public function descargarPng(int $id) { return $this->convertImageDownload($id, 'png'); }
    public function descargarJpg(int $id) { return $this->convertImageDownload($id, 'jpg'); }
    public function descargarPngLote(string $loteId) { return $this->zipLoteConverted($loteId, 'png'); }
    public function descargarJpgLote(string $loteId) { return $this->zipLoteConverted($loteId, 'jpg'); }

    /* =======================
       Opcional: pedidos->productos
    ======================= */
    public function productosDePedidos(): ResponseInterface
    {
        $ids = (string)$this->request->getGet('ids');
        $idsArr = $this->parseList($ids);

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
            'thumb_url' => $url,
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
        if ($fmt === 'png') { imagepng($im, null, 9); $outMime = 'image/png'; $ext = 'png'; }
        else { imagejpeg($im, null, 92); $outMime = 'image/jpeg'; $ext = 'jpg'; }
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
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($path);
        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) return @imagecreatefrompng($path);
        if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('imagecreatefromjpeg')) return @imagecreatefromjpeg($path);
        if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) return @imagecreatefromgif($path);

        if (function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($path);
            if ($raw) return @imagecreatefromstring($raw);
        }
        return null;
    }
}
