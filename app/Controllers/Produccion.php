<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard');
        }
        
        return view("roduccion");
    }

    public function filter()
    {
        return $this->response->setJSON(['success' => true]);
    }
}   if (!session()->get('logged_in')) {
        return $this->response->setJSON([
            'success' => false,
            'message' => 'No autenticado'
        ])->setStatusCode(401);
    }

    