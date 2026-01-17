<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidosEstadoModel;

class ProduccionController extends BaseController
{
    private string $estadoEntrada = 'Confirmado';
    private string $estadoProduccion = 'Por producir';

    public function index()
    {
        return view('produccion');
    }

    // =========================
    // GET /produccion/my-queue
    // =========================

    public function myQueue()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'No autenticado',
            ]);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            $sql = "
                SELECT
                    p.id,
                    p.numero,
                    p.cliente,
                    p.total,
                    p.estado_envio,
                    p.forma_envio,
                    p.etiquetas,
                    p.articulos,
                    p.created_at,
                    p.shopify_order_id,
                    p.assigned_to_user_id,
                    p.assigned_at,

                    h.estado AS estado_bd,
                    h.actualizado AS estado_actualizado,
                    h.user_name AS estado_por,
                    h.user_id AS estado_user_id

                FROM pedidos p
                LEFT JOIN (
                    SELECT ph.*
                    FROM pedidos_estado_historial ph
                    INNER JOIN (
                        SELECT order_id, MAX(id) AS last_id
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) last
                    ON last.order_id = ph.order_id AND last.last_id = ph.id
                ) h ON h.order_id = p.id

                WHERE p.assigned_to_user_id = ?
                AND LOWER(TRIM(COALESCE(h.estado,''))) = 'confirmado'

                ORDER BY COALESCE(h.actualizado, p.created_at) ASC
            ";

            $rows = $db->query($sql, [$userId])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController myQueue ERROR: ' . $e->getMessage());

            // ✅ Para debug rápido (puedes quitarlo luego)
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno cargando cola',
                'debug' => $e->getMessage(),
            ]);
        }
    }




    // =========================
    // POST /produccion/pull
    // body: {count: 5|10}
    // =========================
    public function pull()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'No autenticado']);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int)($data['count'] ?? 5);
        if (!in_array($count, [5, 10], true)) $count = 5;

        try {
            $db = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p
                JOIN (
                    SELECT x.*
                    FROM pedidos_estado_historial x
                    INNER JOIN (
                        SELECT order_id, MAX(id) AS max_id
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) last ON last.order_id = x.order_id AND last.max_id = x.id
                ) h ON h.order_id = p.id

                WHERE LOWER(TRIM(COALESCE(h.estado,''))) = 'confirmado'
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)

                ORDER BY COALESCE(h.actualizado, p.created_at) ASC
                LIMIT {$count}
            ")->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles en estado Confirmado',
                    'assigned' => 0,
                ]);
            }

            $db->transStart();

            $ids = array_map(fn($r) => (int)$r['id'], $candidatos);

            $db->table('pedidos')
                ->whereIn('id', $ids)
                ->where("(assigned_to_user_id IS NULL OR assigned_to_user_id = 0)", null, false)
                ->update([
                    'assigned_to_user_id' => $userId,
                    'assigned_at' => $now,
                ]);

            $affected = (int)$db->affectedRows();

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON(['ok' => false, 'error' => 'No se pudo asignar (transacción falló)']);
            }

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => $affected,
                'ids' => $ids,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'error' => 'Error interno asignando pedidos']);
        }
    }

    // =========================
    // POST /produccion/return-all
    // =========================
    public function returnAll()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'No autenticado']);
        }

        $userId = (int)(session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);
        }

        try {
            $db = \Config\Database::connect();

            $db->table('pedidos')
                ->where('assigned_to_user_id', $userId)
                ->update([
                    'assigned_to_user_id' => null,
                    'assigned_at' => null,
                ]);

            return $this->response->setJSON(['ok' => true, 'message' => 'Pedidos devueltos']);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController returnAll ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false, 'error' => 'Error interno devolviendo pedidos']);
        }
    }
    public function uploadGeneral()
    {
        $orderId = $this->request->getPost('order_id');
        if (!$orderId) {
            return $this->response->setJSON(['success' => false, 'message' => 'order_id requerido'])->setStatusCode(400);
        }

        $files = $this->request->getFiles();
        if (!isset($files['files'])) {
            return $this->response->setJSON(['success' => false, 'message' => 'Sin archivos'])->setStatusCode(400);
        }

        $saved = 0;
        $out = [];

        foreach ($files['files'] as $f) {
            if (!$f->isValid()) continue;

            $newName = $f->getRandomName();
            $original = $f->getName();
            $mime = $f->getClientMimeType();

            // Carpeta: writable/uploads/produccion/{orderId}/
            $dir = WRITEPATH . "uploads/produccion/" . $orderId;
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $f->move($dir, $newName);

            // 👉 Guarda en DB (recomendado) o devuelve array simple.
            // Si no tienes DB, al menos retorna URL pública (ver nota abajo).
            $saved++;
            $out[] = [
                'original_name' => $original,
                'filename' => $newName,
                'mime' => $mime,
                'size' => $f->getSize(),
                'created_at' => date('Y-m-d H:i:s'),
                'url' => site_url("produccion/file/{$orderId}/{$newName}") // necesitas route para servirlo
            ];
        }

        return $this->response->setJSON([
            'success' => true,
            'saved' => $saved,
            'files' => $out
        ]);
    }
    public function listGeneral()
    {
        $orderId = $this->request->getGet('order_id');
        if (!$orderId) {
            return $this->response->setJSON(['success' => false, 'message' => 'order_id requerido'])->setStatusCode(400);
        }

        $dir = WRITEPATH . "uploads/produccion/" . $orderId;
        if (!is_dir($dir)) {
            return $this->response->setJSON(['success' => true, 'files' => []]);
        }

        $files = [];
        foreach (scandir($dir) as $name) {
            if ($name === "." || $name === "..") continue;
            $path = $dir . "/" . $name;
            if (!is_file($path)) continue;

            $files[] = [
                'original_name' => $name,
                'filename' => $name,
                'mime' => mime_content_type($path),
                'size' => filesize($path),
                'created_at' => date('Y-m-d H:i:s', filemtime($path)),
                'url' => site_url("produccion/file/{$orderId}/{$name}")
            ];
        }

        // ordena más nuevos arriba
        usort($files, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));

        return $this->response->setJSON(['success' => true, 'files' => $files]);
    }

}


