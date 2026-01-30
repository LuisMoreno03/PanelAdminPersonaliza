<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;
use ZipArchive;

class PlacasArchivosController extends BaseController
{
    private PlacaArchivoModel $m;

    public function __construct()
    {
        $this->m = new PlacaArchivoModel();
        helper(['url', 'filesystem']);
    }

    // ----------------------------
    // ✅ PEDIDOS INTERNOS: SOLO "Por producir"
    // ----------------------------
    public function productosPorProducir(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            $tables = $db->listTables();

            // (Puedes fijar aquí tu tabla real si la sabes, ej: $orderTable='pedidos_cache';
            $preferred = ['pedidos', 'pedidos_internos', 'pedidos_cache', 'panel_pedidos'];
            $orderTable = null;

            foreach ($preferred as $t) {
                if (in_array($t, $tables, true)) { $orderTable = $t; break; }
            }
            if (!$orderTable) {
                foreach ($tables as $t) {
                    if (stripos($t, 'pedido') !== false) { $orderTable = $t; break; }
                }
            }

            if (!$orderTable) {
                return $this->response->setJSON(['success'=>true,'items'=>[],'message'=>'No se encontró tabla interna de pedidos.']);
            }

            $cols = $db->getFieldNames($orderTable) ?: [];
            $pick = function(array $names) use ($cols) {
                foreach ($names as $n) if (in_array($n, $cols, true)) return $n;
                return null;
            };

            // Según tu panel: numero (#PEDIDO10914), estado (Por producir), cliente, fecha, articulos
            $idCol     = $pick(['id', 'pedido_id', 'order_id']) ?: 'id';
            $numeroCol = $pick(['numero', 'number', 'pedido_numero', 'numero_pedido', 'order_number', 'folio', 'codigo']);
            $estadoCol = $pick(['estado', 'estado_interno', 'estado_actual', 'status']);
            $clienteCol= $pick(['cliente', 'cliente_nombre', 'nombre_cliente', 'customer', 'customer_name']);
            $fechaCol  = $pick(['fecha', 'created_at', 'created', 'fecha_creacion']);
            $artCol    = $pick(['articulos', 'items', 'items_count', 'total_items']);

            $estadoWanted = 'Por producir';

            $b = $db->table($orderTable);
            $select = ["$idCol as id"];
            if ($numeroCol)  $select[] = "$numeroCol as numero";
            if ($estadoCol)  $select[] = "$estadoCol as estado";
            if ($clienteCol) $select[] = "$clienteCol as cliente";
            if ($fechaCol)   $select[] = "$fechaCol as fecha";
            if ($artCol)     $select[] = "$artCol as articulos";

            $b->select(implode(',', $select));

            if ($estadoCol) {
                $b->groupStart()
                  ->where($estadoCol, $estadoWanted)
                  ->orWhere("LOWER($estadoCol) = ", strtolower($estadoWanted), false)
                  ->orLike($estadoCol, 'por produc')
                  ->groupEnd();
            }

            if ($fechaCol) $b->orderBy($fechaCol, 'DESC');
            else $b->orderBy($idCol, 'DESC');

            $b->limit(400);
            $rows = $b->get()->getResultArray();

            $items = array_map(function($r) {
                $numero = (string)($r['numero'] ?? '');
                $estado = (string)($r['estado'] ?? 'Por producir');
                $cliente = (string)($r['cliente'] ?? '');
                $fecha = (string)($r['fecha'] ?? '');
                $articulos = $r['articulos'] ?? null;

                // asegurar formato #PEDIDO...
                if ($numero !== '' && strpos($numero, '#') !== 0 && stripos($numero, 'pedido') !== false) {
                    $numero = '#'.strtoupper(ltrim($numero, '#'));
                }
                if ($numero !== '' && strpos($numero, '#') !== 0 && preg_match('/^\d+$/', $numero)) {
                    $numero = '#PEDIDO' . str_pad($numero, 4, '0', STR_PAD_LEFT);
                }

                $label = ($numero !== '' ? $numero : ('Pedido #' . (string)($r['id'] ?? '')));
                if ($cliente !== '') $label .= ' — ' . $cliente;
                $label .= ' — ' . $estado;

                return [
                    'id'             => (string)($r['id'] ?? ''),
                    'pedido_display' => $numero,
                    'numero'         => $numero,
                    'estado'         => $estado,
                    'cliente'        => $cliente,
                    'fecha'          => $fecha,
                    'articulos'      => $articulos,
                    'label'          => $label,
                ];
            }, $rows);

            return $this->response->setJSON(['success'=>true,'items'=>$items]);

        } catch (\Throwable $e) {
            log_message('error', 'productosPorProducir ERROR: {m}', ['m'=>$e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['success'=>false,'message'=>$e->getMessage()]);
        }
    }

    // ----------------------------
    // ✅ LISTAR AGRUPADO POR DÍA + LOTES
    // ----------------------------
    public function listarPorDia(): ResponseInterface
    {
        try {
            $rows = $this->m->orderBy('id','DESC')->findAll();

            // enrich + parse pedidos_json
            foreach ($rows as &$r) {
                $r['url'] = $r['ruta'] ? base_url($r['ruta']) : null;
                $r['thumb_url'] = $r['url']; // si quieres miniaturas reales luego lo ajustamos

                $pj = $r['pedidos_json'] ?? '[]';
                $arr = json_decode($pj, true);
                $r['pedidos'] = is_array($arr) ? $arr : [];
            }
            unset($r);

            // agrupar por día (created_at)
            $dias = [];
            foreach ($rows as $r) {
                $created = (string)($r['created_at'] ?? '');
                $dateKey = $created ? substr($created, 0, 10) : date('Y-m-d');

                if (!isset($dias[$dateKey])) {
                    $dias[$dateKey] = [
                        'fecha' => $dateKey,
                        'total_archivos' => 0,
                        'lotes' => [],
                    ];
                }

                $loteId = (string)($r['lote_id'] ?? 'SIN_LOTE');
                if (!isset($dias[$dateKey]['lotes'][$loteId])) {
                    $dias[$dateKey]['lotes'][$loteId] = [
                        'lote_id' => $loteId,
                        'lote_nombre' => (string)($r['lote_nombre'] ?? ''),
                        'created_at' => (string)($r['created_at'] ?? ''),
                        'items' => [],
                    ];
                }

                $dias[$dateKey]['lotes'][$loteId]['items'][] = [
                    'id' => (int)$r['id'],
                    'lote_id' => (string)($r['lote_id'] ?? ''),
                    'lote_nombre' => (string)($r['lote_nombre'] ?? ''),
                    'nombre' => (string)($r['nombre'] ?? ''),
                    'original' => (string)($r['original'] ?? ''),
                    'mime' => (string)($r['mime'] ?? ''),
                    'size' => (int)($r['size'] ?? 0),
                    'ruta' => (string)($r['ruta'] ?? ''),
                    'url' => (string)($r['url'] ?? ''),
                    'thumb_url' => (string)($r['thumb_url'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'is_primary' => (int)($r['is_primary'] ?? 0),
                    'numero_placa' => (string)($r['numero_placa'] ?? ''),
                    'pedidos' => $r['pedidos'] ?? [],
                ];

                $dias[$dateKey]['total_archivos']++;
            }

            // ordenar días desc
            krsort($dias);

            // convertir lotes a array y ordenar
            $diasOut = [];
            foreach ($dias as $d) {
                $lotes = array_values($d['lotes']);
                // lotes desc por created_at
                usort($lotes, fn($a,$b)=>strcmp((string)$b['created_at'], (string)$a['created_at']));
                $d['lotes'] = $lotes;
                $diasOut[] = $d;
            }

            // placas_hoy
            $hoy = date('Y-m-d');
            $placasHoy = isset($dias[$hoy]) ? (int)$dias[$hoy]['total_archivos'] : 0;

            return $this->response->setJSON([
                'success' => true,
                'dias' => $diasOut,
                'placas_hoy' => $placasHoy,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'listarPorDia ERROR: {m}', ['m'=>$e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['success'=>false,'message'=>$e->getMessage()]);
        }
    }

    // ----------------------------
    // ✅ STATS
    // ----------------------------
    public function stats(): ResponseInterface
    {
        try {
            $hoy = date('Y-m-d');
            $count = $this->m
                ->like('created_at', $hoy, 'after')
                ->countAllResults();

            return $this->response->setJSON([
                'success' => true,
                'data' => ['total' => (int)$count],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON(['success'=>false,'message'=>$e->getMessage()]);
        }
    }

    // ----------------------------
    // ✅ SUBIR LOTE (guarda pedidos_json en cada archivo)
    // ----------------------------
    public function subirLote(): ResponseInterface
    {
        try {
            $loteNombre = trim((string)$this->request->getPost('lote_nombre'));
            $numeroPlaca = trim((string)$this->request->getPost('numero_placa'));

            if ($loteNombre === '') {
                return $this->response->setStatusCode(400)->setJSON(['success'=>false,'message'=>'El nombre del lote es obligatorio.']);
            }

            $pedidosJson = $this->request->getPost('pedidos_json');
            if (!is_string($pedidosJson) || trim($pedidosJson) === '') $pedidosJson = '[]';

            $tmp = json_decode($pedidosJson, true);
            if (!is_array($tmp)) $tmp = [];
            $pedidosJson = json_encode($tmp, JSON_UNESCAPED_UNICODE);

            $files = $this->request->getFiles();
            $archivos = $files['archivos'] ?? null;

            if (!$archivos) {
                return $this->response->setStatusCode(400)->setJSON(['success'=>false,'message'=>'Selecciona uno o más archivos.']);
            }

            // lote_id único
            $loteId = 'L' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

            $baseDir = WRITEPATH . 'uploads/placas';
            if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);

            $inserted = 0;
            $isPrimarySet = false;

            foreach ($archivos as $file) {
                if (!$file || !$file->isValid()) continue;

                $original = $file->getClientName();
                $mime = $file->getClientMimeType() ?: $file->getMimeType();
                $size = (int)$file->getSize();

                $safeName = preg_replace('/[^a-zA-Z0-9\._-]+/', '_', $original);
                $newName = time().'_'.bin2hex(random_bytes(3)).'_'.$safeName;

                $file->move($baseDir, $newName);

                // ruta pública (si tu public/index.php apunta a /public, ajusta según tu config)
                // Aquí asumimos que expones WRITEPATH/uploads vía base_url con symlink o config.
                // Si ya lo tienes funcionando, déjalo.
                $rutaRel = 'writable/uploads/placas/'.$newName;

                $this->m->insert([
                    'lote_id' => $loteId,
                    'lote_nombre' => $loteNombre,
                    'numero_placa' => $numeroPlaca,
                    'nombre' => pathinfo($original, PATHINFO_FILENAME),
                    'original' => $original,
                    'mime' => $mime,
                    'size' => $size,
                    'ruta' => $rutaRel,
                    'is_primary' => $isPrimarySet ? 0 : 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'pedidos_json' => $pedidosJson, // ✅ asignación
                ]);

                $isPrimarySet = true;
                $inserted++;
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => "✅ Subidos correctamente ({$inserted})",
                'lote_id' => $loteId,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'subirLote ERROR: {m}', ['m'=>$e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['success'=>false,'message'=>$e->getMessage()]);
        }
    }

    // ----------------------------
    // ✅ RENOMBRAR ARCHIVO
    // ----------------------------
    public function renombrar(): ResponseInterface
    {
        $id = (int)$this->request->getPost('id');
        $nombre = trim((string)$this->request->getPost('nombre'));

        if (!$id || $nombre==='') {
            return $this->response->setStatusCode(400)->setJSON(['success'=>false,'message'=>'Datos inválidos']);
        }

        $this->m->update($id, ['nombre'=>$nombre]);
        return $this->response->setJSON(['success'=>true,'message'=>'✅ Nombre actualizado']);
    }

    // ----------------------------
    // ✅ ELIMINAR ARCHIVO
    // ----------------------------
    public function eliminar(): ResponseInterface
    {
        $id = (int)$this->request->getPost('id');
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404)->setJSON(['success'=>false,'message'=>'No existe']);

        // borrar archivo físico si existe en writable
        $ruta = (string)($row['ruta'] ?? '');
        $path = FCPATH . $ruta;
        // si tu ruta no es pública real, ignora este delete o ajusta al path real
        if ($ruta && is_file($path)) @unlink($path);

        $this->m->delete($id);
        return $this->response->setJSON(['success'=>true,'message'=>'✅ Eliminado']);
    }

    // ----------------------------
    // ✅ RENOMBRAR LOTE (todos los archivos del lote)
    // ----------------------------
    public function renombrarLote(): ResponseInterface
    {
        $loteId = trim((string)$this->request->getPost('lote_id'));
        $loteNombre = trim((string)$this->request->getPost('lote_nombre'));

        if ($loteId==='' || $loteNombre==='') {
            return $this->response->setStatusCode(400)->setJSON(['success'=>false,'message'=>'Datos inválidos']);
        }

        $this->m->where('lote_id', $loteId)->set(['lote_nombre'=>$loteNombre])->update();
        return $this->response->setJSON(['success'=>true,'message'=>'✅ Lote renombrado']);
    }

    // ----------------------------
    // ✅ DESCARGA ORIGINAL
    // ----------------------------
    public function descargar(int $id)
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $originalName = (string)($row['original'] ?? ('archivo_'.$id));

        $path = FCPATH . $ruta;
        if (!is_file($path)) return $this->response->setStatusCode(404);

        $mime = (string)($row['mime'] ?? '') ?: mime_content_type($path);

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="'.$originalName.'"')
            ->setBody(file_get_contents($path));
    }

    // ----------------------------
    // ✅ DESCARGAR PNG (si imagen)
    // ----------------------------
    public function descargarPng(int $id)
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = FCPATH . $ruta;
        if (!is_file($path)) return $this->response->setStatusCode(404);

        $mime = (string)($row['mime'] ?? '') ?: mime_content_type($path);
        $name = (string)($row['original'] ?? ('archivo_'.$id));

        // si ya es png
        if (stripos($mime, 'png') !== false) {
            return $this->response
                ->setHeader('Content-Type', 'image/png')
                ->setHeader('Content-Disposition', 'attachment; filename="'.$name.'"')
                ->setBody(file_get_contents($path));
        }

        // convertir a png si imagen
        if (strpos($mime, 'image/') === 0) {
            $img = @imagecreatefromstring(file_get_contents($path));
            if (!$img) return $this->response->setStatusCode(500)->setBody('No se pudo convertir.');

            ob_start();
            imagepng($img, null, 9);
            imagedestroy($img);
            $png = ob_get_clean();

            $downloadName = preg_replace('/\.[^.]+$/', '.png', $name);
            return $this->response
                ->setHeader('Content-Type', 'image/png')
                ->setHeader('Content-Disposition', 'attachment; filename="'.$downloadName.'"')
                ->setBody($png);
        }

        // fallback original
        return $this->descargar($id);
    }

    // ----------------------------
    // ✅ DESCARGAR JPG
    // ----------------------------
    public function descargarJpg(int $id)
    {
        $row = $this->m->find($id);
        if (!$row) return $this->response->setStatusCode(404);

        $ruta = (string)($row['ruta'] ?? '');
        $path = FCPATH . $ruta;
        if (!is_file($path)) return $this->response->setStatusCode(404);

        $mime = (string)($row['mime'] ?? '') ?: mime_content_type($path);
        $name = (string)($row['original'] ?? ('archivo_'.$id));

        if (stripos($mime, 'jpeg') !== false || stripos($mime, 'jpg') !== false) {
            return $this->response
                ->setHeader('Content-Type', 'image/jpeg')
                ->setHeader('Content-Disposition', 'attachment; filename="'.$name.'"')
                ->setBody(file_get_contents($path));
        }

        if (strpos($mime, 'image/') === 0) {
            $img = @imagecreatefromstring(file_get_contents($path));
            if (!$img) return $this->response->setStatusCode(500)->setBody('No se pudo convertir.');

            ob_start();
            imagejpeg($img, null, 92);
            imagedestroy($img);
            $jpg = ob_get_clean();

            $downloadName = preg_replace('/\.[^.]+$/', '.jpg', $name);
            return $this->response
                ->setHeader('Content-Type', 'image/jpeg')
                ->setHeader('Content-Disposition', 'attachment; filename="'.$downloadName.'"')
                ->setBody($jpg);
        }

        return $this->descargar($id);
    }

    // ----------------------------
    // ✅ ZIP PNG DEL LOTE
    // ----------------------------
    public function descargarPngLote(string $loteId)
    {
        return $this->zipLote($loteId, 'png');
    }

    public function descargarJpgLote(string $loteId)
    {
        return $this->zipLote($loteId, 'jpg');
    }

    private function zipLote(string $loteId, string $format)
    {
        $rows = $this->m->where('lote_id', $loteId)->orderBy('id','ASC')->findAll();
        if (!$rows) return $this->response->setStatusCode(404);

        $tmpZip = WRITEPATH . 'uploads/tmp_' . $loteId . '_' . $format . '.zip';
        @unlink($tmpZip);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
            return $this->response->setStatusCode(500)->setBody('No se pudo crear ZIP');
        }

        foreach ($rows as $r) {
            $ruta = (string)($r['ruta'] ?? '');
            $path = FCPATH . $ruta;
            if (!is_file($path)) continue;

            $name = (string)($r['original'] ?? ('archivo_'.$r['id']));
            $mime = (string)($r['mime'] ?? '') ?: mime_content_type($path);

            // convertir si es imagen
            if (strpos($mime, 'image/') === 0) {
                $img = @imagecreatefromstring(file_get_contents($path));
                if ($img) {
                    ob_start();
                    if ($format === 'png') imagepng($img, null, 9);
                    else imagejpeg($img, null, 92);
                    imagedestroy($img);
                    $data = ob_get_clean();

                    $fname = preg_replace('/\.[^.]+$/', '.' . $format, $name);
                    $zip->addFromString($fname, $data);
                    continue;
                }
            }

            // fallback original
            $zip->addFile($path, $name);
        }

        $zip->close();

        $downloadName = "lote_{$loteId}.zip";
        return $this->response
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="'.$downloadName.'"')
            ->setBody(file_get_contents($tmpZip));
    }
}
