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
        $payload = $this->request->getJSON(true) ?? [];
        $limit = (int)($payload['limit'] ?? 50);
        if (!in_array($limit, [50, 100], true)) $limit = 50;

        $user = $this->currentUser();
        $now = date('Y-m-d H:i:s');

        // 1) Pedimos a Shopify “candidatos” (un poco más para filtrar asignados)
        //    Si tienes MUCHOS pedidos, sube el 300->500.
        $candidates = $this->shopify->searchOrdersByTag($this->TAG_POR_PRODUCIR, 300);

        // 2) Filtramos: no asignados en cola
        $claimed = [];
        foreach ($candidates as $o) {
            if (count($claimed) >= $limit) break;

            // si está enviado, ni lo metemos
            if ($this->isEnviado($o)) continue;

            // intentamos insertar (unique por order_id evita duplicados)
            try {
                $this->queue->insert([
                    'order_id' => (string)$o['id_num'],
                    'order_name' => $o['name'] ?? null,
                    'assigned_to' => $user,
                    'assigned_at' => $now,
                ]);

                $claimed[] = $o;
            } catch (\Throwable $e) {
                // ya estaba asignado por otro usuario, lo saltamos
                continue;
            }
        }

        return $this->response->setJSON([
            'ok' => true,
            'claimed' => count($claimed),
            'data' => $claimed,
        ]);
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
