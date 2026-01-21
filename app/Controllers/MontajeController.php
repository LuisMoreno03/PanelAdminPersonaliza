<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class MontajeController extends Controller
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // =========================
    // VIEW
    // =========================
    public function index()
    {
        return view('montaje/index');
    }

    // =========================
    // API: trae pedidos DISEÑADO
    // GET /montaje/my-queue
    // =========================
    public function myQueue()
    {
        try {
            $builder = $this->db->table('pedidos');

            $builder->groupStart()
                ->where('estado_bd', 'Diseñado')
                ->orWhere('estado_bd', 'Disenado')
                ->groupEnd();

            $rows = $builder
                ->select('id, shopify_order_id, numero, created_at, cliente, total, estado_bd, etiquetas, articulos, estado_envio, forma_envio, last_status_change')
                ->orderBy('id', 'DESC')
                ->get()
                ->getResultArray();

            return $this->response->setJSON([
                'success' => true,
                'orders'  => $rows,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error cargando pedidos',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // API: Subir pedido -> Por producir
    // POST /montaje/subir-pedido
    // body JSON: { order_id, pedido_id, shopify_order_id }
    // =========================
    public function subirPedido()
    {
        try {
            $data = $this->request->getJSON(true) ?? [];

            $orderId   = trim((string)($data['order_id'] ?? ''));
            $pedidoId  = trim((string)($data['pedido_id'] ?? ''));
            $shopifyId = trim((string)($data['shopify_order_id'] ?? ''));

            $builder = $this->db->table('pedidos');
            $pedido = null;

            if ($shopifyId !== '' && $shopifyId !== '0') {
                $pedido = $builder->where('shopify_order_id', $shopifyId)->get()->getRowArray();
            }

            if (!$pedido && $pedidoId !== '' && $pedidoId !== '0') {
                $pedido = $builder->where('id', $pedidoId)->get()->getRowArray();
            }

            if (!$pedido && $orderId !== '') {
                $pedido = $builder->where('shopify_order_id', $orderId)->get()->getRowArray();
                if (!$pedido && ctype_digit($orderId)) {
                    $pedido = $builder->where('id', (int)$orderId)->get()->getRowArray();
                }
            }

            if (!$pedido) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ]);
            }

            $id = $pedido['id'];
            $userName = session('user_name') ?? session('username') ?? 'Sistema';

            $last = json_encode([
                'user_name'  => $userName,
                'changed_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);

            $this->db->table('pedidos')
                ->where('id', $id)
                ->update([
                    'estado_bd'          => 'Por producir',
                    'last_status_change' => $last,
                ]);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Estado actualizado a Por producir',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error subiendo pedido',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // =========================
    // OPCIONAL: upload/list general
    // =========================
    public function uploadGeneral()
    {
        try {
            $orderId = trim((string)$this->request->getPost('order_id'));
            if ($orderId === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'order_id requerido',
                ]);
            }

            $files = $this->request->getFiles();
            $arr = $files['files'] ?? null;

            if (!$arr) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'No llegaron archivos (files[])',
                ]);
            }

            $list = is_array($arr) ? $arr : [$arr];
            $dir = FCPATH . 'uploads/montaje/' . $orderId . '/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);

            $saved = 0;
            foreach ($list as $f) {
                if (!$f->isValid()) continue;
                $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $f->getClientName());
                $final = time() . '_' . $name;
                $f->move($dir, $final);
                $saved++;
            }

            // Si quieres que al subir archivos también pase a Por producir
            $this->db->table('pedidos')
                ->groupStart()
                ->where('shopify_order_id', $orderId)
                ->orWhere('id', ctype_digit($orderId) ? (int)$orderId : 0)
                ->groupEnd()
                ->update([
                    'estado_bd' => 'Por producir',
                    'last_status_change' => json_encode([
                        'user_name'  => session('user_name') ?? session('username') ?? 'Sistema',
                        'changed_at' => date('Y-m-d H:i:s'),
                    ], JSON_UNESCAPED_UNICODE),
                ]);

            return $this->response->setJSON([
                'success' => true,
                'saved'   => $saved,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error subiendo archivos',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function listGeneral()
    {
        try {
            $orderId = trim((string)$this->request->getGet('order_id'));
            if ($orderId === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'order_id requerido',
                ]);
            }

            $dir = FCPATH . 'uploads/montaje/' . $orderId . '/';
            if (!is_dir($dir)) {
                return $this->response->setJSON(['success' => true, 'files' => []]);
            }

            $files = [];
            foreach (scandir($dir) as $fn) {
                if ($fn === '.' || $fn === '..') continue;
                $path = $dir . $fn;
                if (!is_file($path)) continue;

                $files[] = [
                    'filename'      => $fn,
                    'original_name' => $fn,
                    'size'          => filesize($path),
                    'mime'          => function_exists('mime_content_type') ? mime_content_type($path) : '',
                    'url'           => base_url('uploads/montaje/' . $orderId . '/' . $fn),
                ];
            }

            return $this->response->setJSON([
                'success' => true,
                'files'   => $files,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Error listando archivos',
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
