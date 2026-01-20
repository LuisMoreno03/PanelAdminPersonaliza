<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class SupportController extends Controller
{
    /**
     * AJUSTA ESTO SI TU TABLA DE USUARIOS SE LLAMA DIFERENTE
     * (solo se usa para mostrar nombres en el UI).
     */
    private string $usersTable = 'usuarios';     // <-- cambia si aplica
    private string $usersIdCol = 'id';
    private string $usersNameCol = 'nombre';

    // =========================
    // Helpers de sesión/rol
    // =========================
    private function userId(): int
    {
        // AJUSTA si tu sesión usa otra llave
        return (int) (session('user_id') ?? 0);
    }

    private function role(): string
    {
        // AJUSTA si tu sesión usa otra llave
        return (string) (session('rol') ?? '');
    }

    private function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    private function requireLogin()
    {
        if ($this->userId() <= 0) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Not authenticated']);
        }
        return null;
    }

    private function canSeeTicketRow(array $ticket): bool
    {
        return $this->isAdmin() || ((int)$ticket['user_id'] === $this->userId());
    }

    // =========================
    // Vista
    // =========================
    public function chat()
    {
        if ($resp = $this->requireLogin()) return $resp;
        return view('soporte/chat');
    }

    // =========================
    // Listar tickets (admin todos / produccion solo propios)
    // =========================
    public function tickets()
    {
        if ($resp = $this->requireLogin()) return $resp;

        $db = db_connect();
        $builder = $db->table('support_tickets t')
            ->select('t.id, t.ticket_code, t.order_id, t.status, t.created_at, t.updated_at, t.assigned_to, t.assigned_at')
            ->orderBy('t.updated_at', 'DESC');

        if (!$this->isAdmin()) {
            $builder->where('t.user_id', $this->userId());
        }

        // Traer nombres si existe tabla usuarios
        // Si tu tabla no existe o no quieres, puedes comentar estas 2 líneas.
        $builder->select("a.{$this->usersNameCol} AS assigned_name");
        $builder->join("{$this->usersTable} a", "a.{$this->usersIdCol} = t.assigned_to", 'left');

        $rows = $builder->get()->getResultArray();
        return $this->response->setJSON($rows);
    }

    // =========================
    // Ver ticket + mensajes + adjuntos
    // =========================
    public function ticket($id)
    {
        if ($resp = $this->requireLogin()) return $resp;

        $db = db_connect();

        $ticketBuilder = $db->table('support_tickets t')
            ->select('t.*')
            ->where('t.id', (int)$id);

        // nombres (opcional)
        $ticketBuilder->select("u.{$this->usersNameCol} AS created_name");
        $ticketBuilder->join("{$this->usersTable} u", "u.{$this->usersIdCol} = t.user_id", 'left');
        $ticketBuilder->select("a.{$this->usersNameCol} AS assigned_name");
        $ticketBuilder->join("{$this->usersTable} a", "a.{$this->usersIdCol} = t.assigned_to", 'left');

        $ticket = $ticketBuilder->get()->getRowArray();
        if (!$ticket) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        if (!$this->canSeeTicketRow($ticket)) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden']);
        }

        $messages = $db->table('support_messages')
            ->where('ticket_id', (int)$id)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        $msgIds = array_column($messages, 'id');
        $attachmentsByMsg = [];

        if (!empty($msgIds)) {
            $atts = $db->table('support_attachments')
                ->whereIn('message_id', $msgIds)
                ->get()->getResultArray();

            foreach ($atts as $a) {
                $attachmentsByMsg[$a['message_id']][] = $a;
            }
        }

        return $this->response->setJSON([
            'ticket' => $ticket,
            'messages' => $messages,
            'attachments' => $attachmentsByMsg
        ]);
    }

    // =========================
    // Crear ticket (SOLO produccion)
    // =========================
    public function createTicket()
    {
        if ($resp = $this->requireLogin()) return $resp;

        if ($this->isAdmin()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Admins no crean tickets']);
        }

        $db = db_connect();
        $message = trim((string)$this->request->getPost('message'));
        $orderId = trim((string)$this->request->getPost('order_id'));

        // permitir ticket con solo imagen o solo texto
        $hasFiles = !empty($this->request->getFiles()['images'] ?? null);
        if ($message === '' && !$hasFiles) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Escribe un mensaje o adjunta una imagen']);
        }

        $now = date('Y-m-d H:i:s');

        $db->transStart();

        $db->table('support_tickets')->insert([
            'ticket_code' => 'TMP',
            'user_id' => $this->userId(),
            'order_id' => ($orderId !== '') ? $orderId : null,
            'status' => 'open',
            'assigned_to' => null,
            'assigned_at' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);
        $ticketId = $db->insertID();

        $code = 'TCK-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
        $db->table('support_tickets')->where('id', $ticketId)->update(['ticket_code' => $code]);

        $db->table('support_messages')->insert([
            'ticket_id' => $ticketId,
            'sender' => 'user',
            'message' => ($message !== '') ? $message : null,
            'created_at' => $now
        ]);
        $messageId = $db->insertID();

        $this->saveImages($messageId);

        $db->transComplete();

        return $this->response->setJSON([
            'ticket_id' => $ticketId,
            'ticket_code' => $code,
            'status' => 'open'
        ]);
    }

    // =========================
    // Enviar mensaje (admin a cualquiera / produccion solo propios)
    // =========================
    public function sendMessage($ticketId)
    {
        if ($resp = $this->requireLogin()) return $resp;

        $db = db_connect();
        $ticket = $db->table('support_tickets')->where('id', (int)$ticketId)->get()->getRowArray();
        if (!$ticket) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        if (!$this->canSeeTicketRow($ticket)) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden']);
        }

        $message = trim((string)$this->request->getPost('message'));
        $hasFiles = !empty($this->request->getFiles()['images'] ?? null);

        if ($message === '' && !$hasFiles) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Mensaje vacío']);
        }

        $now = date('Y-m-d H:i:s');
        $sender = $this->isAdmin() ? 'support' : 'user';

        $db->transStart();

        $db->table('support_messages')->insert([
            'ticket_id' => (int)$ticketId,
            'sender' => $sender,
            'message' => ($message !== '') ? $message : null,
            'created_at' => $now
        ]);
        $messageId = $db->insertID();

        $this->saveImages($messageId);

        // Auto-cambios de estado:
        // - Si admin responde y estaba open => in_progress
        // - Si produccion responde y estaba waiting_customer => in_progress
        $updates = ['updated_at' => $now];
        if ($this->isAdmin() && $ticket['status'] === 'open') $updates['status'] = 'in_progress';
        if (!$this->isAdmin() && $ticket['status'] === 'waiting_customer') $updates['status'] = 'in_progress';

        $db->table('support_tickets')->where('id', (int)$ticketId)->update($updates);

        $db->transComplete();

        return $this->response->setJSON(['ok' => true]);
    }

    // =========================
    // Admin: Aceptar caso (asignarse)
    // =========================
    public function assign($ticketId)
    {
        if ($resp = $this->requireLogin()) return $resp;

        if (!$this->isAdmin()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden']);
        }

        $db = db_connect();
        $adminId = $this->userId();
        $now = date('Y-m-d H:i:s');

        $ticket = $db->table('support_tickets')->where('id', (int)$ticketId)->get()->getRowArray();
        if (!$ticket) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        if (!empty($ticket['assigned_to'])) {
            return $this->response->setStatusCode(409)->setJSON(['error' => 'Ticket ya asignado']);
        }

        $db->transStart();

        $db->table('support_tickets')->where('id', (int)$ticketId)->update([
            'assigned_to' => $adminId,
            'assigned_at' => $now,
            'status' => 'in_progress',
            'updated_at' => $now
        ]);

        $db->table('support_ticket_assignments')->insert([
            'ticket_id' => (int)$ticketId,
            'admin_id' => $adminId,
            'assigned_at' => $now,
            'note' => 'Aceptado por admin'
        ]);

        $db->transComplete();

        return $this->response->setJSON(['ok' => true]);
    }

    // =========================
    // Admin: Cambiar estado (opcional pero útil)
    // Body: status=open|in_progress|waiting_customer|resolved|closed
    // =========================
    public function setStatus($ticketId)
    {
        if ($resp = $this->requireLogin()) return $resp;

        if (!$this->isAdmin()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden']);
        }

        $status = (string)$this->request->getPost('status');
        $allowed = ['open','in_progress','waiting_customer','resolved','closed'];
        if (!in_array($status, $allowed, true)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Estado inválido']);
        }

        $db = db_connect();
        $ticket = $db->table('support_tickets')->where('id', (int)$ticketId)->get()->getRowArray();
        if (!$ticket) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        $db->table('support_tickets')->where('id', (int)$ticketId)->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->response->setJSON(['ok' => true]);
    }

    // =========================
    // Ver adjunto (seguro)
    // =========================
    public function attachment($attachmentId)
    {
        if ($resp = $this->requireLogin()) return $resp;

        $db = db_connect();

        // validar que el adjunto pertenece a un ticket visible por este usuario
        $row = $db->query("
            SELECT a.*, t.user_id
            FROM support_attachments a
            JOIN support_messages m ON m.id = a.message_id
            JOIN support_tickets t ON t.id = m.ticket_id
            WHERE a.id = ?
        ", [(int)$attachmentId])->getRowArray();

        if (!$row) return $this->response->setStatusCode(404);

        if (!$this->isAdmin() && (int)$row['user_id'] !== $this->userId()) {
            return $this->response->setStatusCode(403);
        }

        $fullPath = WRITEPATH . 'uploads/' . $row['file_path'];
        if (!is_file($fullPath)) return $this->response->setStatusCode(404);

        return $this->response
            ->setHeader('Content-Type', $row['mime'] ?? 'application/octet-stream')
            ->setBody(file_get_contents($fullPath));
    }

    // =========================
    // Guardar imágenes (privado)
    // input name = images[]
    // =========================
    private function saveImages($messageId): void
    {
        $db = db_connect();
        $files = $this->request->getFiles();
        if (!isset($files['images'])) return;

        $images = $files['images'];
        if (!is_array($images)) $images = [$images];

        foreach ($images as $img) {
            if (!$img || !$img->isValid()) continue;

            $mime = $img->getClientMimeType();
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) continue;

            // max 5MB
            if ($img->getSize() > 5 * 1024 * 1024) continue;

            $newName = $img->getRandomName();
            $img->move(WRITEPATH . 'uploads/support', $newName);

            $db->table('support_attachments')->insert([
                'message_id' => (int)$messageId,
                'file_path' => 'support/' . $newName,
                'original_name' => $img->getClientName(),
                'mime' => $mime,
                'size' => $img->getSize(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
