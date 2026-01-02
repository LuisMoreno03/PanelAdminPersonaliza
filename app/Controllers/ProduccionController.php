<?php

namespace App\Controllers;

use App\Controllers\ProduccionController;

class ProduccionController extends BaseController
{
    /**
     * Vista principal de pedidos Produccion / preparados
     */
    public function Produccion()
    {
        // Si más adelante traes datos desde modelo:
        // $data['pedidos'] = [];

        return view('produccion'); 
        // 👉 cambia 'produccion' por el nombre real de tu vista si es otro
    }
}