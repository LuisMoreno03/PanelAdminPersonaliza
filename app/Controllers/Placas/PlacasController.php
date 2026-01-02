<?php

namespace App\Controllers;

class PlacasController extends BaseController
{
    public function index()
    {
        // Vista principal del apartado
        return view('placas/index');
    }

    /**
     * Endpoint de filtro (AJAX)
     * Ej: /placas/filter?q=ABC&estado=pendiente
     */
    public function filter()
    {
        $q = trim((string) $this->request->getGet('q'));
        $estado = trim((string) $this->request->getGet('estado'));

        // DEMO: datos ficticios (luego lo conectas a DB o Shopify)
        $placas = [
            ['id' => 1, 'codigo' => 'ABC-123', 'cliente' => 'Juan', 'estado' => 'pendiente', 'fecha' => '2026-01-02'],
            ['id' => 2, 'codigo' => 'XYZ-777', 'cliente' => 'Maria', 'estado' => 'listo',     'fecha' => '2026-01-01'],
            ['id' => 3, 'codigo' => 'LMN-555', 'cliente' => 'Pedro', 'estado' => 'pendiente', 'fecha' => '2026-01-02'],
        ];

        // Filtros
        $filtradas = array_filter($placas, function ($p) use ($q, $estado) {
            $okQ = $q === '' || stripos($p['codigo'], $q) !== false || stripos($p['cliente'], $q) !== false;
            $okE = $estado === '' || $p['estado'] === $estado;
            return $okQ && $okE;
        });

        return $this->response->setJSON([
            'success' => true,
            'total'   => count($filtradas),
            'items'   => array_values($filtradas),
        ]);
    }

    /**
     * Guardar placa (opcional)
     */
    public function guardar()
    {
        $codigo  = trim((string) $this->request->getPost('codigo'));
        $cliente = trim((string) $this->request->getPost('cliente'));

        if ($codigo === '' || $cliente === '') {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Código y cliente son obligatorios',
            ])->setStatusCode(422);
        }

        // Aquí luego insertas en DB. Por ahora solo demo:
        return $this->response->setJSON([
            'success' => true,
            'message' => 'Placa guardada (demo)',
            'data'    => [
                'codigo' => $codigo,
                'cliente' => $cliente,
                'estado' => 'pendiente',
            ],
        ]);
    }
}
