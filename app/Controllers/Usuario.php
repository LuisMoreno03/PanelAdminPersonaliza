<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Usuario extends BaseController
{
    /**
     * GET /usuarios
     * Lista usuarios (simple)
     */
    public function index(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $db = \Config\Database::connect();

        $users = $db->table('usuarios')
            ->select('id, nombre, rol, email, created_at')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'users' => $users,
            'count' => count($users),
        ]);
    }

    /**
     * POST /usuarios/crear
     * Crea usuario + crea tags automáticos (D./P.) según rol
     */
    public function crear(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        // Permite JSON o form-data
        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getPost() ?: [];

        $nombre = trim((string)($payload['nombre'] ?? ''));
        $rol    = trim((string)($payload['rol'] ?? ''));
        $email  = trim((string)($payload['email'] ?? ''));
        $pass   = (string)($payload['password'] ?? '');

        if ($nombre === '' || $rol === '' || $email === '' || $pass === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan campos: nombre / rol / email / password',
            ]);
        }

        // roles permitidos (ajusta si quieres)
        $rolLower = mb_strtolower($rol);
        $rolesPermitidos = ['admin', 'produccion', 'producción', 'diseno', 'diseño', 'confirmacion', 'confirmación'];
        if (!in_array($rolLower, $rolesPermitidos, true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Rol inválido. Usa: admin, produccion, diseno, confirmacion',
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Email inválido',
            ]);
        }

        $db = \Config\Database::connect();

        // evitar duplicado por email
        $exists = $db->table('usuarios')
            ->select('id')
            ->where('email', $email)
            ->get()
            ->getRowArray();

        if ($exists) {
            return $this->response->setStatusCode(409)->setJSON([
                'success' => false,
                'message' => 'Ya existe un usuario con ese email',
            ]);
        }

        $db->transStart();

        // 1) Insert usuario
        $db->table('usuarios')->insert([
            'nombre' => $nombre,
            'rol' => $rol,
            'email' => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $userId = (int)$db->insertID();

        // 2) Generar tags según rol
        $tags = $this->buildTagsForUser($nombre, $rol);

        // 3) Guardar en user_tags
        foreach ($tags as $t) {
            // unique key evita duplicados, pero igual lo hacemos limpio
            $db->table('user_tags')->insert([
                'user_id' => $userId,
                'tag' => $t,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error creando usuario',
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'user_id' => $userId,
            'tags_creados' => $tags,
        ]);
    }

    /**
     * GET /usuarios/(:num)/tags
     * Devuelve las etiquetas del usuario
     */
    public function tags($id = null): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $userId = (int)$id;
        if ($userId <= 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'ID inválido',
            ]);
        }

        $db = \Config\Database::connect();

        $rows = $db->table('user_tags')
            ->select('tag')
            ->where('user_id', $userId)
            ->orderBy('tag', 'ASC')
            ->get()
            ->getResultArray();

        $tags = array_values(array_map(fn($r) => (string)$r['tag'], $rows));

        return $this->response->setJSON([
            'success' => true,
            'user_id' => $userId,
            'tags' => $tags,
        ]);
    }

    // =====================================================
    // Helpers
    // =====================================================

    /**
     * Construye tags según rol:
     * - producción: P.Nombre
     * - diseño: D.Nombre
     * - confirmación: D.Nombre
     * - admin: D.Nombre + P.Nombre
     */
    private function buildTagsForUser(string $nombre, string $rol): array
    {
        $nombre = trim($nombre);
        $rol = mb_strtolower(trim($rol));

        $nombreTag = preg_replace('/\s+/', ' ', $nombre);
        $nombreTag = str_replace([',', ';'], '', $nombreTag);

        $tags = [];

        // ✅ Producción: P + D
        if ($rol === 'produccion' || $rol === 'producción') {
            $tags[] = 'P.' . $nombreTag;
            $tags[] = 'D.' . $nombreTag;
        }

        // Diseño: solo D
        if ($rol === 'diseno' || $rol === 'diseño') {
            $tags[] = 'D.' . $nombreTag;
        }

        // Confirmación: D
        if ($rol === 'confirmacion' || $rol === 'confirmación') {
            $tags[] = 'D.' . $nombreTag;
        }

        // Admin: ambas
        if ($rol === 'admin') {
            $tags[] = 'P.' . $nombreTag;
            $tags[] = 'D.' . $nombreTag;
        }

        return array_values(array_unique(array_filter($tags)));
    }

}