<?php

namespace App\Controllers;

use App\Controllers\PlacasController;

class PlacasController extends BaseController
{
    /**
     * Vista principal de pedidos Produccion / preparados
     */
    public function placas()
    {
        // Si más adelante traes datos desde modelo:
        // $data['pedidos'] = [];

        return view('placas'); 
        // 👉 cambia 'placas' por el nombre real de tu vista si es otro
    }
}