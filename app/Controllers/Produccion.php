<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        
        return "PRODUCCION EN PROCESO...";
    }

    public function filter()
    {
        return $this->response->setJSON(['success' => true]);
    }
}

