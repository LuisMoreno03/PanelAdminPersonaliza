<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Dashboard extends BaseController
{
    // =========================
    // VISTA
    // =========================
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('dashboard');
    }

    // =====================================================
    // PEDIDOS (50 en 50)
    // =====================================================
    public function pedidos()
    {
        return $this->pedidosPaginados();
    }

    // Alias para JS viejo
    public function filter()
    {
        return $this->pedidosPaginados();
    }

    // =====================================================
    // CORE: pedidos paginados + ultimo cambio BD
    // =====================================================
    private function pedidosPaginados(): ResponseInterface
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No autenticado'
            ])->setStatusCode(401);
        }

        try {
            $pageInfo = (string) ($this->request->getGet('page_info') ?? '');
            $limit = 50;

            $shop  = trim((string) env('SHOPIFY_STORE_DOMAIN'));
            $token = trim((string) env('SHOPIFY_ADMIN_TOKEN'));
            $apiVersion = '2024-01';

            if (!$shop || !$token) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Falta SHOPIFY_STORE_DOMAIN o SHOPIFY_ADMIN_TOKEN',
                    'orders'  => [],
                    'count'   => 0,
                ])->setStatusCode(200);
            }

            $shop = preg_replace('#^https?://#', '', $shop);
            $shop = preg_replace('#/.*$#', '', $shop);

            if ($pageInfo !== '') {
                $url = "https://{$shop}/admin/api/{$apiVersion}/orders.json?limit={$limit}&page_info=" . urlencode($pageInfo);
            } else {
                $url = "https://{$shop}/admin/api/{$apiVersion}/orders.json?limit={$limit}&status=any&order=created_at%20desc";
            }

            $client = \Config\Services::curlrequest([
                'timeout' => 25,
                'http_errors' => false,
            ]);

            $resp = $client->get($url, [
                'headers' => [
                    'X-Shopify-Access-Token' => $token,
                    'Accept' => 'application/json',
                ],
            ]);

            $status = $resp->getStatusCode();
            $raw = (string) $resp->getBody();
            $json = json_decode($raw, true) ?: [];

            if ($status >= 400) {
                log_message('error', 'SHOPIFY ORDERS HTTP '.$status.': '.$raw);

                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Error consultando Shopify',
                    'orders'  => [],
                    'count'   => 0,
                    'status'  => $status,
                ])->setStatusCode(200);
            }

            $ordersRaw = $json['orders'] ?? [];

            // next/prev page_info
            $linkHeader = $resp->getHeaderLine('Link');
            $nextPageInfo = null;
            $prevPageInfo = null;

            if ($linkHeader) {
                if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="next"/', $linkHeader, $m)) {
                    $nextPageInfo = urldecode($m[1]);
                }
                if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="previous"/', $linkHeader, $m2)) {
                    $prevPageInfo = urldecode($m2[1]);
                }
            }

            // Mapear formato dashboard
            $orders = [];
            foreach ($ordersRaw as $o) {
                $orderId = $o['id'] ?? null;

                $numero = $o['name'] ?? ('#' . ($o['order_number'] ?? $orderId));
                $fecha  = isset($o['created_at']) ? substr($o['created_at'], 0, 10) : '-';

                $cliente = '-';
                if (!empty($o['customer'])) {
                    $cliente = trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''));
                    if ($cliente === '') $cliente = '-';
                }

                $total = isset($o['total_price']) ? ($o['total_price'] . ' €') : '-';
                $articulos = isset($o['line_items']) ? count($o['line_items']) : 0;

                $estado_envio = $o['fulfillment_status'] ?? '-';
                $forma_envio  = (!empty($o['shipping_lines'][0]['title'])) ? $o['shipping_lines'][0]['title'] : '-';

                $orders[] = [
                    'id'           => $orderId,
                    'numero'       => $numero,
                    'fecha'        => $fecha,
                    'cliente'      => $cliente,
                    'total'        => $total,
                    'estado'       => (!empty($o['tags']) ? 'Producción' : 'Por preparar'),
                    'etiquetas'    => $o['tags'] ?? '',
                    'articulos'    => $articulos,
                    'estado_envio' => $estado_envio ?: '-',
                    'forma_envio'  => $forma_envio ?: '-',
                    'last_status_change' => null,
                ];
            }

            // Último cambio desde BD
            $db = \Config\Database::connect();

            foreach ($orders as &$ord) {
                $orderId = $ord['id'] ?? null;
                if (!$orderId) {
                    $ord['last_status_change'] = null;
                    continue;
                }

                $row = $db->table('pedidos_estado')
                    ->select('created_at, user_id')
                    ->where('id', $orderId)
                    ->orderBy('created_at', 'DESC')
                    ->limit(1)
                    ->get()
                    ->getRowArray();

                $userName = 'Sistema';
                if (!empty($row['user_id'])) {
                    $u = $db->table('users')->where('id', $row['user_id'])->get()->getRowArray();
                    if ($u && !empty($u['nombre'])) $userName = $u['nombre'];
                }

                $ord['last_status_change'] = [
                    'user_name'  => $userName,
                    'changed_at' => $row['created_at'] ?? null,
                ];
            }
            unset($ord);

            return $this->response->setJSON([
                'success'        => true,
                'orders'         => $orders,
                'count'          => count($orders),
                'limit'          => $limit,
                'next_page_info' => $nextPageInfo,
                'prev_page_info' => $prevPageInfo,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'DASHBOARD PEDIDOS ERROR: ' . $e->getMessage());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Error interno consultando pedidos',
                'orders'  => [],
                'count'   => 0,
            ])->setStatusCode(200);
        }
    }

    // =====================================================
    // PING (marca activo) - BLINDADO
    // =====================================================
    public function ping()
    {
        try {
            $userId = session('user_id');
            if (!$userId) {
                return $this->response->setJSON(['ok' => false])->setStatusCode(200);
            }

            $db = \Config\Database::connect();

            $hasLastSeen = $this->columnExists($db, 'usuarios', 'last_seen');
            $hasIsOnline = $this->columnExists($db, 'usuarios', 'is_online');

            $data = [];
            if ($hasLastSeen) $data['last_seen'] = date('Y-m-d H:i:s');
            if ($hasIsOnline) $data['is_online'] = 1;

            // Si no hay columnas, no hagas update (evita error)
            if (!empty($data)) {
                $db->table('usuarios')->where('id', $userId)->update($data);
            }

            return $this->response->setJSON([
                'ok' => true,
                'last_seen_enabled' => $hasLastSeen,
                'is_online_enabled' => $hasIsOnline,
            ])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'PING ERROR: ' . $e->getMessage());
            return $this->response->setJSON(['ok' => false])->setStatusCode(200);
        }
    }

    // =====================================================
    // USUARIOS ESTADO (últimos 2 min) - BLINDADO
    // =====================================================
    public function usuariosEstado()
    {
        try {
            $db = \Config\Database::connect();

            $hasLastSeen = $this->columnExists($db, 'usuarios', 'last_seen');

            if (!$hasLastSeen) {
                // Sin last_seen no podemos calcular "últimos 2 min"
                return $this->response->setJSON([
                    'ok' => true,
                    'conectados' => [],
                    'count' => 0,
                    'warning' => 'La columna usuarios.last_seen no existe',
                ])->setStatusCode(200);
            }

            $cutoff = date('Y-m-d H:i:s', time() - 120);

            $conectados = $db->table('usuarios')
                ->select('id, nombre, last_seen')
                ->where('last_seen >=', $cutoff)
                ->orderBy('last_seen', 'DESC')
                ->get()->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'conectados' => $conectados,
                'count' => count($conectados),
            ])->setStatusCode(200);

        } catch (\Throwable $e) {
            log_message('error', 'USUARIOS_ESTADO ERROR: ' . $e->getMessage());
            return $this->response->setJSON([
                'ok' => false,
                'conectados' => [],
                'count' => 0,
            ])->setStatusCode(200);
        }
    }

    // =====================================================
    // Helper: verificar si existe una columna en MySQL
    // =====================================================
    private function columnExists($db, string $table, string $column): bool
    {
        try {
            $dbName = $db->getDatabase();

            $row = $db->query(
                "SELECT 1
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1",
                [$dbName, $table, $column]
            )->getRowArray();

            return !empty($row);
        } catch (\Throwable $e) {
            // Si falla este check, mejor no romper nada:
            log_message('error', 'columnExists ERROR: ' . $e->getMessage());
            return false;
        }
    }
}
