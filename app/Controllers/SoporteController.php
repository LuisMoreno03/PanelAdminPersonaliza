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

        // intenta tabla usuarios (si no existe, queda vacío)
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

    private function onlyExisting(array $data, array $cols): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($this->has($cols, $k)) $out[$k] = $v;
        }
        return $out;
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

        // intenta leer rol desde DB si existe tabla/columna
        try {
            if ($userId > 0 && !empty($this->userCols)) {
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

        // si sigue vacío, NO forzar admin; default produccion
        return $role !== '' ? $role : 'produccion';
    }

    private function resolveUserName(int $userId): string
    {
        $name = trim((string)(session('nombre') ?? ''));
        if ($name !== '') return $name;

        try {
            if ($userId > 0 && !empty($this->userCols)) {
                $col = $this->has($this->userCols, 'nombre') ? 'nombre' : ($this->has($this->userCols, 'name') ? 'name' : null);
                if ($col) {
                    $row = $this->db->table('usuarios')->select($col)->where('id', $userId)->get()->getRowArray();
                    if ($row && !empty($row[$col])) return (string)$row[$col];
                }
            }
        } catch (\Throwable $e) {}

        return '';
    }

    // ✅ MIME seguro (sin finfo_file /tmp) compatible hosting
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

        $out = [];
        foreach ($imgs as $f) if ($f) $out[] = $f;
        return $out;
    }

    // ✅ Debug opcional
    public function whoami()
    {
        $userId = (int)(session('user_id') ?? 0);
        $roleSession = (string)(session('rol') ?? '');
        $roleResolved = $this->resolveRole($userId);

        return $this->response->setJSON([
            'user_id' => $userId,
            'session_rol' => $roleSession,
            'resolved_rol' => $roleResolved,
            'nombre' => (string)(session('nombre') ?? ''),
        ]);
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
            $userId = (int)(session('user_id') ?? 0);
            if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'Sesión inválida']);

            if (empty($this->ticketCols)) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'Tabla support_tickets no existe']);
            }

            $role = $this->resolveRole($userId);

            $want = ['id','ticket_code','order_id','status','user_id','assigned_to','assigned_at','assigned_name','user_name','created_at','updated_at'];
            $sel  = array_values(array_intersect($want, $this->ticketCols));
            if (!in_array('id', $sel, true)) $sel[] = 'id';

            $b = $this->db->table('support_tickets')->select(implode(',', $sel));

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
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno cargando tickets',
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ✅ GET /soporte/ticket/{id}
    public function ticket($id)
    {
        try {
            $userId = (int)(session('user_id') ?? 0);
            if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'Sesión inválida']);

            $id   = (int)$id;
            $role = $this->resolveRole($userId);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no encontrado']);

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $messages = [];
            if (!empty($this->msgCols)) {
                $mwant = ['id','ticket_id','sender','message','created_at'];
                $msel  = array_values(array_intersect($mwant, $this->msgCols));
                if (!in_array('id', $msel, true)) $msel[] = 'id';

                $messages = $this->db->table('support_messages')
                    ->select(implode(',', $msel))
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
                $awant = ['id','ticket_id','message_id','filename','mime','created_at'];
                $asel  = array_values(array_intersect($awant, $this->attCols));
                if (!in_array('id', $asel, true)) $asel[] = 'id';

                $atts = $this->db->table('support_attachments')
                    ->select(implode(',', $asel))
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
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno abriendo ticket',
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ✅ POST /soporte/ticket (crear - producción)
    public function create()
    {
        try {
            $userId = (int)(session('user_id') ?? 0);
            if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'Sesión inválida']);

            if (empty($this->ticketCols) || empty($this->msgCols)) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'Faltan tablas support_tickets o support_messages']);
            }

            $role = $this->resolveRole($userId);
            if ($role === 'admin') {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Admin no crea tickets']);
            }

            $message = trim((string)$this->request->getPost('message'));
            $orderId = trim((string)$this->request->getPost('order_id'));
            $imgs = $this->getUploadsArray();

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
            }

            $now = date('Y-m-d H:i:s');
            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $userName = $this->resolveUserName($userId);

            $this->db->transStart();

            $ticketData = [
                'ticket_code' => 'TCK-TMP',
                'user_id' => $userId,
                'user_name' => ($userName !== '' ? $userName : null),
                'order_id' => ($orderId !== '' ? $orderId : null),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $ticketData = $this->onlyExisting($ticketData, $this->ticketCols);

            $this->db->table('support_tickets')->insert($ticketData);
            $ticketId = (int)$this->db->insertID();

            $ticketCode = 'TCK-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
            if ($this->has($this->ticketCols, 'ticket_code')) {
                $this->db->table('support_tickets')->where('id', $ticketId)->update(['ticket_code' => $ticketCode]);
            }

            $msgData = [
                'ticket_id' => $ticketId,
                'sender' => 'user',
                'message' => $message,
                'created_at' => $now
            ];
            $msgData = $this->onlyExisting($msgData, $this->msgCols);

            $this->db->table('support_messages')->insert($msgData);
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

                    $att = [
                        'ticket_id' => $ticketId,
                        'message_id' => $msgId,
                        'filename' => $newName,
                        'mime' => $this->safeMimeFromUpload($img),
                        'created_at' => $now
                    ];
                    $att = $this->onlyExisting($att, $this->attCols);

                    $this->db->table('support_attachments')->insert($att);
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'No se pudo crear el ticket']);
            }

            return $this->response->setJSON([
                'ticket_id' => $ticketId,
                'ticket_code' => $ticketCode
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'create() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno creando ticket',
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ✅ POST /soporte/ticket/{id}/message
    public function message($id)
    {
        try {
            $userId = (int)(session('user_id') ?? 0);
            if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'Sesión inválida']);

            if (empty($this->msgCols)) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'Falta tabla support_messages']);
            }

            $id   = (int)$id;
            $role = $this->resolveRole($userId);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no existe']);

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $message = trim((string)$this->request->getPost('message'));
            $imgs = $this->getUploadsArray();

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
            }

            $now = date('Y-m-d H:i:s');
            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $sender = ($role === 'admin') ? 'admin' : 'user';

            $this->db->transStart();

            $msgData = [
                'ticket_id' => $id,
                'sender' => $sender,
                'message' => $message,
                'created_at' => $now
            ];
            $msgData = $this->onlyExisting($msgData, $this->msgCols);

            $this->db->table('support_messages')->insert($msgData);
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

                    $att = [
                        'ticket_id' => $id,
                        'message_id' => $msgId,
                        'filename' => $newName,
                        'mime' => $this->safeMimeFromUpload($img),
                        'created_at' => $now
                    ];
                    $att = $this->onlyExisting($att, $this->attCols);

                    $this->db->table('support_attachments')->insert($att);
                }
            }

            if ($this->has($this->ticketCols, 'updated_at')) {
                $this->db->table('support_tickets')->where('id', $id)->update(['updated_at' => $now]);
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->response->setStatusCode(500)->setJSON(['error' => 'No se pudo enviar el mensaje']);
            }

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'message() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno enviando mensaje',
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ✅ POST /soporte/ticket/{id}/assign (admin)
    public function assign($id)
    {
        try {
            $adminId = (int)(session('user_id') ?? 0);
            if ($adminId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'Sesión inválida']);

            $role = $this->resolveRole($adminId);
            if ($role !== 'admin') return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);

            $id  = (int)$id;
            $now = date('Y-m-d H:i:s');

            $adminName = $this->resolveUserName($adminId);

            $upd = [
                'assigned_to' => $adminId,
                'assigned_at' => $now,
                'assigned_name' => ($adminName !== '' ? $adminName : null),
                'updated_at' => $now,
            ];
            $upd = $this->onlyExisting($upd, $this->ticketCols);

            $this->db->table('support_tickets')->where('id', $id)->update($upd);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'assign() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno asignando caso',
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ✅ POST /soporte/ticket/{id}/status (admin)
    public function status($id)
    {
        try {
            $adminId = (int)(session('user_id') ?? 0);
            if ($adminId <= 0) return $this->response->setStatusCode(401)->setJSON(['error' => 'Sesión inválida']);

            $role = $this->resolveRole($adminId);
            if ($role !== 'admin') return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);

            $id = (int)$id;
            $status = strtolower(trim((string)$this->request->getPost('status')));

            $allowed = ['open','in_progress','waiting_customer','resolved','closed'];
            if (!in_array($status, $allowed, true)) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Estado inválido']);
            }

            $now = date('Y-m-d H:i:s');

            $upd = ['status' => $status, 'updated_at' => $now];
            $upd = $this->onlyExisting($upd, $this->ticketCols);

            $this->db->table('support_tickets')->where('id', $id)->update($upd);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'status() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno actualizando estado',
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ✅ GET /soporte/attachment/{id}
    public function attachment($id)
    {
        try {
            $id = (int)$id;
            if (empty($this->attCols)) return $this->response->setStatusCode(404)->setBody('No encontrado');

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
