<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class MiCuenta extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/login');
        }

        return view('mi_cuenta/index');
    }

    public function cambiarPassword(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getPost() ?: [];

        $current = (string)($payload['current_password'] ?? '');
        $new     = (string)($payload['new_password'] ?? '');

        if ($current === '' || $new === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan campos',
            ]);
        }

        if (mb_strlen($new) < 8) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'La nueva contraseña debe tener mínimo 8 caracteres',
            ]);
        }

        // Intentar detectar el id en sesión
        $userId = (int)(
            session()->get('user_id')
            ?? session()->get('id')
            ?? session()->get('usuario_id')
            ?? 0
        );

        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Sesión inválida: no se encontró user_id',
            ]);
        }

        $db = \Config\Database::connect();

        $user = $db->table('users')
            ->select('id, password')
            ->where('id', $userId)
            ->get()
            ->getRowArray();

        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Usuario no encontrado',
            ]);
        }

        if (!password_verify($current, (string)$user['password'])) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Contraseña actual incorrecta',
            ]);
        }

        if (password_verify($new, (string)$user['password'])) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'La nueva contraseña no puede ser igual a la anterior',
            ]);
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);

        $db->table('users')->where('id', $userId)->update([
            'password' => $hash,
        ]);

        return $this->response->setJSON(['success' => true]);
    }
}
