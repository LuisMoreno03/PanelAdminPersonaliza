<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UsuarioModel;

class Usuarios extends BaseController
{
    protected UsuarioModel $usuarioModel;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
    }

    public function index()
    {
        return 'Usuarios::index OK';
        // luego volvemos a la vista
    }

    public function password(int $id) {}
    public function updatePassword(int $id) {}
}
