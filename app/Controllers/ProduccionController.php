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

            // ✅ Ultimo estado desde HISTORIAL (por pedido interno p.id)
            // Nota: h.created_at existe, h.actualizado NO existe.
            $rows = $db->query("
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

                    -- ✅ estado actual: primero historial, si no hay historial usa pedidos_estado
                    COALESCE(
                        CAST(h.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci,
                        CAST(pe.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci,
                        'por preparar'
                    ) AS estado_bd,


                    -- ✅ ultimo cambio
                    COALESCE(h.created_at, pe.estado_updated_at, pe.actualizado, p.created_at) AS estado_actualizado,
                    COALESCE(h.user_name, pe.estado_updated_by_name) AS estado_por

                FROM pedidos p

                -- fallback: pedidos_estado (ojo: a veces guarda order_id = p.id o shopify_order_id)
                LEFT JOIN pedidos_estado pe
                    ON (pe.order_id = p.id OR pe.order_id = p.shopify_order_id)

                -- ✅ subquery: ultimo historial por p.id
                LEFT JOIN (
                    SELECT h1.order_id, h1.estado, h1.user_name, h1.created_at
                    FROM pedidos_estado_historial h1
                    INNER JOIN (
                        SELECT order_id, MAX(created_at) AS max_created
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) hx
                    ON hx.order_id = h1.order_id AND hx.max_created = h1.created_at
                ) h
                ON h.order_id = p.id

                WHERE p.assigned_to_user_id = ?
                    AND LOWER(TRIM(
                        CAST(COALESCE(h.estado, pe.estado, '') AS CHAR)
                        COLLATE utf8mb4_uca1400_ai_ci
                    )) = 'confirmado'


                ORDER BY COALESCE(h.created_at, pe.estado_updated_at, pe.actualizado, p.created_at) ASC
            ", [$userId])->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows ?: [],
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController myQueue ERROR: ' . $e->getMessage());
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

        $userId   = (int)(session('user_id') ?? 0);
        $userName = (string)(session('nombre') ?? session('user_name') ?? 'Usuario');

        if (!$userId) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sin user_id en sesión']);
        }

        $data = $this->request->getJSON(true);
        if (!is_array($data)) $data = [];

        $count = (int)($data['count'] ?? 5);
        if (!in_array($count, [5,10], true)) $count = 5;

        try {
            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            // ✅ candidatos: pedidos SIN asignar cuyo ultimo estado (historial) sea CONFIRMADO
            $candidatos = $db->query("
                SELECT
                    p.id,
                    p.shopify_order_id
                FROM pedidos p
                INNER JOIN (
                    SELECT h1.order_id, h1.estado, h1.created_at
                    FROM pedidos_estado_historial h1
                    INNER JOIN (
                        SELECT order_id, MAX(created_at) AS max_created
                        FROM pedidos_estado_historial
                        GROUP BY order_id
                    ) hx
                    ON hx.order_id = h1.order_id
                    AND hx.max_created = h1.created_at
                ) h ON h.order_id = p.id
                WHERE LOWER(TRIM(
                    CAST(h.estado AS CHAR) COLLATE utf8mb4_uca1400_ai_ci
                )) = 'confirmado'
                AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
                ORDER BY h.created_at ASC
                LIMIT {$count}
            ")->getResultArray();

            if (!$candidatos) {
                return $this->response->setJSON([
                    'ok' => true,
                    'message' => 'No hay pedidos disponibles para asignar',
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

            if ($affected <= 0) {
                $db->transComplete();
                return $this->response->setJSON([
                    'ok' => false,
                    'error' => 'No se asignó nada (affectedRows=0).',
                    'debug' => ['ids_candidatos' => $ids]
                ]);
            }

            // ✅ (Opcional recomendado) registrar en historial un evento de asignación
            // Si NO quieres cambiar estado, solo registra log si te interesa.
            // Si sí quieres cambiar estado aquí, cambia 'Confirmado' por el nuevo estado.
            foreach ($candidatos as $c) {
                $pid = (int)($c['id'] ?? 0);
                if (!$pid) continue;

                $db->table('pedidos_estado_historial')->insert([
                    'order_id'    => $pid,
                    'estado'      => 'Confirmado', // o el estado que corresponda al asignar
                    'user_id'     => $userId,
                    'user_name'   => $userName,
                    'created_at'  => $now,
                    'pedido_json' => null,
                ]);
            }

            $db->transComplete();

            return $this->response->setJSON([
                'ok' => true,
                'assigned' => $affected,
                'ids' => $ids,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'ProduccionController pull ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Error interno asignando pedidos',
                'debug' => $e->getMessage()
            ]);
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


