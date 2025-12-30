<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class ConfirmadosController extends BaseController
{
    /**
     * Vista principal de pedidos confirmados / preparados
     */
    public function confirmados()
    {
        // Si más adelante traes datos desde modelo:
        // $data['pedidos'] = [];

        return view('confirmados'); 
        // 👉 cambia 'confirmados' por el nombre real de tu vista si es otro
    }
}