<?php

namespace App\Controllers;

class PorProducir extends BaseController
{
    public function index()
    {
        return view('por_producir/por_producir', [
            'etiquetasPredeterminadas' => [],
        ]);
    }
}
