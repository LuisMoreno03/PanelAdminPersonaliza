<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UsuariosModel;

class Usuarios extends BaseController
{
    protected usuariosModel $usuariosModel;

    public function __construct()
    {
        $this->usuariosModel = new UsuariosModel();
    }

public function index()
{
    $usuarios = $this->usuariosModel
        ->select('id, nombre, email, rol, activo, created_at')
        ->orderBy('id', 'DESC')
        ->findAll();

    return view('usuarios/index', [
        'usuarios' => $usuarios,
    ]);
}

}