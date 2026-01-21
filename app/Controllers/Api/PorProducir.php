<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\PorProducirQueueModel;
use App\Services\ShopifyService;

class PorProducir extends BaseController
{
    private PorProducirQueueModel $queue;
    private ShopifyService $shopify;

    // ✅ CAMBIA ESTO si tu tag real es distinto (ej: "Pör producir")
    private string $TAG_POR_PRODUCIR = 'Por producir';

    public function __construct()
    {
        $this->queue = new PorProducirQueueModel();
        $this->shopify = new ShopifyService();
    }

    private function currentUser(): string
    {
        return (string) (session()->get('nombre') ?? 'Sistema');
    }

    private function isEnviado(array $o): bool
    {
        $fs = strtolower((string)($o['fulfillment_status'] ?? ''));
        // Shopify displayFulfillmentStatus suele ser: FULFILLED / UNFULFILLED / PARTIALLY_FULFILLED
        return $fs === 'fulfilled' || str_contains($fs, 'fulfilled');
    }

    private function toUiRow(array $shop, array $row): array
    {
        $tags = $shop['tags'] ?? [];
        if (is_array($tags)) $tags = implode(', ', $tags);

        return [
            // ✅ id interno (cola)
            'id' => $row['id'],

            // ✅ id shopify
            'shopify_order_id' => (string)$row['order_id'],

            'numero' => $shop['name'] ?? ('#' . $row['order_id']),
            'fecha' => $shop['created_at'] ?? null,
            'cliente' => $shop['cliente_nombre'] ?? '—',
            'total' => $shop['total'] ?? null,

            // ✅ estado fijo por sección
            'estado' => 'Por producir',
            'estado_bd' => 'Por producir',

            'etiquetas' => $tags ?: '',
            'articulos' => $shop['items_count'] ?? '',
            'estado_envio' => $shop['fulfillment_status'] ?? '',
            'forma_envio' => $shop['shipping_method'] ?? '',

            // para “Último cambio” (igual a producción)
            'last_status_change' => [
                'user_name' => $row['assigned_to'] ?? null,
                'changed_at' => $row['assigned_at'] ?? null,
            ],
        ];
    }

    // ✅ GET: mi cola actual
    public function mine()
    {
        try {
            $user = $this->currentUser();

            $rows = $this->queue->where('assigned_to', $user)
                ->orderBy('assigned_at', 'ASC')
                ->findAll();

            if (!$rows) {
                return $this->response->setJSON([
                    'success' => true,
                    'orders' => [],
                ]);
            }

            $ids = array_values(array_unique(array_map(fn($r) => (string)$r['order_id'], $rows)));

            // Trae datos Shopify por IDs
            $shopOrders = $this->shopify->getOrdersByIds($ids); // retorna MAP [id_num => order]
            $out = [];

            foreach ($rows as $r) {
                $sid = (string)$r['order_id'];
                $shop = $shopOrders[$sid] ?? null;
                if (!$shop) continue;

                // si ya está enviado, lo quitamos de la cola automáticamente
                if ($this->isEnviado($shop)) {
                    $this->queue->where('assigned_to', $user)->where('order_id', $sid)->delete();
                    continue;
                }

                $out[] = $this->toUiRow($shop, $r);
            }

            return $this->response->setJSON([
                'success' => true,
                'orders' => $out,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'PorProducir mine error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST: traer 50/100 (claim)
    public function claim()
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];
            $limit = (int)($payload['limit'] ?? ($payload['count'] ?? 50));
            if (!in_array($limit, [50, 100], true)) $limit = 50;

            $user = $this->currentUser();
            $now  = date('Y-m-d H:i:s');

            $claimed = [];

            // paginación Shopify hasta completar cupo
            $after = null;
            $tries = 0;

            while (count($claimed) < $limit && $tries < 25) {
                $tries++;

                $page = $this->shopify->searchOrdersByQueryPage(
                    'tag:"' . $this->TAG_POR_PRODUCIR . '" status:any',
                    100,
                    $after
                );

                $orders = $page['orders'] ?? [];
                $after  = $page['endCursor'] ?? null;
                $hasNext = (bool)($page['hasNextPage'] ?? false);

                foreach ($orders as $o) {
                    if (count($claimed) >= $limit) break;

                    // no meter enviados
                    if ($this->isEnviado($o)) continue;

                    $oid = (string)$o['id_num'];

                    // insertar en cola (unique evita duplicados)
                    try {
                        $this->queue->insert([
                            'order_id' => $oid,
                            'order_name' => $o['name'] ?? null,
                            'assigned_to' => $user,
                            'assigned_at' => $now,
                        ]);

                        // para devolver a UI necesitamos el row insertado
                        $row = $this->queue->where('order_id', $oid)->first();
                        if ($row) $claimed[] = $this->toUiRow($o, $row);

                    } catch (\Throwable $e) {
                        // ya asignado por otro usuario, saltar
                        continue;
                    }
                }

                if (!$hasNext || !$after) break;
            }

            return $this->response->setJSON([
                'success' => true,
                'orders' => $claimed,
                'claimed' => count($claimed),
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'PorProducir claim error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST: devolver todo lo asignado
    public function returnAll()
    {
        try {
            $user = $this->currentUser();
            $this->queue->where('assigned_to', $user)->delete();

            return $this->response->setJSON([
                'success' => true,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PorProducir returnAll error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ✅ POST: check enviados (devuelve IDs shopify a remover)
    public function check()
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];
            $ids = $payload['ids'] ?? [];
            if (!is_array($ids) || !$ids) {
                return $this->response->setJSON(['success' => true, 'removed' => []]);
            }

            $ids = array_values(array_unique(array_map('strval', $ids)));

            $user = $this->currentUser();
            $shopOrders = $this->shopify->getOrdersByIds($ids);

            $removed = [];
            foreach ($ids as $sid) {
                $o = $shopOrders[$sid] ?? null;
                if (!$o) continue;
                if ($this->isEnviado($o)) $removed[] = $sid;
            }

            if ($removed) {
                $this->queue->where('assigned_to', $user)->whereIn('order_id', $removed)->delete();
            }

            return $this->response->setJSON([
                'success' => true,
                'removed' => $removed,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'PorProducir check error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
