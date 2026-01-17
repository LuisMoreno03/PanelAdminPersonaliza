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

        $userId = (int) (session('user_id') ?? 0);
        if (!$userId) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Sin user_id en sesión',
            ]);
        }

        try {
            $db = \Config\Database::connect();

            // ✅ JOIN consistente: pedidos_estado.order_id = pedidos.shopify_order_id
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
                    pe.estado AS estado_bd,
                    pe.actualizado AS estado_actualizado,
                    pe.estado_updated_by_name AS estado_por
                FROM pedidos p
                LEFT JOIN pedidos_estado pe
                    ON pe.order_id = p.shopify_order_id
                WHERE p.assigned_to_user_id = ?
                AND LOWER(TRIM(COALESCE(pe.estado,'por preparar'))) IN ('por producir','confirmado')
                ORDER BY COALESCE(pe.actualizado, p.created_at) ASC
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
    if (!in_array($count, [5, 10], true)) $count = 5;

    // ✅ DEBUG garantizado del Model
    $modelPath = APPPATH . 'Models/PedidosEstadoModel.php';

    if (!is_file($modelPath)) {
        return $this->response->setJSON([
            'ok' => false,
            'error' => 'Model no carga',
            'debug' => [
                'reason' => 'file_not_found',
                'expected_path' => $modelPath,
                'APPPATH' => APPPATH,
            ],
        ]);
    }

    require_once $modelPath;

    if (!class_exists(\App\Models\PedidosEstadoModel::class)) {
        return $this->response->setJSON([
            'ok' => false,
            'error' => 'Model no carga',
            'debug' => [
                'reason' => 'class_not_found',
                'expected_class' => \App\Models\PedidosEstadoModel::class,
                'file_loaded' => $modelPath,
                'hint' => 'Revisar namespace App\\Models y el nombre del archivo/clase (Linux es case-sensitive).',
            ],
        ]);
    }

    $estadoModel = new \App\Models\PedidosEstadoModel();

    try {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // ✅ Candidatos: estado confirmado + no asignados
        // OJO: tu DB mostró que lo correcto es: pedidos.shopify_order_id = pedidos_estado.order_id
        $candidatos = $db->query("
            SELECT p.id, p.shopify_order_id
            FROM pedidos p
            JOIN pedidos_estado pe ON pe.order_id = p.shopify_order_id
            WHERE LOWER(TRIM(pe.estado))='confirmado'
              AND (p.assigned_to_user_id IS NULL OR p.assigned_to_user_id = 0)
            ORDER BY COALESCE(pe.estado_updated_at, pe.actualizado, p.created_at) ASC
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
                'debug' => [
                    'ids_candidatos' => $ids,
                    'user_id' => $userId,
                ],
            ]);
        }

        // ✅ Cambia estado a "Por producir" usando el model
        foreach ($candidatos as $c) {
            $oid = trim((string)($c['shopify_order_id'] ?? ''));
            if ($oid === '' || $oid === '0') continue;
            $estadoModel->setEstadoPedido($oid, 'Por producir', $userId, $userName);
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
            'debug' => [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ],
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


