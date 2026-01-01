<?php

namespace App\Controllers;



class PedidosController extends BaseController
{
    public function index()
    {
        return view('pedidos/index');
    }
}

public function index()
{
    return view('welcome_message');
}
