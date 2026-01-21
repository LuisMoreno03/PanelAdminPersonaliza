<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;
use App\Models\PedidosEstadoModel;

class ChatController extends Controller
{
   
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

    // =====================================================
    // CONFIG LOADERS  dashboard
    // =====================================================

    private function loadShopifyFromConfig(): void
    {
        try {
            $cfg = config('Shopify');
            if (!$cfg) return;

            $this->shop       = (string) ($cfg->shop ?? $cfg->SHOP ?? $this->shop);
            $this->token      = (string) ($cfg->token ?? $cfg->TOKEN ?? $this->token);
            $this->apiVersion = (string) ($cfg->apiVersion ?? $cfg->version ?? $cfg->API_VERSION ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'DRepetirController loadShopifyFromConfig ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifyFromEnv(): void
    {
        try {
            $shop  = (string) env('SHOPIFY_STORE_DOMAIN');
            $token = (string) env('SHOPIFY_ADMIN_TOKEN');
            $ver   = (string) (env('SHOPIFY_API_VERSION') ?: '2025-10');

            if (!empty(trim($shop)))  $this->shop = $shop;
            if (!empty(trim($token))) $this->token = $token;
            if (!empty(trim($ver)))   $this->apiVersion = $ver;
        } catch (\Throwable $e) {
            log_message('error', 'ChatController loadShopifyFromEnv ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifySecretsFromFile(): void
    {
        try {
            $path = '/home/u756064303/.secrets/shopify.php';
            if (!is_file($path)) return;

            $cfg = require $path;
            if (!is_array($cfg)) return;

            $this->shop       = (string) ($cfg['shop'] ?? $this->shop);
            $this->token      = (string) ($cfg['token'] ?? $this->token);
            $this->apiVersion = (string) ($cfg['apiVersion'] ?? $cfg['version'] ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'ChatController loadShopifySecretsFromFile ERROR: ' . $e->getMessage());
        }
    }

 


   

    // ============================================================
    // VISTA PRINCIPAL 
    // ============================================================

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('chat');
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
    $table = $db->table('users');

    $user = $table->select('id, password')
                  ->where('id', $userId)
                  ->get()
                  ->getRowArray();

    if (!$user) {
        return $this->response->setStatusCode(404)->setJSON([
            'ok' => false,
            'message' => 'Usuario no encontrado.',
            'csrf' => csrf_hash(),
        ]);
    }

    $stored = (string)($user['password'] ?? '');

    // Detectar bcrypt (hash)
    $isBcrypt = str_starts_with($stored, '$2y$')
             || str_starts_with($stored, '$2a$')
             || str_starts_with($stored, '$2b$');

    // Validar clave actual (hash o texto plano)
    $okCurrent = $isBcrypt
        ? password_verify($currentPassword, $stored)
        : hash_equals($stored, $currentPassword);

    if (!$okCurrent) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'La clave actual no es correcta.',
            'csrf' => csrf_hash(),
        ]);
    }

    // Guardar SIEMPRE como hash seguro
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $ok = $table->where('id', $userId)->update([
        'password' => $newHash,
        'password_changed_at' => date('Y-m-d H:i:s'),
    ]);

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
