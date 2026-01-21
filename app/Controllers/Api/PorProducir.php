<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\PorProducirQueueModel;
use App\Services\ShopifyService;

class PorProducir extends BaseController
{
    private PorProducirQueueModel $queue;
    private ShopifyService $shopify;

    // ✅ AJUSTA ESTO a tu realidad
    private string $TAG_POR_PRODUCIR = 'Por producir';
    private string $TAG_ENVIADO = 'Enviado'; // opcional (por si marcas enviado con tag)

    public function __construct()
    {
        $this->queue = new PorProducirQueueModel();
        $this->shopify = new ShopifyService();
    }

    private function currentUser(): string
    {
        return (string) (session()->get('nombre') ?? 'Sistema');
    }

    public function mine()
    {
        $user = $this->currentUser();

        $rows = $this->queue->where('assigned_to', $user)
            ->orderBy('assigned_at', 'ASC')
            ->findAll();

        $ids = array_values(array_unique(array_map(fn($r) => (string)$r['order_id'], $rows)));
        $orders = $ids ? $this->shopify->getOrdersByIds($ids) : [];

        return $this->response->setJSON([
            'ok' => true,
            'data' => $orders,
        ]);
    }

    public function claim()
{
    try {
        $payload = $this->request->getJSON(true) ?? [];
        $limit = (int)($payload['limit'] ?? 50);
        if (!in_array($limit, [50, 100], true)) $limit = 50;

        $user = $this->currentUser();
        $now  = date('Y-m-d H:i:s');

        $claimed = [];

        $after = null;
        $tries = 0;

        // ✅ Loop: seguimos pidiendo páginas hasta llenar el cupo o no haya más
        while (count($claimed) < $limit && $tries < 20) {
            $tries++;

            // ✅ IMPORTANTE: status:any para que no falten pedidos
            $page = $this->shopify->searchOrdersByQueryPage(
                'tag:"Por producir" status:any',
                100,
                $after
            );

            $orders = $page['orders'] ?? [];
            $after  = $page['endCursor'] ?? null;
            $hasNext = (bool)($page['hasNextPage'] ?? false);

            foreach ($orders as $o) {
                if (count($claimed) >= $limit) break;

                // si ya está enviado, no lo metas
                if ($this->isEnviado($o)) continue;

                // insert único para que no se pisen usuarios
                try {
                    $this->queue->insert([
                        'order_id'    => (string)$o['id_num'],
                        'order_name'  => $o['name'] ?? null,
                        'assigned_to' => $user,
                        'assigned_at' => $now,
                    ]);
                    $claimed[] = $o;
                } catch (\Throwable $e) {
                    // duplicado / ya asignado -> saltar
                    continue;
                }
            }

            if (!$hasNext) break;
            if (!$after) break;
        }

        return $this->response->setJSON([
            'ok' => true,
            'claimed' => count($claimed),
            'data' => $claimed,
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'PorProducir claim error: ' . $e->getMessage());
        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'error' => $e->getMessage(), // en prod puedes ocultarlo
        ]);
    }
}


    public function returnAll()
    {
        $user = $this->currentUser();
        $this->queue->where('assigned_to', $user)->delete();

        return $this->response->setJSON([
            'ok' => true,
        ]);
    }

    public function check()
    {
        $payload = $this->request->getJSON(true) ?? [];
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            return $this->response->setJSON(['ok' => true, 'removed' => []]);
        }

        $ids = array_values(array_unique(array_map('strval', $ids)));
        $orders = $this->shopify->getOrdersByIds($ids);

        $remove = [];
        foreach ($orders as $o) {
            if ($this->isEnviado($o)) {
                $remove[] = (string)$o['id_num'];
            }
        }

        if ($remove) {
            $user = $this->currentUser();
            $this->queue->where('assigned_to', $user)->whereIn('order_id', $remove)->delete();
        }

        return $this->response->setJSON([
            'ok' => true,
            'removed' => $remove,
        ]);
    }

    private function isEnviado(array $o): bool
    {
        // ✅ Shopify fulfillment
        if (($o['fulfillment_status'] ?? '') === 'FULFILLED') return true;

        // ✅ Si también lo marcas por tag
        $tags = $o['tags'] ?? [];
        if (is_string($tags)) $tags = array_map('trim', explode(',', $tags));
        if (in_array($this->TAG_ENVIADO, $tags, true)) return true;

        return false;
    }
}
