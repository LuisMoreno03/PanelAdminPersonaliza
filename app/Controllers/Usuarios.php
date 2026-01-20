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

    public function password(int $id)
    {
        $usuario = $this->usuariosModel
            ->select('id, nombre, email, rol, activo')
            ->find($id);

        if (!$usuario) {
            return redirect()->to('/usuarios')
                ->with('error', 'Usuario no encontrado.');
        }

        return view('usuarios/password', [
            'usuario' => $usuario,
        ]);
    }

    public function updatePassword(int $id)
    {
        $usuario = $this->usuariosModel->find($id);

        if (!$usuario) {
            return redirect()->to('/usuarios')
                ->with('error', 'Usuario no encontrado.');
        }

        $rules = [
            'password'         => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        $messages = [
            'password' => [
                'required'   => 'La contraseña es obligatoria.',
                'min_length' => 'La contraseña debe tener mínimo 8 caracteres.',
            ],
            'password_confirm' => [
                'required' => 'Debes confirmar la contraseña.',
                'matches'  => 'Las contraseñas no coinciden.',
            ],
        ];

        if (!$this->validate($rules, $messages)) {
            return redirect()
                ->to("/usuarios/{$id}/password")
                ->withInput()
                ->with('error', 'Revisa los errores del formulario.')
                ->with('validation', $this->validator->getErrors());
        }

        $password = (string) $this->request->getPost('password');

        $this->usuariosModel->update($id, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return redirect()->to('/usuarios')
            ->with('success', 'Contraseña actualizada correctamente.');
    }
}
