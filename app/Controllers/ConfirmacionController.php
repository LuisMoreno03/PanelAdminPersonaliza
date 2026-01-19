<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\PedidosEstadoModel;

class ConfirmacionController extends Controller
{
    public function index()
    {
        if (!session()->get('logged_in')) return redirect()->to('/');
        return view('confirmacion'); // tu vista parecida a produccion
    }

    /**
     * Cola general de confirmación:
     * - Solo estado: "Por preparar"
     * - Prioridad Express primero
     */
    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }

        $db = \Config\Database::connect();

        // 1) Order IDs por estado (de tu tabla pedidos_estado)
        $estadoModel = new PedidosEstadoModel();
        $orderIds = $estadoModel->getOrderIdsByEstado('Por preparar', 500, 0); // ajusta

        if (!$orderIds) {
            return $this->response->setJSON(['success' => true, 'orders' => []]);
        }

        // 2) Traer pedidos desde tabla pedidos con prioridad express
        $rows = $db->table('pedidos p')
            ->select('p.id, p.shopify_order_id, p.numero, p.cliente, p.total, p.etiquetas, p.forma_envio, p.created_at, p.assigned_to_user_id')
            ->whereIn('p.shopify_order_id', $orderIds)
            ->orderBy("
                CASE
                  WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                  WHEN LOWER(p.etiquetas) LIKE '%express%' THEN 0
                  ELSE 1
                END
            ", '', false)
            ->orderBy('p.created_at', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $rows,
        ]);
    }

    /**
     * Pull: asigna el siguiente pedido al usuario (opcional, igual que Producción)
     */
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }

        $db = \Config\Database::connect();
        $userId = (int)(session('user_id') ?? 0);

        $estadoModel = new PedidosEstadoModel();
        $orderIds = $estadoModel->getOrderIdsByEstado('Por preparar', 500, 0);

        if (!$orderIds) {
            return $this->response->setJSON(['success' => true, 'message' => 'No hay pedidos', 'order' => null]);
        }

        // Busca el mejor candidato sin asignación
        $pedido = $db->table('pedidos p')
            ->select('p.id, p.shopify_order_id, p.numero, p.cliente, p.total, p.etiquetas, p.forma_envio, p.created_at')
            ->whereIn('p.shopify_order_id', $orderIds)
            ->where('(p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)', null, false)
            ->orderBy("
                CASE
                  WHEN LOWER(p.forma_envio) LIKE '%express%' THEN 0
                  WHEN LOWER(p.etiquetas) LIKE '%express%' THEN 0
                  ELSE 1
                END
            ", '', false)
            ->orderBy('p.created_at', 'ASC')
            ->get()
            ->getRowArray();

        if (!$pedido) {
            return $this->response->setJSON(['success' => true, 'message' => 'No hay pedidos libres', 'order' => null]);
        }

        // Asignar
        $db->table('pedidos')->where('id', (int)$pedido['id'])->update([
            'assigned_to_user_id' => $userId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON(['success' => true, 'order' => $pedido]);
    }

    /**
     * Subir archivos de confirmación (cuadros/llaveros) y auto-estado -> Confirmado
     * REGLA: si hay al menos 1 archivo válido subido, cambiamos a Confirmado
     * (Si quieres regla más estricta por item, te la ajusto abajo)
     */
    public function uploadConfirmacion()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }

        $orderId = trim((string)($this->request->getPost('order_id') ?? ''));
        if ($orderId === '' || $orderId === '0') {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'order_id requerido']);
        }

        $files = $this->request->getFiles();
        if (!isset($files['files'])) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Sin archivos']);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // Guardar en carpeta
        $dir = WRITEPATH . "uploads/confirmacion/" . $orderId;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $saved = 0;
        $out = [];

        foreach ($files['files'] as $f) {
            if (!$f || !$f->isValid()) continue;

            $newName  = $f->getRandomName();
            $original = $f->getName();
            $mime     = $f->getClientMimeType();

            $f->move($dir, $newName);

            $saved++;
            $url = site_url("confirmacion/file/{$orderId}/{$newName}");

            // Opcional: guardar referencia en DB (recomendado)
            $db->table('pedido_archivos_confirmacion')->insert([
                'order_id' => (string)$orderId,
                'line_index' => null,
                'filename' => $newName,
                'original_name' => $original,
                'mime' => $mime,
                'size' => $f->getSize(),
                'created_at' => $now,
                'created_by' => (int)(session('user_id') ?? 0),
                'created_by_name' => (string)(session('nombre') ?? session('user_name') ?? 'Sistema'),
            ]);

            $out[] = [
                'original_name' => $original,
                'filename' => $newName,
                'mime' => $mime,
                'size' => $f->getSize(),
                'created_at' => $now,
                'url' => $url,
            ];
        }

        if ($saved <= 0) {
            return $this->response->setJSON(['success' => false, 'message' => 'No se subió ningún archivo válido']);
        }

        // ✅ Auto-cambiar estado a "Confirmado"
        $estadoModel = new \App\Models\PedidosEstadoModel();
        $userId   = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Sistema');

        $okEstado = (bool)$estadoModel->setEstadoPedido((string)$orderId, 'Confirmado', $userId ?: null, $userName);

        // Historial (siempre que quieras)
        $db->table('pedidos_estado_historial')->insert([
            'order_id'   => (string)$orderId,
            'estado'     => 'Confirmado',
            'user_id'    => $userId ?: null,
            'user_name'  => $userName,
            'created_at' => $now,
            'pedido_json'=> null,
        ]);

        // Opcional: desasignar para que desaparezca de “mi cola”
        $db->table('pedidos')
            ->where('shopify_order_id', (string)$orderId)
            ->update(['assigned_to_user_id' => null, 'assigned_at' => null]);

        return $this->response->setJSON([
            'success' => true,
            'saved' => $saved,
            'files' => $out,
            'estado_set' => $okEstado,
            'new_estado' => 'Confirmado',
        ]);
    }

    public function listFiles()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => 'No autenticado']);
        }

        $orderId = trim((string)($this->request->getGet('order_id') ?? ''));
        if ($orderId === '') {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'order_id requerido']);
        }

        $db = \Config\Database::connect();

        $rows = $db->table('pedido_archivos_confirmacion')
            ->select('filename, original_name, mime, size, created_at')
            ->where('order_id', (string)$orderId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $out = array_map(function($r) use ($orderId) {
            return [
                'filename' => $r['filename'],
                'original_name' => $r['original_name'],
                'mime' => $r['mime'],
                'size' => $r['size'],
                'created_at' => $r['created_at'],
                'url' => site_url("confirmacion/file/{$orderId}/{$r['filename']}"),
            ];
        }, $rows);

        return $this->response->setJSON(['success' => true, 'files' => $out]);
    }
}
