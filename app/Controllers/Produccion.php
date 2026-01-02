<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        
        return "VISUALMENTE HABLANDO YA ESTA MEJOR LA PAGINA";
    }

    public function filter()
    {
        return $this->response->setJSON(['success' => true]);
    }
}

