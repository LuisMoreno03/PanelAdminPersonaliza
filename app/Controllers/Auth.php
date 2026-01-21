<?php

namespace App\Controllers;

use App\Models\UserModel;

class Auth extends BaseController
{
    public function index()
    {
        // Si ya está logueado → mandar al dashboard
        if (session()->get('logged_in')) {
            return redirect()->to('/dashboard');
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

        // VALIDAR contraseñas ACTUALIZADAS
        $stored = (string)($user['password'] ?? '');

            // Detectar si ya es bcrypt
        $isBcrypt = str_starts_with($stored, '$2y$')
         || str_starts_with($stored, '$2a$')
         || str_starts_with($stored, '$2b$');

        $ok = $isBcrypt
    ? password_verify($password, $stored)          // hash
    : hash_equals($stored, $password);             // texto plano

    if (!$ok) {
    return redirect()->back()->with('error', 'Contraseña incorrecta.');
}

        // ✅ Si estaba en texto plano y entró, migrar automáticamente a hash
    if (!$isBcrypt) {
    try {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $userModel->update($user['id'], [
            'password' => $newHash,
            'password_changed_at' => date('Y-m-d H:i:s'), // si existe la columna
        ]);
    } catch (\Throwable $e) {
        // No bloquees el login si falla la migración
        log_message('error', 'Login migrate password hash: ' . $e->getMessage());
    }
}

        // Crear sesión
        session()->set([
            'user_id'   => $user['id'],
            'nombre'    => $user['nombre'],
            'email'     => $user['email'],
             'rol'     => strtolower(trim($user['rol'] ?? $user['role'] ?? 'produccion')), // <- CLAVE
            'logged_in' => true
        ]);

        return redirect()->to('index.php/dashboard');
    }

    
    public function dashboard()
    {
        if (!session()->get('user_id')) {
            return redirect()->to('/');
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
