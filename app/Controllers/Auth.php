<?php

namespace App\Controllers;

use App\Models\UserModel;

class Auth extends BaseController
{
    public function index()
    {
        // Si ya está logueado → mandar al dashboard
        if (session()->get('logged_in')) {
            return redirect()->to('index.php/dashboard');
        }

        return view('login');
    }

    public function login()
    {
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        $userModel = new UserModel();
        $user = $userModel->where('email', $email)->first();

        // Usuario no encontrado
        if (!$user) {
            return redirect()->back()->with('error', 'El usuario no existe.');
        }

        // VALIDAR contraseñas en texto plano
        if ($password !== $user['password']) {
            return redirect()->back()->with('error', 'Contraseña incorrecta.');
        }

        // Crear sesión
        session()->set([
            'user_id'   => $user['id'],
            'nombre'    => $user['nombre'],
            'email'     => $user['email'],
            'role'      => $user['role'],
            'logged_in' => true
        ]);

        return redirect()->to('index.php/dashboard');
    }

    
    public function dashboard()
    {
        if (!session()->get('user_id')) {
            return redirect()->to('/login');
        }

        return view('dashboard');
    }

    public function logout()
    {
        $session = session();

        // Solo si hay sesión iniciada/variables
        if ($session->get()) {
            $session->regenerate(); // ✅ primero
        }

        $session->destroy(); // ✅ después

        return redirect()->to('/');
    }




}
