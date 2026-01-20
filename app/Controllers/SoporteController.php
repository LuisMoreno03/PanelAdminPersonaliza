<?php

namespace App\Controllers;

class SoporteController extends BaseController
{
    protected $db;

    // cache de columnas (para no romper si tu tabla no tiene ciertas columnas)
    protected array $ticketCols = [];
    protected array $msgCols = [];
    protected array $attCols = [];

    public function __construct()
    {
        $this->db = \Config\Database::connect();

        $this->ticketCols = $this->safeFields('support_tickets');
        $this->msgCols    = $this->safeFields('support_messages');
        $this->attCols    = $this->safeFields('support_attachments');
    }

    private function safeFields(string $table): array
    {
        try {
            return $this->db->getFieldNames($table) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function hasTicketCol(string $c): bool { return in_array($c, $this->ticketCols, true); }
    private function hasMsgCol(string $c): bool { return in_array($c, $this->msgCols, true); }
    private function hasAttCol(string $c): bool { return in_array($c, $this->attCols, true); }

    private function onlyExistingTicketCols(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($this->hasTicketCol($k)) $out[$k] = $v;
        }
        return $out;
    }

    // ✅ Vista
    public function chat()
    {
        return view('soporte/chat');
    }

    // ✅ GET /soporte/tickets
    public function tickets()
    {
        try {
            if (empty($this->ticketCols)) {
                return $this->response->setStatusCode(500)->setJSON([
                    'error' => 'Tabla support_tickets no existe o no es accesible'
                ]);
            }

            $role   = (string)(session('rol') ?? '');
            $userId = (int)(session('user_id') ?? 0);

            // Selección segura (solo columnas existentes)
            $want = ['id','ticket_code','order_id','status','user_id','assigned_to','assigned_at','created_at','updated_at','assigned_name','user_name'];
            $sel = array_values(array_intersect($want, $this->ticketCols));
            if (!in_array('id', $sel, true)) $sel[] = 'id';

            $b = $this->db->table('support_tickets')->select(implode(',', $sel));

            if ($role !== 'admin') {
                if ($this->hasTicketCol('user_id')) {
                    $b->where('user_id', $userId);
                }
            }

            if ($this->hasTicketCol('updated_at')) {
                $b->orderBy('updated_at', 'DESC');
            } else {
                $b->orderBy('id', 'DESC');
            }

            $rows = $b->get()->getResultArray();

            // asegurar keys que el frontend usa
            foreach ($rows as &$r) {
                $r['ticket_code']   = $r['ticket_code']   ?? ('TCK-' . str_pad((string)($r['id'] ?? 0), 6, '0', STR_PAD_LEFT));
                $r['order_id']      = $r['order_id']      ?? null;
                $r['status']        = $r['status']        ?? 'open';
                $r['assigned_to']   = $r['assigned_to']   ?? null;
                $r['assigned_at']   = $r['assigned_at']   ?? null;
                $r['assigned_name'] = $r['assigned_name'] ?? null;
                $r['user_name']     = $r['user_name']     ?? null;
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
            $role   = (string)(session('rol') ?? '');
            $userId = (int)(session('user_id') ?? 0);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no encontrado']);
            }

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            // mensajes
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

            // attachments
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

            // normalizar ticket para el frontend
            $t['ticket_code']   = $t['ticket_code']   ?? ('TCK-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
            $t['order_id']      = $t['order_id']      ?? null;
            $t['status']        = $t['status']        ?? 'open';
            $t['assigned_to']   = $t['assigned_to']   ?? null;
            $t['assigned_at']   = $t['assigned_at']   ?? null;
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

    // ✅ POST /soporte/ticket  (crear)
    public function create()
    {
        try {
            if (empty($this->ticketCols) || empty($this->msgCols)) {
                return $this->response->setStatusCode(500)->setJSON([
                    'error' => 'Faltan tablas support_tickets o support_messages'
                ]);
            }

            $role     = (string)(session('rol') ?? '');
            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? '');

            if ($role === 'admin') {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Admin no crea tickets']);
            }

            $message = trim((string)$this->request->getPost('message'));
            $orderId = trim((string)$this->request->getPost('order_id'));

            // soporta images y images[]
            $imgs = $this->request->getFileMultiple('images');
            if (!is_array($imgs) || count($imgs) === 0) {
                $imgs = $this->request->getFileMultiple('images[]');
            }
            if (!is_array($imgs)) $imgs = [];

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
            }

            $now = date('Y-m-d H:i:s');

            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $this->db->transStart();

            $ticketData = [
                'ticket_code' => 'TCK-TMP',
                'user_id' => $userId,
                'order_id' => ($orderId !== '' ? $orderId : null),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
                'user_name' => ($userName !== '' ? $userName : null),
            ];
            $ticketData = $this->onlyExistingTicketCols($ticketData);

            $this->db->table('support_tickets')->insert($ticketData);
            $ticketId = (int)$this->db->insertID();

            // generar código tipo TCK-000002
            $ticketCode = 'TCK-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
            if ($this->hasTicketCol('ticket_code')) {
                $this->db->table('support_tickets')->where('id', $ticketId)->update(['ticket_code' => $ticketCode]);
            }

            // mensaje inicial
            $this->db->table('support_messages')->insert([
                'ticket_id' => $ticketId,
                'sender' => 'user',
                'message' => $message,
                'created_at' => $now
            ]);
            $msgId = (int)$this->db->insertID();

            // adjuntos (si existe tabla)
            if (!empty($this->attCols)) {
                foreach ($imgs as $img) {
                    if (!$img || !$img->isValid()) continue;

                    $newName = $img->getRandomName();
                    $img->move($dir, $newName);

                    $att = [
                        'ticket_id' => $ticketId,
                        'message_id' => $msgId,
                        'filename' => $newName,
                        'mime' => $img->getMimeType(),
                        'created_at' => $now
                    ];

                    // filtrar columnas existentes
                    $out = [];
                    foreach ($att as $k => $v) if ($this->hasAttCol($k)) $out[$k] = $v;

                    $this->db->table('support_attachments')->insert($out);
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
            $role   = (string)(session('rol') ?? '');
            $userId = (int)(session('user_id') ?? 0);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no existe']);

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $message = trim((string)$this->request->getPost('message'));

            $imgs = $this->request->getFileMultiple('images');
            if (!is_array($imgs) || count($imgs) === 0) {
                $imgs = $this->request->getFileMultiple('images[]');
            }
            if (!is_array($imgs)) $imgs = [];

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
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

            if (!empty($this->attCols)) {
                foreach ($imgs as $img) {
                    if (!$img || !$img->isValid()) continue;

                    $newName = $img->getRandomName();
                    $img->move($dir, $newName);

                    $att = [
                        'ticket_id' => $id,
                        'message_id' => $msgId,
                        'filename' => $newName,
                        'mime' => $img->getMimeType(),
                        'created_at' => $now
                    ];

                    $out = [];
                    foreach ($att as $k => $v) if ($this->hasAttCol($k)) $out[$k] = $v;

                    $this->db->table('support_attachments')->insert($out);
                }
            }

            if ($this->hasTicketCol('updated_at')) {
                $this->db->table('support_tickets')->where('id', $id)->update(['updated_at' => $now]);
            }

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

    // ✅ POST /soporte/ticket/{id}/assign  (admin)
    public function assign($id)
    {
        try {
            $role = (string)(session('rol') ?? '');
            if ($role !== 'admin') return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);

            $id        = (int)$id;
            $adminId   = (int)(session('user_id') ?? 0);
            $adminName = (string)(session('nombre') ?? '');
            $now       = date('Y-m-d H:i:s');

            $upd = [
                'assigned_to' => $adminId,
                'assigned_at' => $now,
                'updated_at'  => $now,
                'assigned_name' => ($adminName !== '' ? $adminName : null),
            ];
            $upd = $this->onlyExistingTicketCols($upd);

            $this->db->table('support_tickets')->where('id', $id)->update($upd);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'assign() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno asignando caso']);
        }
    }

    // ✅ POST /soporte/ticket/{id}/status  (admin)
    public function status($id)
    {
        try {
            $role = (string)(session('rol') ?? '');
            if ($role !== 'admin') return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);

            $id = (int)$id;
            $status = (string)$this->request->getPost('status');
            $allowed = ['open','in_progress','waiting_customer','resolved','closed'];

            if (!in_array($status, $allowed, true)) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Estado inválido']);
            }

            $now = date('Y-m-d H:i:s');

            $upd = ['status' => $status, 'updated_at' => $now];
            $upd = $this->onlyExistingTicketCols($upd);

            $this->db->table('support_tickets')->where('id', $id)->update($upd);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'status() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno actualizando estado']);
        }
    }

    // ✅ GET /soporte/attachment/{id} (inline para <img>)
    public function attachment($id)
    {
        try {
            $id = (int)$id;

            if (empty($this->attCols)) {
                return $this->response->setStatusCode(404)->setBody('No encontrado');
            }

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
