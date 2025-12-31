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
        // De momento prueba con dummy:
        return $this->response->setJSON([
            'success' => true,
            'orders' => [],
            'next_page_info' => null
        ]);
    }
}
