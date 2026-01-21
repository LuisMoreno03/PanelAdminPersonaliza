<?php

namespace App\Controllers;

class SoporteController extends BaseController
{
    protected $db;

    protected array $ticketCols = [];
    protected array $msgCols = [];
    protected array $attCols = [];
    protected array $userCols = [];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->ticketCols = $this->safeFields('support_tickets');
        $this->msgCols    = $this->safeFields('support_messages');
        $this->attCols    = $this->safeFields('support_attachments');
        $this->userCols   = $this->safeFields('usuarios');
    }

    private function safeFields(string $table): array
    {
        try {
            return $this->db->getFieldNames($table) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function has(array $cols, string $c): bool
    {
        return in_array($c, $cols, true);
    }

    private function normRole(?string $r): string
    {
        $r = strtolower(trim((string)$r));
        if ($r === 'administrator' || $r === 'administrador') return 'admin';
        return $r;
    }

    private function resolveRole(int $userId): string
    {
        $role = $this->normRole((string)(session('rol') ?? ''));

        try {
            if (!empty($this->userCols)) {
                $col = $this->has($this->userCols, 'rol') ? 'rol' : ($this->has($this->userCols, 'role') ? 'role' : null);
                if ($col) {
                    $row = $this->db->table('usuarios')->select($col)->where('id', $userId)->get()->getRowArray();
                    if ($row && !empty($row[$col])) {
                        $role = $this->normRole($row[$col]);
                        session()->set('rol', $role);
                    }
                }
            }
        } catch (\Throwable $e) {}

        return $role ?: 'produccion';
    }

    private function resolveUserName(int $userId): string
    {
        $name = trim((string)(session('nombre') ?? ''));
        if ($name !== '') return $name;

        try {
            if (!empty($this->userCols)) {
                $col = $this->has($this->userCols, 'nombre') ? 'nombre' : ($this->has($this->userCols, 'name') ? 'name' : null);
                if ($col) {
                    $row = $this->db->table('usuarios')->select($col)->where('id', $userId)->get()->getRowArray();
                    if ($row && !empty($row[$col])) return (string)$row[$col];
                }
            }
        } catch (\Throwable $e) {}

        return '';
    }

    // ✅ MIME seguro (sin finfo_file /tmp) + compatible PHP 7.x (sin match)
    private function safeMimeFromUpload($file): string
    {
        $m = '';
        try { $m = (string)$file->getClientMimeType(); } catch (\Throwable $e) { $m = ''; }
        $m = strtolower(trim($m));
        if ($m !== '') return $m;

        $ext = strtolower((string)$file->getClientExtension());
        switch ($ext) {
            case 'png':  return 'image/png';
            case 'webp': return 'image/webp';
            case 'gif':  return 'image/gif';
            case 'jpeg':
            case 'jpg':  return 'image/jpeg';
            default:     return 'application/octet-stream';
        }
    }

    private function getUploadsArray(): array
    {
        $imgs = [];

        try {
            $tmp = $this->request->getFileMultiple('images');
            if (is_array($tmp) && count($tmp)) $imgs = $tmp;
        } catch (\Throwable $e) {}

        if (!count($imgs)) {
            try {
                $tmp = $this->request->getFileMultiple('images[]');
                if (is_array($tmp) && count($tmp)) $imgs = $tmp;
            } catch (\Throwable $e) {}
        }

        if (!count($imgs)) {
            $files = $this->request->getFiles();
            if (isset($files['images']) && is_array($files['images'])) $imgs = $files['images'];
            if (!count($imgs) && isset($files['images[]']) && is_array($files['images[]'])) $imgs = $files['images[]'];
        }

        // limpia nulos
        $out = [];
        foreach ($imgs as $f) if ($f) $out[] = $f;
        return $out;
    }

    // ✅ Vista
    public function chat()
    {
        $userId = (int)(session('user_id') ?? 0);
        $role = $this->resolveRole($userId);
        return view('soporte/chat', ['forcedRole' => $role]);
    }

    // ✅ GET /soporte/tickets
    public function tickets()
    {
        try {
            if (empty($this->ticketCols)) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'Tabla support_tickets no existe']);
            }

            $userId = (int)(session('user_id') ?? 0);
            $role   = $this->resolveRole($userId);

            $b = $this->db->table('support_tickets');

            if ($role !== 'admin' && $this->has($this->ticketCols, 'user_id')) {
                $b->where('user_id', $userId);
            }

            if ($this->has($this->ticketCols, 'updated_at')) $b->orderBy('updated_at', 'DESC');
            else $b->orderBy('id', 'DESC');

            $rows = $b->get()->getResultArray();

            foreach ($rows as &$r) {
                $id = (int)($r['id'] ?? 0);
                $r['ticket_code']   = $r['ticket_code'] ?? ('TCK-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
                $r['status']        = $r['status'] ?? 'open';
                $r['order_id']      = $r['order_id'] ?? null;
                $r['assigned_to']   = $r['assigned_to'] ?? null;
                $r['assigned_at']   = $r['assigned_at'] ?? null;
                $r['assigned_name'] = $r['assigned_name'] ?? null;
                $r['user_name']     = $r['user_name'] ?? null;
            }

            return $this->response->setJSON($rows);

        } catch (\Throwable $e) {
            log_message('error', 'tickets() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno cargando tickets']);
        }
    }

    // ✅ GET /soporte/ticket/{id}
    public function ticket($id)
    {
        try {
            $id     = (int)$id;
            $userId = (int)(session('user_id') ?? 0);
            $role   = $this->resolveRole($userId);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no encontrado']);

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $messages = [];
            if (!empty($this->msgCols)) {
                $messages = $this->db->table('support_messages')
                    ->where('ticket_id', $id)
                    ->orderBy('id', 'ASC')
                    ->get()->getResultArray();

                foreach ($messages as &$m) {
                    $m['sender']     = $m['sender'] ?? 'user';
                    $m['message']    = $m['message'] ?? '';
                    $m['created_at'] = $m['created_at'] ?? null;
                }
            }

            $grouped = [];
            if (!empty($this->attCols)) {
                $atts = $this->db->table('support_attachments')
                    ->where('ticket_id', $id)
                    ->orderBy('id', 'ASC')
                    ->get()->getResultArray();

                foreach ($atts as $a) {
                    $mid = (int)($a['message_id'] ?? 0);
                    if (!isset($grouped[$mid])) $grouped[$mid] = [];
                    $grouped[$mid][] = [
                        'id' => (int)($a['id'] ?? 0),
                        'message_id' => $mid,
                        'filename' => $a['filename'] ?? '',
                        'mime' => $a['mime'] ?? 'image/jpeg',
                        'created_at' => $a['created_at'] ?? null
                    ];
                }
            }

            $t['ticket_code']   = $t['ticket_code'] ?? ('TCK-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
            $t['status']        = $t['status'] ?? 'open';
            $t['order_id']      = $t['order_id'] ?? null;
            $t['assigned_to']   = $t['assigned_to'] ?? null;
            $t['assigned_at']   = $t['assigned_at'] ?? null;
            $t['assigned_name'] = $t['assigned_name'] ?? null;

            return $this->response->setJSON([
                'ticket' => $t,
                'messages' => $messages,
                'attachments' => $grouped
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ticket() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno abriendo ticket']);
        }
    }

    // ✅ POST /soporte/ticket (crear - produccion)
    public function create()
    {
        try {
            if (empty($this->ticketCols) || empty($this->msgCols)) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'Faltan tablas soporte']);
            }

            $userId = (int)(session('user_id') ?? 0);
            $role   = $this->resolveRole($userId);

            if ($role === 'admin') {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Admin no crea tickets']);
            }

            $message = trim((string)$this->request->getPost('message'));
            $orderId = trim((string)$this->request->getPost('order_id'));
            $imgs = $this->getUploadsArray();

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Escribe un mensaje o adjunta una imagen']);
            }

            $now = date('Y-m-d H:i:s');
            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $userName = $this->resolveUserName($userId);

            $this->db->transStart();

            $this->db->table('support_tickets')->insert([
                'ticket_code' => 'TCK-TMP',
                'user_id' => $userId,
                'user_name' => ($userName !== '' ? $userName : null),
                'order_id' => ($orderId !== '' ? $orderId : null),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ticketId = (int)$this->db->insertID();

            $ticketCode = 'TCK-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
            $this->db->table('support_tickets')->where('id', $ticketId)->update(['ticket_code' => $ticketCode]);

            $this->db->table('support_messages')->insert([
                'ticket_id' => $ticketId,
                'sender' => 'user',
                'message' => $message,
                'created_at' => $now
            ]);
            $msgId = (int)$this->db->insertID();

            if (!empty($this->attCols) && count($imgs)) {
                foreach ($imgs as $img) {
                    if (!$img || !$img->isValid()) continue;

                    $newName = $img->getRandomName();
                    try {
                        if (!$img->move($dir, $newName)) continue;
                    } catch (\Throwable $e) {
                        continue;
                    }

                    $this->db->table('support_attachments')->insert([
                        'ticket_id' => $ticketId,
                        'message_id' => $msgId,
                        'filename' => $newName,
                        'mime' => $this->safeMimeFromUpload($img),
                        'created_at' => $now
                    ]);
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'No se pudo crear el ticket']);
            }

            return $this->response->setJSON(['ticket_id' => $ticketId, 'ticket_code' => $ticketCode]);

        } catch (\Throwable $e) {
            log_message('error', 'create() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno creando ticket']);
        }
    }

    // ✅ POST /soporte/ticket/{id}/message
    public function message($id)
    {
        try {
            if (empty($this->msgCols)) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'Falta tabla support_messages']);
            }

            $id     = (int)$id;
            $userId = (int)(session('user_id') ?? 0);
            $role   = $this->resolveRole($userId);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no existe']);

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $message = trim((string)$this->request->getPost('message'));
            $imgs = $this->getUploadsArray();

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Escribe un mensaje o adjunta una imagen']);
            }

            $now = date('Y-m-d H:i:s');
            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $sender = ($role === 'admin') ? 'admin' : 'user';

            $this->db->transStart();

            $this->db->table('support_messages')->insert([
                'ticket_id' => $id,
                'sender' => $sender,
                'message' => $message,
                'created_at' => $now
            ]);
            $msgId = (int)$this->db->insertID();

            if (!empty($this->attCols) && count($imgs)) {
                foreach ($imgs as $img) {
                    if (!$img || !$img->isValid()) continue;

                    $newName = $img->getRandomName();
                    try {
                        if (!$img->move($dir, $newName)) continue;
                    } catch (\Throwable $e) {
                        continue;
                    }

                    $this->db->table('support_attachments')->insert([
                        'ticket_id' => $id,
                        'message_id' => $msgId,
                        'filename' => $newName,
                        'mime' => $this->safeMimeFromUpload($img),
                        'created_at' => $now
                    ]);
                }
            }

            $this->db->table('support_tickets')->where('id', $id)->update(['updated_at' => $now]);

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'No se pudo enviar el mensaje']);
            }

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'message() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno enviando mensaje']);
        }
    }

    // ✅ POST /soporte/ticket/{id}/assign
    public function assign($id)
    {
        try {
            $adminId = (int)(session('user_id') ?? 0);
            $role = $this->resolveRole($adminId);
            if ($role !== 'admin') return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);

            $id = (int)$id;
            $now = date('Y-m-d H:i:s');
            $adminName = $this->resolveUserName($adminId);

            $this->db->table('support_tickets')->where('id', $id)->update([
                'assigned_to' => $adminId,
                'assigned_at' => $now,
                'assigned_name' => ($adminName !== '' ? $adminName : null),
                'updated_at' => $now,
            ]);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'assign() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno asignando caso']);
        }
    }

    // ✅ POST /soporte/ticket/{id}/status
    public function status($id)
    {
        try {
            $adminId = (int)(session('user_id') ?? 0);
            $role = $this->resolveRole($adminId);
            if ($role !== 'admin') return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);

            $id = (int)$id;
            $status = strtolower(trim((string)$this->request->getPost('status')));
            $allowed = ['open','in_progress','waiting_customer','resolved','closed'];

            if (!in_array($status, $allowed, true)) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Estado inválido']);
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('support_tickets')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => $now
            ]);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'status() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno actualizando estado']);
        }
    }

    // ✅ GET /soporte/attachment/{id}
    public function attachment($id)
    {
        try {
            $id = (int)$id;

            $a = $this->db->table('support_attachments')->where('id', $id)->get()->getRowArray();
            if (!$a) return $this->response->setStatusCode(404)->setBody('No encontrado');

            $path = WRITEPATH . 'uploads/support/' . ($a['filename'] ?? '');
            if (!is_file($path)) return $this->response->setStatusCode(404)->setBody('Archivo no existe');

            $mime = $a['mime'] ?? 'image/jpeg';
            return $this->response->setHeader('Content-Type', $mime)->setBody(file_get_contents($path));

        } catch (\Throwable $e) {
            log_message('error', 'attachment() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setBody('Error interno');
        }
    }
}
