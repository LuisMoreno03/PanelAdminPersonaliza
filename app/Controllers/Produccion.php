<?php

namespace App\Controllers;

class Produccion extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard');
        }
        
        return view("produccion.php");
    }

    public function filter()
    {
        return $this->response->setJSON(['success' => true]);
    }
}   

    