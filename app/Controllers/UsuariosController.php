<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;
use App\Models\PedidosEstadoModel;

class UsuariosController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    // ✅ Estados permitidos (los del modal)
    private array $allowedEstados = [
        'Por preparar',
        'Faltan archivos',
        'Confirmado',
        'Diseñado',
        'Por producir',
        'Enviado',
        'Repetir',
    ];


    public function __construct()
    {
        // 1) Config/Shopify.php $estadoModel
        $this->loadShopifyFromConfig();

        // 2) archivo fuera del repo
        if (!$this->shop || !$this->token) {
            $this->loadShopifySecretsFromFile();
        }

        // 3) env() (fallback)
        if (!$this->shop || !$this->token) {
            $this->loadShopifyFromEnv();
        }

        // Normalizar dominio
        $this->shop = trim($this->shop);
        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->shop = rtrim($this->shop, '/');

        $this->token = trim($this->token);
        $this->apiVersion = trim($this->apiVersion ?: '2025-10');
    }

  

    // ============================================================
    // VISTA PRINCIPAL 
    // ============================================================

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('usuarios');
    }


    



    public function cambiarClave(): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'No autenticado',
            'csrf' => csrf_hash(),
        ]);
    }

    $userId = (int) (session()->get('user_id') ?? 0);
    if ($userId <= 0) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'Sesión inválida (sin user_id).',
            'csrf' => csrf_hash(),
        ]);
    }

    $data = $this->request->getJSON(true);
    if (!is_array($data)) $data = [];

    $currentPassword = trim((string)($data['currentPassword'] ?? ''));
    $newPassword     = trim((string)($data['newPassword'] ?? ''));

    if ($currentPassword === '' || $newPassword === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'Completa todos los campos.',
            'csrf' => csrf_hash(),
        ]);
    }

    if (strlen($newPassword) < 8) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'La nueva clave debe tener al menos 8 caracteres.',
            'csrf' => csrf_hash(),
        ]);
    }

    if ($currentPassword === $newPassword) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'La nueva clave no puede ser igual a la actual.',
            'csrf' => csrf_hash(),
        ]);
    }

    try {
        $db = \Config\Database::connect();

        // ✅ AJUSTA AQUÍ: tabla + campo de password
        $table = $db->table('usuarios');

        // Opción 1 (recomendada): password_hash
        $user = $table->select('id, password_hash')
                      ->where('id', $userId)
                      ->get()
                      ->getRowArray();

        // --- Si tu campo se llama "password" en vez de password_hash, usa esto:
        // $user = $table->select('id, password')
        //               ->where('id', $userId)
        //               ->get()
        //               ->getRowArray();

        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok' => false,
                'message' => 'Usuario no encontrado.',
                'csrf' => csrf_hash(),
            ]);
        }

        $hashActual = (string)($user['password_hash'] ?? '');
        // --- Si usas "password":
        // $hashActual = (string)($user['password'] ?? '');

        if ($hashActual === '' || !password_verify($currentPassword, $hashActual)) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'message' => 'La clave actual no es correcta.',
                'csrf' => csrf_hash(),
            ]);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // ✅ Guarda en BD (tiempo real)
        $ok = $table->where('id', $userId)->update([
            'password_hash' => $newHash,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        // --- Si tu campo es "password":
        // $ok = $table->where('id', $userId)->update([
        //     'password' => $newHash,
        //     'password_changed_at' => date('Y-m-d H:i:s'),
        // ]);

        if (!$ok) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => 'No se pudo guardar la nueva clave.',
                'csrf' => csrf_hash(),
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'message' => 'Clave actualizada correctamente.',
            'csrf' => csrf_hash(),
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'cambiarClave ERROR: ' . $e->getMessage());

        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'message' => 'Error interno actualizando clave.',
            'csrf' => csrf_hash(),
        ]);
    }
}

}
