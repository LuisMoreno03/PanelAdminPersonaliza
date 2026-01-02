<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        // Puedes devolver una vista o algo simple para probar
        return view('produccion/index'); 
        // o temporalmente:
        // return "OK PRODUCCION";
    }

    public function filter()
    {
        // Si tu JS lo llama, puedes devolver JSON por ahora
        return $this->response->setJSON([
            'success' => true,
            'message' => 'OK filter'
        ]);
    }
}
