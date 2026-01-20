<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UsuarioModel;

class UsuariosController extends BaseController
{
    protected UsuarioModel $usuarioModel;

  public function index()
{
 return view('usuarios/index');
 
 }

}