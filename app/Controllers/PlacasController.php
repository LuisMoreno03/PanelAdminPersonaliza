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
     * OJO: loteId es STRING (ej: L20260108_....)
     */
    public function archivos(string $loteId): ResponseInterface
    {
        $m = new PlacaArchivoModel();

        $rows = $m->where('lote_id', $loteId)
            ->orderBy('id', 'DESC')
            ->findAll();

        $items = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'original_name' => (string) ($r['original'] ?? $r['nombre'] ?? $r['filename'] ?? ''),
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
     * Lista TODOS los archivos
     * GET /placas/listar
     */
    public function listar(): ResponseInterface
    {
        helper('url');

        try {
            $model = new PlacaArchivoModel();
            $items = $model->orderBy('id', 'DESC')->findAll();

            foreach ($items as &$it) {
                $ruta = (string) ($it['ruta'] ?? '');
                $it['url'] = $ruta !== '' ? base_url($ruta) : null;

                $it['created_at'] = $it['created_at'] ?? null;

                $original = $it['original'] ?? ($it['original_name'] ?? ($it['filename'] ?? null));
                $it['original'] = $original;
                $it['nombre']   = $it['nombre'] ?? ($original ? pathinfo($original, PATHINFO_FILENAME) : null);

                $it['lote_id'] = $it['lote_id'] ?? ($it['conjunto_id'] ?? ($it['lote'] ?? null));
            }
            unset($it);

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

        // ✅ En tu sistema la ruta guardada es relativa a FCPATH (public)
        $ruta = (string) ($r['ruta'] ?? '');
        if ($ruta === '') {
            return $this->response->setStatusCode(422)->setBody('Registro incompleto: falta ruta');
        }

        $fullPath = FCPATH . ltrim($ruta, '/');

        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody("Archivo no existe en disco: {$fullPath}");
        }

        $downloadName = (string) ($r['original'] ?? $r['original_name'] ?? $r['filename'] ?? basename($fullPath));
        return $this->response->download($fullPath, null)->setFileName($downloadName);
    }
}



