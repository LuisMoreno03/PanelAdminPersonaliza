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
 /**
     * GET /chat/users
     * Retorna usuarios + isOnline + lastMessage + unread
     */
    public function users()
    {
        $adminId = $this->meId();
        if ($adminId <= 0) return $this->json(['ok' => false], 401);

        $now = time();
        $onlineSeconds = 60;

        // Usuarios (no te incluyas si estás dentro de la misma tabla)
        $users = $this->db->table($this->usersTable . ' u')
            ->select("u.{$this->colUserId} as id, u.{$this->colName} as name, u.{$this->colEmail} as email, u.last_activity")
            ->where("u.{$this->colUserId} !=", $adminId)
            ->orderBy("u.{$this->colName}", 'ASC')
            ->get()->getResultArray();

        if (!$users) {
            return $this->json(['ok' => true, 'users' => []]);
        }

        $userIds = array_map(fn($r) => (int)$r['id'], $users);

        // Unread por user (mensajes del user hacia admin, no leídos)
        $unreadRows = $this->db->table('chat_messages')
            ->select('user_id, COUNT(*) c')
            ->where('admin_id', $adminId)
            ->whereIn('user_id', $userIds)
            ->where('sender_type', 'user')
            ->where('is_read', 0)
            ->groupBy('user_id')
            ->get()->getResultArray();

        $unreadMap = [];
        foreach ($unreadRows as $r) {
            $unreadMap[(int)$r['user_id']] = (int)$r['c'];
        }

        // Último mensaje por user (subquery simple: max(id) por user y luego lookup)
        $lastIdsRows = $this->db->table('chat_messages')
            ->select('user_id, MAX(id) as mid')
            ->where('admin_id', $adminId)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->get()->getResultArray();

        $midByUser = [];
        $mids = [];
        foreach ($lastIdsRows as $r) {
            $uid = (int)$r['user_id'];
            $mid = (int)$r['mid'];
            $midByUser[$uid] = $mid;
            $mids[] = $mid;
        }

        $lastMsgMap = [];
        if (!empty($mids)) {
            $rows = $this->db->table('chat_messages')
                ->select('id, user_id, message')
                ->whereIn('id', $mids)
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $lastMsgMap[(int)$r['user_id']] = (string)$r['message'];
            }
        }

        foreach ($users as &$u) {
            $last = $u['last_activity'] ? strtotime($u['last_activity']) : 0;
            $u['isOnline']   = ($last > 0 && ($now - $last) <= $onlineSeconds);
            $u['unread']     = $unreadMap[(int)$u['id']] ?? 0;
            $u['lastMessage']= $lastMsgMap[(int)$u['id']] ?? '';
            unset($u['last_activity']);
        }

        return $this->json(['ok' => true, 'users' => $users]);
    }

    /**
     * GET /chat/messages/{userId}
     */
    public function messages(int $userId)
    {
        $adminId = $this->meId();
        if ($adminId <= 0) return $this->json(['ok' => false], 401);

        if ($userId <= 0) return $this->json(['ok' => false, 'message' => 'User inválido'], 400);

        $msgs = $this->db->table('chat_messages')
            ->select('sender_type, message, created_at')
            ->where('admin_id', $adminId)
            ->where('user_id', $userId)
            ->orderBy('id', 'ASC')
            ->limit(400)
            ->get()->getResultArray();

        // Marcar como leídos los del user
        $this->db->table('chat_messages')
            ->where('admin_id', $adminId)
            ->where('user_id', $userId)
            ->where('sender_type', 'user')
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();

        // Formato de fecha legible (si prefieres ISO, lo cambiamos)
        foreach ($msgs as &$m) {
            $m['created_at'] = date('Y-m-d H:i', strtotime($m['created_at']));
        }

        return $this->json(['ok' => true, 'messages' => $msgs]);
    }

    /**
     * POST /chat/send { userId, message }
     */
    public function send()
    {
        $adminId = $this->meId();
        if ($adminId <= 0) return $this->json(['ok' => false], 401);

        $payload = $this->request->getJSON(true) ?? [];
        $userId  = (int)($payload['userId'] ?? 0);
        $msg     = trim((string)($payload['message'] ?? ''));

        if ($userId <= 0 || $msg === '') {
            return $this->json(['ok' => false, 'message' => 'Datos inválidos'], 400);
        }

        $this->db->table('chat_messages')->insert([
            'admin_id'     => $adminId,
            'user_id'      => $userId,
            'sender_type'  => 'admin',
            'message'      => $msg,
            'is_read'      => 0,
        ]);

        return $this->json([
            'ok'        => true,
            'createdAt' => date('Y-m-d H:i'),
        ]);
    }

    /**
     * POST /chat/read { userId }
     */
    public function read()
    {
        $adminId = $this->meId();
        if ($adminId <= 0) return $this->json(['ok' => false], 401);

        $payload = $this->request->getJSON(true) ?? [];
        $userId  = (int)($payload['userId'] ?? 0);

        if ($userId <= 0) return $this->json(['ok' => false], 400);

        $this->db->table('chat_messages')
            ->where('admin_id', $adminId)
            ->where('user_id', $userId)
            ->where('sender_type', 'user')
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();

        return $this->json(['ok' => true]);
    }

    /**
     * POST /chat/ping
     * Mantiene online al admin (y sirve si lo usas también del lado user)
     */
    public function ping()
    {
        $id = $this->meId();
        if ($id <= 0) return $this->json(['ok' => false], 401);

        $this->db->table($this->usersTable)
            ->where($this->colUserId, $id)
            ->set('last_activity', date('Y-m-d H:i:s'))
            ->update();

        return $this->json(['ok' => true]);
    }
}