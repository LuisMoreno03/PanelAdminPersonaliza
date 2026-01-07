<?php

namespace App\Controllers;

use App\Models\PlacaArchivoModel;
use CodeIgniter\HTTP\ResponseInterface;

class PlacasController extends BaseController
{
    /**
     * Vista principal de Placas
     */
    public function index()
    {
        return view('placas'); // ajusta si tu vista tiene otro nombre
    }

    /**
     * Lista archivos por conjunto
     * GET /placas/{conjuntoId}/archivos
     */
    public function archivos(int $conjuntoId): ResponseInterface
    {
        $m = new PlacaArchivoModel();

        $rows = $m->where('conjunto_id', $conjuntoId)
                  ->orderBy('id', 'DESC')
                  ->findAll();

        $items = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'original_name' => (string) ($r['original_name'] ?? ''),
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

        $conjuntoId = (int) $r['conjunto_id'];
        $filename   = (string) $r['filename'];
        $origName   = (string) ($r['original_name'] ?: $filename);

        // ✅ AJUSTA ESTA RUTA a donde guardas tus archivos realmente
        $path = WRITEPATH . 'uploads/placas/' . $conjuntoId . '/' . $filename;

        if (!is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Archivo físico no encontrado');
        }

        return $this->response->download($path, null)->setFileName($origName);
    }
}
