<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UsuarioModel;

class UsuariosController extends BaseController
{
    protected UsuarioModel $usuarioModel;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
    }

    public function index()
    {
        $usuarios = $this->usuarioModel
            ->select('id, nombre, email, rol, activo, created_at')
            ->orderBy('id', 'DESC')
            ->findAll();

        return view('usuarios/index', [
            'usuarios' => $usuarios,
        ]);
    }

    public function password(int $id)
    {
        $usuario = $this->usuarioModel
            ->select('id, nombre, email, rol, activo')
            ->find($id);

        if (!$usuario) {
            return redirect()->to('/usuarios')->with('error', 'Usuario no encontrado.');
        }

        return view('usuarios/password', [
            'usuario' => $usuario,
        ]);
    }

    public function updatePassword(int $id)
    {
        $usuario = $this->usuarioModel->find($id);

        if (!$usuario) {
            return redirect()->to('/usuarios')->with('error', 'Usuario no encontrado.');
        }

        $rules = [
            'password'         => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        $messages = [
            'password' => [
                'required'   => 'La contraseña es obligatoria.',
                'min_length' => 'Mínimo 8 caracteres.',
            ],
            'password_confirm' => [
                'required' => 'Confirma la contraseña.',
                'matches'  => 'Las contraseñas no coinciden.',
            ],
        ];

        if (!$this->validate($rules, $messages)) {
            return redirect()
                ->to("/usuarios/{$id}/password")
                ->withInput()
                ->with('error', 'Revisa el formulario.')
                ->with('validation', $this->validator->getErrors());
        }

        $password = (string) $this->request->getPost('password');

        $this->usuarioModel->update($id, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return redirect()->to('/usuarios')->with('success', 'Contraseña actualizada.');
    }
}
