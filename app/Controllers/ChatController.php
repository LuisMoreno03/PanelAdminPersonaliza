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


    



public function users(): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $adminId = (int)(session()->get('user_id') ?? 0);
    if ($adminId <= 0) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $db = \Config\Database::connect();

    // Ajusta si tienes un campo role distinto
    // Aquí asumo que users tiene: id, nombre, email, role
    $rows = $db->query("
        SELECT 
            u.id,
            u.nombre AS name,
            u.email,

            -- último mensaje (texto)
            (
              SELECT m.message
              FROM chat_conversations c
              JOIN chat_messages m ON m.conversation_id = c.id
              WHERE c.user_id = u.id AND c.admin_id = ?
              ORDER BY m.id DESC
              LIMIT 1
            ) AS lastMessage,

            -- no leídos (solo mensajes del usuario)
            (
              SELECT COUNT(*)
              FROM chat_conversations c
              JOIN chat_messages m ON m.conversation_id = c.id
              WHERE c.user_id = u.id AND c.admin_id = ?
                AND m.sender_type = 'user'
                AND m.read_at IS NULL
            ) AS unread
        FROM users u
        WHERE (u.role IS NULL OR u.role <> 'admin')  -- ajusta esto a tu sistema
        ORDER BY unread DESC, u.id DESC
        LIMIT 300
    ", [$adminId, $adminId])->getResultArray();

    

    // isOnline lo setea Socket.IO en el frontend (real-time), aquí solo default
    foreach ($rows as &$r) {
        $r['isOnline'] = false;
        $r['unread'] = (int)($r['unread'] ?? 0);
        $r['lastMessage'] = (string)($r['lastMessage'] ?? '');
    }

    return $this->response->setJSON([
        'ok' => true,
        'users' => $rows,
        'csrf' => csrf_hash(),
    ]);
}

public function messages($userId): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $adminId = (int)(session()->get('user_id') ?? 0);
    $userId  = (int)$userId;

    if ($adminId <= 0 || $userId <= 0) {
        return $this->response->setStatusCode(400)->setJSON(['ok'=>false, 'message'=>'IDs inválidos', 'csrf'=>csrf_hash()]);
    }

    $db = \Config\Database::connect();

    // Buscar o crear conversación
    $conv = $db->query("
        SELECT id FROM chat_conversations
        WHERE user_id = ? AND admin_id = ?
        LIMIT 1
    ", [$userId, $adminId])->getRowArray();

    if (!$conv) {
        $db->query("
            INSERT INTO chat_conversations (user_id, admin_id, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ", [$userId, $adminId]);

        $convId = (int)$db->insertID();
    } else {
        $convId = (int)$conv['id'];
    }

    $msgs = $db->query("
        SELECT sender_type, message, DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS created_at
        FROM chat_messages
        WHERE conversation_id = ?
        ORDER BY id ASC
        LIMIT 500
    ", [$convId])->getResultArray();

    return $this->response->setJSON([
        'ok' => true,
        'messages' => $msgs,
        'csrf' => csrf_hash(),
    ]);
}


public function send(): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $adminId = (int)(session()->get('user_id') ?? 0);
    if ($adminId <= 0) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $data = $this->request->getJSON(true);
    $userId = (int)($data['userId'] ?? 0);
    $message = trim((string)($data['message'] ?? ''));

    if ($userId <= 0 || $message === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'Datos incompletos.',
            'csrf' => csrf_hash()
        ]);
    }

    $db = \Config\Database::connect();

    // Conversación
    $conv = $db->query("
        SELECT id FROM chat_conversations
        WHERE user_id = ? AND admin_id = ?
        LIMIT 1
    ", [$userId, $adminId])->getRowArray();

    if (!$conv) {
        $db->query("
            INSERT INTO chat_conversations (user_id, admin_id, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ", [$userId, $adminId]);

        $convId = (int)$db->insertID();
    } else {
        $convId = (int)$conv['id'];
    }

    $db->query("
        INSERT INTO chat_messages (conversation_id, sender_type, sender_id, message, created_at)
        VALUES (?, 'admin', ?, ?, NOW())
    ", [$convId, $adminId, $message]);

    $db->query("UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?", [$convId]);

    return $this->response->setJSON([
        'ok' => true,
        'createdAt' => date('d/m/Y H:i'),
        'csrf' => csrf_hash(),
    ]);
}


public function Read(): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $adminId = (int)(session()->get('user_id') ?? 0);
    if ($adminId <= 0) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $data = $this->request->getJSON(true);
    $userId = (int)($data['userId'] ?? 0);

    if ($userId <= 0) {
        return $this->response->setStatusCode(400)->setJSON(['ok'=>false, 'csrf'=>csrf_hash()]);
    }

    $db = \Config\Database::connect();

    $conv = $db->query("
        SELECT id FROM chat_conversations
        WHERE user_id = ? AND admin_id = ?
        LIMIT 1
    ", [$userId, $adminId])->getRowArray();

    if (!$conv) {
        return $this->response->setJSON(['ok'=>true, 'csrf'=>csrf_hash()]);
    }

    $db->query("
        UPDATE chat_messages
        SET read_at = NOW()
        WHERE conversation_id = ?
          AND sender_type = 'user'
          AND read_at IS NULL
    ", [(int)$conv['id']]);

    return $this->response->setJSON(['ok'=>true, 'csrf'=>csrf_hash()]);
}

}