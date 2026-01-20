<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class SoporteController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function chat()
    {
        return view('soporte/chat');
    }

    // LISTA DE TICKETS
    public function tickets()
    {
        try {
            $role   = (string)(session('rol') ?? '');
            $userId = (int)(session('user_id') ?? 0);

            $b = $this->db->table('support_tickets')
                ->select('id, ticket_code, order_id, status, user_id, user_name, assigned_to, assigned_name, assigned_at, created_at, updated_at');

            if ($role !== 'admin') {
                $b->where('user_id', $userId);
            }

            $b->orderBy('updated_at', 'DESC');
            $tickets = $b->get()->getResultArray();

            return $this->response->setJSON($tickets);

        } catch (\Throwable $e) {
            log_message('error', 'tickets() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno cargando tickets']);
        }
    }

    // ABRIR TICKET
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

            if ($role !== 'admin' && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $messages = $this->db->table('support_messages')
                ->where('ticket_id', $id)
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();

            $atts = $this->db->table('support_attachments')
                ->where('ticket_id', $id)
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();

            // agrupar attachments por message_id
            $grouped = [];
            foreach ($atts as $a) {
                $mid = (int)($a['message_id'] ?? 0);
                if (!isset($grouped[$mid])) $grouped[$mid] = [];
                $grouped[$mid][] = $a;
            }

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

    // CREAR TICKET (SOLO PRODUCCION)
    public function create()
    {
        try {
            $role     = (string)(session('rol') ?? '');
            $userId   = (int)(session('user_id') ?? 0);
            $userName = (string)(session('nombre') ?? '');

            if ($role === 'admin') {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Admin no crea tickets']);
            }

            $message = trim((string)$this->request->getPost('message'));
            $orderId = trim((string)$this->request->getPost('order_id'));

            $imgs = $this->request->getFileMultiple('images');
            if (!is_array($imgs)) $imgs = [];

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
            }

            $now = date('Y-m-d H:i:s');

            // asegurar carpeta
            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $this->db->transStart();

            // ticket temporal (code se setea luego con ID)
            $this->db->table('support_tickets')->insert([
                'ticket_code' => 'TCK-TMP',
                'user_id' => $userId,
                'user_name' => $userName ?: null,
                'order_id' => $orderId ?: null,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $ticketId = (int)$this->db->insertID();
            $ticketCode = 'TCK-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);

            $this->db->table('support_tickets')->where('id', $ticketId)->update([
                'ticket_code' => $ticketCode
            ]);

            // primer mensaje
            $this->db->table('support_messages')->insert([
                'ticket_id' => $ticketId,
                'sender' => 'user',
                'message' => $message,
                'created_at' => $now
            ]);
            $msgId = (int)$this->db->insertID();

            // adjuntos
            foreach ($imgs as $img) {
                if (!$img || !$img->isValid()) continue;

                $newName = $img->getRandomName();
                $img->move($dir, $newName);

                $this->db->table('support_attachments')->insert([
                    'ticket_id' => $ticketId,
                    'message_id' => $msgId,
                    'filename' => $newName,
                    'mime' => $img->getMimeType(),
                    'created_at' => $now
                ]);
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

    // ENVIAR MENSAJE A TICKET
    public function message($id)
    {
        try {
            $id       = (int)$id;
            $role     = (string)(session('rol') ?? '');
            $userId   = (int)(session('user_id') ?? 0);

            $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
            if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no existe']);

            if ($role !== 'admin' && (int)$t['user_id'] !== $userId) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
            }

            $message = trim((string)$this->request->getPost('message'));

            $imgs = $this->request->getFileMultiple('images');
            if (!is_array($imgs)) $imgs = [];

            if ($message === '' && count($imgs) === 0) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
            }

            $now = date('Y-m-d H:i:s');

            $dir = WRITEPATH . 'uploads/support';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $sender = ($role === 'admin') ? 'admin' : 'user';

            $this->db->transStart();

            $this->db->table('support_messages')->insert([
                'ticket_id' => $id,
                'sender' => $sender,
                'message' => $message,
                'created_at' => $now
            ]);
            $msgId = (int)$this->db->insertID();

            foreach ($imgs as $img) {
                if (!$img || !$img->isValid()) continue;

                $newName = $img->getRandomName();
                $img->move($dir, $newName);

                $this->db->table('support_attachments')->insert([
                    'ticket_id' => $id,
                    'message_id' => $msgId,
                    'filename' => $newName,
                    'mime' => $img->getMimeType(),
                    'created_at' => $now
                ]);
            }

            $this->db->table('support_tickets')->where('id', $id)->update([
                'updated_at' => $now
            ]);

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

    // ADMIN ACEPTA CASO
    public function assign($id)
    {
        try {
            $role = (string)(session('rol') ?? '');
            if ($role !== 'admin') {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);
            }

            $id        = (int)$id;
            $adminId   = (int)(session('user_id') ?? 0);
            $adminName = (string)(session('nombre') ?? '');
            $now       = date('Y-m-d H:i:s');

            $this->db->table('support_tickets')->where('id', $id)->update([
                'assigned_to' => $adminId,
                'assigned_name' => $adminName ?: null,
                'assigned_at' => $now,
                'updated_at' => $now
            ]);

            return $this->response->setJSON(['ok' => true]);

        } catch (\Throwable $e) {
            log_message('error', 'assign() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno asignando caso']);
        }
    }

    // ADMIN CAMBIA ESTADO
    public function status($id)
    {
        try {
            $role = (string)(session('rol') ?? '');
            if ($role !== 'admin') {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);
            }

            $id = (int)$id;
            $status = (string)$this->request->getPost('status');
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

    // SERVIR IMAGEN INLINE
    public function attachment($id)
    {
        try {
            $id = (int)$id;
            $a = $this->db->table('support_attachments')->where('id', $id)->get()->getRowArray();
            if (!$a) return $this->response->setStatusCode(404)->setBody('No encontrado');

            $path = WRITEPATH . 'uploads/support/' . $a['filename'];
            if (!is_file($path)) return $this->response->setStatusCode(404)->setBody('Archivo no existe');

            $mime = $a['mime'] ?: 'image/jpeg';
            return $this->response
                ->setHeader('Content-Type', $mime)
                ->setBody(file_get_contents($path));

        } catch (\Throwable $e) {
            log_message('error', 'attachment() ERROR: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setBody('Error interno');
        }
    }
}
