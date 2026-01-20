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

  // ✅ ESTE es el endpoint que ahora te da 500
  public function tickets()
  {
    try {
      $role   = session('rol') ?? '';
      $userId = (int)(session('user_id') ?? 0);

      $b = $this->db->table('support_tickets');
      $b->select('id, ticket_code, order_id, status, user_id, assigned_to, assigned_at, updated_at, created_at');

      if ($role !== 'admin') {
        // produccion solo ve sus tickets
        $b->where('user_id', $userId);
      }

      $b->orderBy('updated_at', 'DESC');

      $tickets = $b->get()->getResultArray();

      return $this->response->setJSON($tickets);

    } catch (\Throwable $e) {
      log_message('error', 'tickets() error: {msg}', ['msg' => $e->getMessage()]);
      return $this->response->setStatusCode(500)->setJSON([
        'error' => 'Error interno en tickets()'
      ]);
    }
  }

  public function ticket($id)
  {
    try {
      $role   = session('rol') ?? '';
      $userId = (int)(session('user_id') ?? 0);

      $t = $this->db->table('support_tickets')->where('id', (int)$id)->get()->getRowArray();
      if (!$t) {
        return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no encontrado']);
      }

      // permiso: produccion solo su ticket
      if ($role !== 'admin' && (int)$t['user_id'] !== $userId) {
        return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
      }

      $msgs = $this->db->table('support_messages')
        ->where('ticket_id', (int)$id)
        ->orderBy('id', 'ASC')
        ->get()->getResultArray();

      $atts = $this->db->table('support_attachments')
        ->where('ticket_id', (int)$id)
        ->orderBy('id', 'ASC')
        ->get()->getResultArray();

      // agrupar attachments por message_id (tu frontend espera attachments[m.id])
      $grouped = [];
      foreach ($atts as $a) {
        $mid = (int)($a['message_id'] ?? 0);
        if (!isset($grouped[$mid])) $grouped[$mid] = [];
        $grouped[$mid][] = $a;
      }

      return $this->response->setJSON([
        'ticket' => $t,
        'messages' => $msgs,
        'attachments' => $grouped
      ]);

    } catch (\Throwable $e) {
      log_message('error', 'ticket($id) error: {msg}', ['msg' => $e->getMessage()]);
      return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno abriendo ticket']);
    }
  }

  // crear ticket (solo produccion)
  public function create()
  {
    try {
      $role   = session('rol') ?? '';
      $userId = (int)(session('user_id') ?? 0);

      if ($role === 'admin') {
        return $this->response->setStatusCode(403)->setJSON(['error' => 'Admin no crea tickets']);
      }

      $message = trim((string)$this->request->getPost('message'));
      $orderId = trim((string)$this->request->getPost('order_id'));

      if ($message === '' && empty($_FILES['images'])) {
        return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
      }

      $code = 'T' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

      $now = date('Y-m-d H:i:s');

      $this->db->transStart();

      $this->db->table('support_tickets')->insert([
        'ticket_code' => $code,
        'user_id' => $userId,
        'order_id' => $orderId ?: null,
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now
      ]);

      $ticketId = (int)$this->db->insertID();

      $this->db->table('support_messages')->insert([
        'ticket_id' => $ticketId,
        'sender' => 'user',
        'message' => $message,
        'created_at' => $now
      ]);
      $msgId = (int)$this->db->insertID();

      // imágenes
      $files = $this->request->getFiles();
      $imgs = $files['images'] ?? [];

      if (!is_array($imgs)) $imgs = [$imgs];

      foreach ($imgs as $img) {
        if (!$img || !$img->isValid()) continue;

        $newName = $img->getRandomName();
        $img->move(WRITEPATH . 'uploads/support', $newName);

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

      return $this->response->setJSON(['ticket_id' => $ticketId]);

    } catch (\Throwable $e) {
      log_message('error', 'create() error: {msg}', ['msg' => $e->getMessage()]);
      return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno creando ticket']);
    }
  }

  public function message($id)
  {
    try {
      $role   = session('rol') ?? '';
      $userId = (int)(session('user_id') ?? 0);
      $id = (int)$id;

      $t = $this->db->table('support_tickets')->where('id', $id)->get()->getRowArray();
      if (!$t) return $this->response->setStatusCode(404)->setJSON(['error' => 'Ticket no existe']);

      if ($role !== 'admin' && (int)$t['user_id'] !== $userId) {
        return $this->response->setStatusCode(403)->setJSON(['error' => 'Sin permisos']);
      }

      $message = trim((string)$this->request->getPost('message'));
      if ($message === '' && empty($_FILES['images'])) {
        return $this->response->setStatusCode(400)->setJSON(['error' => 'Mensaje o imagen requerida']);
      }

      $now = date('Y-m-d H:i:s');

      $sender = ($role === 'admin') ? 'admin' : 'user';

      $this->db->transStart();

      $this->db->table('support_messages')->insert([
        'ticket_id' => $id,
        'sender' => $sender,
        'message' => $message,
        'created_at' => $now
      ]);
      $msgId = (int)$this->db->insertID();

      // imágenes
      $files = $this->request->getFiles();
      $imgs = $files['images'] ?? [];
      if (!is_array($imgs)) $imgs = [$imgs];

      foreach ($imgs as $img) {
        if (!$img || !$img->isValid()) continue;

        $newName = $img->getRandomName();
        $img->move(WRITEPATH . 'uploads/support', $newName);

        $this->db->table('support_attachments')->insert([
          'ticket_id' => $id,
          'message_id' => $msgId,
          'filename' => $newName,
          'mime' => $img->getMimeType(),
          'created_at' => $now
        ]);
      }

      // tocar updated_at
      $this->db->table('support_tickets')->where('id', $id)->update(['updated_at' => $now]);

      $this->db->transComplete();

      return $this->response->setJSON(['ok' => true]);

    } catch (\Throwable $e) {
      log_message('error', 'message() error: {msg}', ['msg' => $e->getMessage()]);
      return $this->response->setStatusCode(500)->setJSON(['error' => 'Error interno enviando mensaje']);
    }
  }

  // admin acepta caso
  public function assign($id)
  {
    $role = session('rol') ?? '';
    if ($role !== 'admin') {
      return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);
    }

    $adminId = (int)(session('user_id') ?? 0);
    $now = date('Y-m-d H:i:s');

    $this->db->table('support_tickets')->where('id', (int)$id)->update([
      'assigned_to' => $adminId,
      'assigned_at' => $now,
      'updated_at' => $now
    ]);

    return $this->response->setJSON(['ok' => true]);
  }

  public function status($id)
  {
    $role = session('rol') ?? '';
    if ($role !== 'admin') {
      return $this->response->setStatusCode(403)->setJSON(['error' => 'Solo admin']);
    }

    $status = (string)$this->request->getPost('status');
    $allowed = ['open','in_progress','waiting_customer','resolved','closed'];
    if (!in_array($status, $allowed, true)) {
      return $this->response->setStatusCode(400)->setJSON(['error' => 'Estado inválido']);
    }

    $now = date('Y-m-d H:i:s');

    $this->db->table('support_tickets')->where('id', (int)$id)->update([
      'status' => $status,
      'updated_at' => $now
    ]);

    return $this->response->setJSON(['ok' => true]);
  }

  public function attachment($id)
  {
    $a = $this->db->table('support_attachments')->where('id', (int)$id)->get()->getRowArray();
    if (!$a) {
      return $this->response->setStatusCode(404)->setBody('No encontrado');
    }

    $path = WRITEPATH . 'uploads/support/' . $a['filename'];
    if (!is_file($path)) {
      return $this->response->setStatusCode(404)->setBody('Archivo no existe');
    }

    return $this->response->download($path, null)->setFileName($a['filename']);
  }
}
