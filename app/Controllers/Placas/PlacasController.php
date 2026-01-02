<?php

namespace App\Controllers;



class PlacasController extends BaseController
{
    /**
     * Vista principal de pedidos Placas / Produccion
     */
    public function index()
    {
        // Si más adelante traes datos desde modelo:
        // $data['pedidos'] = [];

        return view('placas'); 
        // 👉 cambia 'placas' por el nombre real de tu vista si es otro
    }
}