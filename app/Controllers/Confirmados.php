<?php

namespace App\Controllers;

class Confirmados extends BaseController
{
    public function index()
    {
        return view('confirmados');
    }

    public function filter()
{
    return $this->response->setJSON([
        'success' => true,
        'orders' => [
            [
                'id' => 123,
                'numero' => '#1001',
                'fecha' => date('Y-m-d H:i'),
                'cliente' => 'Cliente Prueba',
                'total' => '€ 19,99',
                'estado' => 'Preparado',
                'etiquetas' => 'Preparado,p.urgent',
                'articulos' => 2,
                'estado_envio' => 'Pendiente',
                'forma_envio' => 'Envío'
            ]
        ],
        'next_page_info' => null
    ]);
}

}
