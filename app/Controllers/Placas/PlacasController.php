<?php

namespace App\Controllers;

class PlacasController extends BaseController
{
    public function index()
    {
  
        return view('placas/index');
    }


    public function filter()
    {
        $q = trim((string) $this->request->getGet('q'));
        $estado = trim((string) $this->request->getGet('estado'));


        $placas = [
            ['id' => 1, 'codigo' => 'ABC-123', 'cliente' => 'Juan',  'estado' => 'pendiente', 'fecha' => date('Y-m-d')],
            ['id' => 2, 'codigo' => 'XYZ-777', 'cliente' => 'Maria', 'estado' => 'listo',     'fecha' => date('Y-m-d')],
        ];


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


        return $this->response->setJSON([
            'success' => true,
            'message' => 'Placa guardada (demo)',
        
        ]);
    }
}
