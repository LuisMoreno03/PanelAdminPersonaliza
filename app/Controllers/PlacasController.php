<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasController extends BaseController
{
    public function index()
    {
        return view('placas');
    }

    /**
     * Lista archivos por lote
     * GET /placas/{loteId}/archivos
     */
    public function archivos(int $loteId): ResponseInterface
    {
        $m = new PlacaArchivoModel();

        $rows = $m->where('lote_id', $loteId)
            ->orderBy('id', 'DESC')
            ->findAll();

        $items = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'original_name' => (string) ($r['original'] ?? $r['nombre'] ?? ''),
                'mime'          => (string) ($r['mime'] ?? ''),
                'size'          => (int) ($r['size'] ?? 0),
                'created_at'    => (string) ($r['created_at'] ?? ''),
                'download_url'  => site_url('placas/descargar/' . $r['id']),
            ];
        }, $rows);

        return $this->response->setJSON([
            'success' => true,
            'items'   => $items,
        ]);
    }

    /**
     * Lista TODOS los archivos (para tu UI)
     * GET /placas/listar  (o la ruta que tengas)
     */
    public function listar(): ResponseInterface
    {
        helper('url');

        try {
            $model = new PlacaArchivoModel();
            $items = $model->orderBy('id', 'DESC')->findAll();

            foreach ($items as &$it) {
                $ruta = $it['ruta'] ?? '';
                $it['url'] = $ruta ? base_url($ruta) : null;

                $it['created_at'] = $it['created_at'] ?? null;

                $original = $it['original'] ?? ($it['original_name'] ?? ($it['filename'] ?? null));
                $it['original'] = $original;
                $it['nombre']   = $original ? pathinfo($original, PATHINFO_FILENAME) : null;

                $it['lote_id'] = $it['lote_id'] ?? ($it['conjunto_id'] ?? ($it['lote'] ?? null));
            }

            return $this->response->setJSON([
                'success' => true,
                'items'   => $items,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Placas listar() ERROR: {msg} | {file}:{line}', [
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
     * Descarga un archivo por id
     * GET /placas/descargar/{archivoId}
     */
    public function descargar(int $archivoId)
    {
        $m = new PlacaArchivoModel();
        $r = $m->find($archivoId);

        if (!$r) {
            return $this->response->setStatusCode(404)->setBody('Archivo no encontrado');
        }

        $loteId = (int) ($r['lote_id'] ?? 0);
        $ruta   = (string) ($r['ruta'] ?? '');
        $nombre = (string) ($r['nombre'] ?? '');

        if ($loteId <= 0) {
            return $this->response->setStatusCode(400)->setBody('Falta lote_id');
        }

        // 1) Si ruta ya es absoluta y existe
        if ($ruta !== '' && is_file($ruta)) {
            $path = $ruta;
        } else {
            // 2) Si ruta es relativa (por ejemplo: uploads/placas/66/archivo.png)
            if ($ruta !== '' && is_file(WRITEPATH . $ruta)) {
                $path = WRITEPATH . $ruta;
            } else {
                // 3) Si no hay ruta usable, construimos por estructura
                if ($nombre === '') {
                    return $this->response->setStatusCode(400)->setBody('Falta nombre o ruta');
                }
                $path = WRITEPATH . 'uploads/placas/' . $loteId . '/' . $nombre;
            }
        }

        if (!is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Archivo no encontrado en disco');
        }

        $downloadName = (string) ($r['original'] ?? $nombre);

        return $this->response->download($path, null)->setFileName($downloadName);
    }
}



