<?php

namespace App\Controllers;

class SoporteController extends BaseController
{
    protected $db;

    protected array $ticketCols = [];
    protected array $msgCols = [];
    protected array $attCols = [];

    // mapeos reales (según columnas existentes)
    protected array $msgMap = [];
    protected array $attMap = [];

    public function __construct()
    {
        $this->db = \Config\Database::connect();

        $this->ticketCols = $this->safeFields('support_tickets');
        $this->msgCols    = $this->safeFields('support_messages');
        $this->attCols    = $this->safeFields('support_attachments');

        $this->msgMap = $this->buildMsgMap();
        $this->attMap = $this->buildAttMap();
    }

    private function safeFields(string $table): array
    {
        try {
            return $this->db->getFieldNames($table) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function firstCol(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) return $c;
        }
        return null;
    }

    private function normalizeRole(string $r): string
    {
        $r = strtolower(trim($r));
        if ($r === 'administrador' || $r === 'administrator') return 'admin';
        if ($r === 'production') return 'produccion';
        if ($r === '1') return 'admin';
        return $r;
    }

    private function resolveRole(): string
    {
        $userId = (int)(session('user_id') ?? 0);

        $sess = (string)(session('rol') ?? session('role') ?? session('perfil') ?? '');
        $sess = $this->normalizeRole($sess);
        if (in_array($sess, ['admin','produccion'], true)) return $sess;

        // intenta BD
        $userTables = ['usuarios','users','user','panel_users'];
        $roleCols   = ['rol','role','perfil','tipo','type'];
        $idCols     = ['id','user_id'];

        foreach ($userTables as $t) {
            $fields = $this->safeFields($t);
            if (empty($fields)) continue;

            $roleCol = $this->firstCol($fields, $roleCols);
            $idCol   = $this->firstCol($fields, $idCols);
            if (!$roleCol || !$idCol) continue;

            try {
                $row = $this->db->table($t)->select($roleCol)->where($idCol, $userId)->get()->getRowArray();
                if ($row && !empty($row[$roleCol])) {
                    $r = $this->normalizeRole((string)$row[$roleCol]);
                    session()->set('rol', $r);
                    session()->set('role', $r);
                    return $r;
                }
            } catch (\Throwable $e) {}
        }

        return $sess ?: 'produccion';
    }

    private function resolveUserName(int $uid): ?string
    {
        if ($uid <= 0) return null;
        $userTables = ['usuarios','users','user','panel_users'];
        $nameCols   = ['nombre','name','username','usuario'];
        $idCols     = ['id','user_id'];

        foreach ($userTables as $t) {
            $fields = $this->safeFields($t);
            if (empty($fields)) continue;

            $nameCol = $this->firstCol($fields, $nameCols);
            $idCol   = $this->firstCol($fields, $idCols);
            if (!$nameCol || !$idCol) continue;

            try {
                $row = $this->db->table($t)->select($nameCol)->where($idCol, $uid)->get()->getRowArray();
                if ($row && !empty($row[$nameCol])) return (string)$row[$nameCol];
            } catch (\Throwable $e) {}
        }
        return null;
    }
    private function safeMimeFromUpload($file): string
{
    // No usa finfo_file => no depende de /tmp
    $m = '';
    try { $m = (string)$file->getClientMimeType(); } catch (\Throwable $e) { $m = ''; }
    $m = strtolower(trim($m));

    if ($m !== '') return $m;

    // fallback por extensión
    $ext = strtolower((string)$file->getClientExtension());
    return match ($ext) {
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'jpeg', 'jpg' => 'image/jpeg',
        default => 'application/octet-stream',
    };
}


    private function jsonFail(int $code, string $msg, array $extra = [])
    {
        $role = $this->resolveRole();

        $payload = ['error' => $msg];
        // debug sólo admin
        if ($role === 'admin' && !empty($extra)) $payload['debug'] = $extra;

        return $this->response->setStatusCode($code)->setJSON($payload);
    }

    private function buildMsgMap(): array
    {
        // soporta columnas “message/mensaje/texto…”
        return [
            'ticket_id'   => $this->firstCol($this->msgCols, ['ticket_id','id_ticket','ticket']),
            'sender'      => $this->firstCol($this->msgCols, ['sender','from','autor','emisor','role']),
            'message'     => $this->firstCol($this->msgCols, ['message','mensaje','texto','contenido','body']),
            'created_at'  => $this->firstCol($this->msgCols, ['created_at','created','fecha','createdOn']),
            'user_id'     => $this->firstCol($this->msgCols, ['user_id','usuario_id','id_usuario']),
            'sender_id'   => $this->firstCol($this->msgCols, ['sender_id','from_id','autor_id']),
        ];
    }

    private function buildAttMap(): array
    {
        return [
            'ticket_id'  => $this->firstCol($this->attCols, ['ticket_id','id_ticket']),
            'message_id' => $this->firstCol($this->attCols, ['message_id','id_mensaje','support_message_id','mensaje_id']),
            'filename'   => $this->firstCol($this->attCols, ['filename','file_name','file','archivo']),
            'path'       => $this->firstCol($this->attCols, ['path','ruta','filepath']),
            'mime'       => $this->firstCol($this->attCols, ['mime','mime_type','tipo','type']),
            'created_at' => $this->firstCol($this->attCols, ['created_at','created','fecha']),
        ];
    }

    public function chat()
    {
        $role = $this->resolveRole();
        return view('soporte/chat', ['forcedRole' => $role]);
    }

    public function tickets()
    {
        try {
            if (empty($this->ticketCols)) return $this->jsonFail(500, 'Tabla support_tickets no accesible');

            $role   = $this->resolveRole();
            $userId = (int)(session('user_id') ?? 0);

            $selWant = ['id','ticket_code','order_id','status','user_id','assigned_to','assigned_at','created_at','updated_at','assigned_name','user_name'];
            $sel = array_values(array_intersect($selWant, $this->ticketCols));
            if (!in_array('id', $sel, true)) $sel[] = 'id';

            $b = $this->db->table('support_tickets')->select(implode(',', $sel));
            if ($role !== 'admin' && in_array('user_id', $this->ticketCols, true)) $b->where('user_id', $userId);

            if (in_array('updated_at', $this->ticketCols, true)) $b->orderBy('updated_at', 'DESC');
            else $b->orderBy('id', 'DESC');

            $rows = $b->get()->getResultArray();

            foreach ($rows as &$r) {
                $id = (int)($r['id'] ?? 0);
                $r['ticket_code'] = $r['ticket_code'] ?? ('TCK-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
                $r['status'] = $r['status'] ?? 'open';
                $r['order_id'] = $r['order_id'] ?? null;
                $r['assigned_to'] = $r['assigned_to'] ?? null;
                $r['assigned_at'] = $r['assigned_at'] ?? null;

                if (empty($r['assigned_name']) && !empty($r['assigned_to'])) {
                    $r['assigned_name'] = $this->resolveUserName((int)$r['assigned_to']);
                }
            }

            return $this->response->setJSON($rows);

        } catch (\Throwable $e) {
            log_message('error', 'tickets() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->jsonFail(500, 'Error interno cargando tickets', ['exception' => $e->getMessage()]);
        }
    }

    public function ticket($id)
    {
        try {
            $id     = (int)$id;
            $role   = $this->resolveRole();
            $userId = (int)(session('user_id') ?? 0);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->jsonFail(404, 'Ticket no encontrado');

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->jsonFail(403, 'Sin permisos');
            }

            // mensajes
            $messages = [];
            if (!empty($this->msgCols) && $this->msgMap['ticket_id']) {
                $idCol = 'id';
                $tkCol = $this->msgMap['ticket_id'];
                $sdCol = $this->msgMap['sender'];
                $msCol = $this->msgMap['message'];
                $crCol = $this->msgMap['created_at'];

                $sel = [$idCol, $tkCol];
                if ($sdCol) $sel[] = $sdCol;
                if ($msCol) $sel[] = $msCol;
                if ($crCol) $sel[] = $crCol;

                $rows = $this->db->table('support_messages')
                    ->select(implode(',', array_unique($sel)))
                    ->where($tkCol, $id)
                    ->orderBy('id', 'ASC')
                    ->get()->getResultArray();

                foreach ($rows as $r) {
                    $messages[] = [
                        'id' => (int)($r['id'] ?? 0),
                        'ticket_id' => $id,
                        'sender' => $sdCol ? ($r[$sdCol] ?? 'user') : 'user',
                        'message' => $msCol ? ($r[$msCol] ?? '') : '',
                        'created_at' => $crCol ? ($r[$crCol] ?? null) : null
                    ];
                }
            }

            // attachments agrupados por message_id
            $grouped = [];
            if (!empty($this->attCols) && $this->attMap['ticket_id']) {
                $tkCol = $this->attMap['ticket_id'];
                $midCol = $this->attMap['message_id'];
                $fnCol = $this->attMap['filename'];
                $ptCol = $this->attMap['path'];
                $mmCol = $this->attMap['mime'];
                $crCol = $this->attMap['created_at'];

                $sel = ['id', $tkCol];
                if ($midCol) $sel[] = $midCol;
                if ($fnCol) $sel[] = $fnCol;
                if ($ptCol) $sel[] = $ptCol;
                if ($mmCol) $sel[] = $mmCol;
                if ($crCol) $sel[] = $crCol;

                $atts = $this->db->table('support_attachments')
                    ->select(implode(',', array_unique($sel)))
                    ->where($tkCol, $id)
                    ->orderBy('id', 'ASC')
                    ->get()->getResultArray();

                foreach ($atts as $a) {
                    $mid = $midCol ? (int)($a[$midCol] ?? 0) : 0;
                    if (!isset($grouped[$mid])) $grouped[$mid] = [];

                    $filename = $fnCol ? ($a[$fnCol] ?? '') : '';
                    $path = $ptCol ? ($a[$ptCol] ?? '') : '';
                    $mime = $mmCol ? ($a[$mmCol] ?? 'image/jpeg') : 'image/jpeg';

                    $grouped[$mid][] = [
                        'id' => (int)($a['id'] ?? 0),
                        'message_id' => $mid,
                        'filename' => $filename ?: $path,
                        'mime' => $mime,
                        'created_at' => $crCol ? ($a[$crCol] ?? null) : null
                    ];
                }
            }

            $t['ticket_code'] = $t['ticket_code'] ?? ('TCK-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
            $t['status'] = $t['status'] ?? 'open';
            $t['order_id'] = $t['order_id'] ?? null;
            $t['assigned_to'] = $t['assigned_to'] ?? null;
            $t['assigned_at'] = $t['assigned_at'] ?? null;

            if (empty($t['assigned_name']) && !empty($t['assigned_to'])) {
                $t['assigned_name'] = $this->resolveUserName((int)$t['assigned_to']);
            }

            return $this->response->setJSON([
                'ticket' => $t,
                'messages' => $messages,
                'attachments' => $grouped
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ticket() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->jsonFail(500, 'Error interno abriendo ticket', ['exception' => $e->getMessage()]);
        }
    }

    public function create()
    {
        try {
            $role   = $this->resolveRole();
            $userId = (int)(session('user_id') ?? 0);

            if ($role === 'admin') return $this->jsonFail(403, 'Admin no crea tickets');

            // (tu create puede quedarse como estaba, aquí lo dejo simple)
            return $this->jsonFail(400, 'Crea tickets desde producción (este endpoint está OK pero no lo toco ahora)');
        } catch (\Throwable $e) {
            return $this->jsonFail(500, 'Error interno creando ticket', ['exception' => $e->getMessage()]);
        }
    }

    public function message($id)
    {
        try {
            $role   = $this->resolveRole();
            $userId = (int)(session('user_id') ?? 0);

            $id = (int)$id;
            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->jsonFail(404, 'Ticket no existe');

            if ($role !== 'admin' && isset($t['user_id']) && (int)$t['user_id'] !== $userId) {
                return $this->jsonFail(403, 'Sin permisos');
            }

            if (empty($this->msgCols) || !$this->msgMap['ticket_id'] || !$this->msgMap['message']) {
                return $this->jsonFail(500, 'Tabla support_messages no compatible', [
                    'msgCols' => $this->msgCols,
                    'msgMap' => $this->msgMap
                ]);
            }

            $text = trim((string)$this->request->getPost('message'));

            $imgs = $this->request->getFileMultiple('images');
            if (!is_array($imgs) || count($imgs) === 0) $imgs = $this->request->getFileMultiple('images[]');
            if (!is_array($imgs)) $imgs = [];

            if ($text === '' && count($imgs) === 0) return $this->jsonFail(400, 'Mensaje o imagen requerida');

            $now = date('Y-m-d H:i:s');
            $sender = ($role === 'admin') ? 'admin' : 'user';

            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $this->db->transStart();

            // INSERT MENSAJE (mapeado)
            $msgData = [];
            $msgData[$this->msgMap['ticket_id']] = $id;

            if ($this->msgMap['sender']) $msgData[$this->msgMap['sender']] = $sender;
            $msgData[$this->msgMap['message']] = $text;

            if ($this->msgMap['created_at']) $msgData[$this->msgMap['created_at']] = $now;
            if ($this->msgMap['user_id']) $msgData[$this->msgMap['user_id']] = $userId;
            if ($this->msgMap['sender_id']) $msgData[$this->msgMap['sender_id']] = $userId;

            $ok = $this->db->table('support_messages')->insert($msgData);
            if (!$ok) {
                $err = $this->db->error();
                $this->db->transRollback();
                return $this->jsonFail(500, 'Error interno enviando mensaje', [
                    'dbError' => $err,
                    'lastQuery' => (string)$this->db->getLastQuery(),
                    'msgDataKeys' => array_keys($msgData),
                    'msgMap' => $this->msgMap
                ]);
            }

            $msgId = (int)$this->db->insertID();

            // INSERT ADJUNTOS (si existe tabla)
            if (!empty($this->attCols) && $this->attMap['ticket_id'] && ($this->attMap['filename'] || $this->attMap['path'])) {

                foreach ($imgs as $img) {
                    if (!$img || !$img->isValid()) continue;

                    $newName = $img->getRandomName();
                    if (!$img->move($dir, $newName)) continue;

                    $attData = [];
                    $attData[$this->attMap['ticket_id']] = $id;

                    if ($this->attMap['message_id']) $attData[$this->attMap['message_id']] = $msgId;

                    if ($this->attMap['filename']) $attData[$this->attMap['filename']] = $newName;
                    if ($this->attMap['path']) $attData[$this->attMap['path']] = 'uploads/support/' . $newName;

                    if ($this->attMap['mime']) $attData[$this->attMap['mime']] = $this->safeMimeFromUpload($img);

                    if ($this->attMap['created_at']) $attData[$this->attMap['created_at']] = $now;

                    $okA = $this->db->table('support_attachments')->insert($attData);
                    if (!$okA) {
                        $err = $this->db->error();
                        $this->db->transRollback();
                        return $this->jsonFail(500, 'Error guardando adjunto', [
                            'dbError' => $err,
                            'lastQuery' => (string)$this->db->getLastQuery(),
                            'attDataKeys' => array_keys($attData),
                            'attMap' => $this->attMap,
                            'attCols' => $this->attCols
                        ]);
                    }
                }
            }

            // updated_at ticket
            if (in_array('updated_at', $this->ticketCols, true)) {
                $this->db->table('support_tickets')->where('id', $id)->update(['updated_at' => $now]);
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->jsonFail(500, 'No se pudo enviar el mensaje', [
                    'lastQuery' => (string)$this->db->getLastQuery()
                ]);
            }

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'message() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->jsonFail(500, 'Error interno enviando mensaje', [
                'exception' => $e->getMessage()
            ]);
        }
    }

    public function assign($id)
    {
        try {
            $role = $this->resolveRole();
            if ($role !== 'admin') return $this->jsonFail(403, 'Solo admin');

            $id        = (int)$id;
            $adminId   = (int)(session('user_id') ?? 0);
            $adminName = (string)(session('nombre') ?? '') ?: ($this->resolveUserName($adminId) ?? '');
            $now       = date('Y-m-d H:i:s');

            $upd = [];
            foreach (['assigned_to','assigned_at','updated_at','assigned_name'] as $c) {
                if (!in_array($c, $this->ticketCols, true)) continue;
                if ($c === 'assigned_to') $upd[$c] = $adminId;
                if ($c === 'assigned_at') $upd[$c] = $now;
                if ($c === 'updated_at') $upd[$c] = $now;
                if ($c === 'assigned_name') $upd[$c] = ($adminName !== '' ? $adminName : null);
            }

            $this->db->table('support_tickets')->where('id', $id)->update($upd);
            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            return $this->jsonFail(500, 'Error interno asignando caso', ['exception' => $e->getMessage()]);
        }
    }

    public function status($id)
    {
        try {
            $role = $this->resolveRole();
            if ($role !== 'admin') return $this->jsonFail(403, 'Solo admin');

            $id = (int)$id;
            $status = (string)$this->request->getPost('status');
            $allowed = ['open','in_progress','waiting_customer','resolved','closed'];
            if (!in_array($status, $allowed, true)) return $this->jsonFail(400, 'Estado inválido');

            $now = date('Y-m-d H:i:s');
            $upd = [];
            if (in_array('status', $this->ticketCols, true)) $upd['status'] = $status;
            if (in_array('updated_at', $this->ticketCols, true)) $upd['updated_at'] = $now;

            $this->db->table('support_tickets')->where('id', $id)->update($upd);
            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            return $this->jsonFail(500, 'Error interno actualizando estado', ['exception' => $e->getMessage()]);
        }
    }

    public function attachment($id)
    {
        try {
            $id = (int)$id;
            if (empty($this->attCols)) return $this->response->setStatusCode(404)->setBody('No encontrado');

            $a = $this->db->table('support_attachments')->where('id', $id)->get()->getRowArray();
            if (!$a) return $this->response->setStatusCode(404)->setBody('No encontrado');

            $filename = null;

            // soporta filename o path
            $fnCol = $this->attMap['filename'];
            $ptCol = $this->attMap['path'];

            if ($fnCol && !empty($a[$fnCol])) $filename = $a[$fnCol];
            if (!$filename && $ptCol && !empty($a[$ptCol])) $filename = basename((string)$a[$ptCol]);

            if (!$filename) return $this->response->setStatusCode(404)->setBody('Archivo no existe');

            $path = WRITEPATH . 'uploads/support/' . $filename;
            if (!is_file($path)) return $this->response->setStatusCode(404)->setBody('Archivo no existe');

            $mime = 'image/jpeg';
            $mmCol = $this->attMap['mime'];
            if ($mmCol && !empty($a[$mmCol])) $mime = (string)$a[$mmCol];

            return $this->response->setHeader('Content-Type', $mime)->setBody(file_get_contents($path));
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setBody('Error interno');
        }
    }
}
