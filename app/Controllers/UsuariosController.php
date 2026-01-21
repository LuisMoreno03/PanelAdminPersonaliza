<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UsuarioModel;

class UsuariosController extends BaseController


{


public function index()


{
    // Solo muestra el botón (sin tabla)
    return view('usuarios/index');
}

public function password()
{
    // Si tu login guarda el usuario en sesión con una clave distinta,
    // cambia 'user_id' por tu clave real.
    $userId = (int) session()->get('user_id');

    if (!$userId) {
        return redirect()->to('/dashboard')->with('error', 'No se pudo identificar tu usuario en sesión.');
    }

    $usuario = $this->usuarioModel
        ->select('id, nombre, email')
        ->find($userId);

    if (!$usuario) {
        return redirect()->to('/dashboard')->with('error', 'Usuario no encontrado.');
    }

    return view('usuarios/password', [
        'usuario' => $usuario
    ]);
}

public function updatePassword()
{
    $userId = (int) session()->get('user_id');

    if (!$userId) {
        return redirect()->to('/dashboard')->with('error', 'No se pudo identificar tu usuario en sesión.');
    }

    $usuario = $this->usuarioModel->find($userId);

    if (!$usuario) {
        return redirect()->to('/dashboard')->with('error', 'Usuario no encontrado.');
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
            ->to('/usuarios/password')
            ->withInput()
            ->with('validation', $this->validator->getErrors());
    }

    $password = (string) $this->request->getPost('password');

    $this->usuarioModel->update($userId, [
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    return redirect()->to('/usuarios')->with('success', 'Contraseña actualizada correctamente.');
}
}
return view('usuarios');