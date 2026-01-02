<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        return "OK PRODUCCION";
    }

    public function filter()
    {
        return $this->response->setJSON(['success' => true]);
    }
}
