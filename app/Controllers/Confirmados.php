<?php

namespace App\Controllers;

class Confirmados extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/confirmados');
        }

            return view('confirmados');
    }
    

}

 