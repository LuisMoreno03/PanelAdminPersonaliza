<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\PedidoModel as OrderModel;
use App\Services\ShopifyService;

class ConfirmacionController extends BaseController
{
    protected $orders;
    protected $shopify;
    protected $userId;
    public function index()
    {
        return view('confirmacion');
    }
    public function __construct()
    {
        $this->orders   = new OrderModel();
        $this->shopify  = new ShopifyService();
        $this->userId   = session()->get('user_id');
    }

    /**
     * GET /confirmacion/my-queue
     * Cola del usuario actual
     */
    public function myQueue()
    {
        $limit = (int) ($this->request->getGet('limit') ?? 10);

        $orders = $this->orders
            ->select('id, shopify_order_id, numero, cliente, total, estado, created_at')
            ->where('estado', 'Por preparar')
            ->where('assigned_user_id', $this->userId)
            ->orderBy("prioridad = 'express'", 'DESC')
            ->orderBy('created_at', 'ASC')
            ->findAll($limit);

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders
        ]);
    }


    /**
     * POST /confirmacion/pull
     * Trae pedidos NUEVOS desde Shopify
     */
    public function pull()
    {
        $limit = 10;

        $shopifyOrders = $this->shopify->getOrders([
            'financial_status'   => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'limit'              => 50,
        ]);

        if (!$shopifyOrders) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'No se pudieron obtener pedidos de Shopify'
            ]);
        }

        $inserted = 0;

        foreach ($shopifyOrders as $o) {

            // ⛔️ Evitar duplicados
            if ($this->orders->where('shopify_order_id', $o['id'])->first()) {
                continue;
            }

            // ✅ Cliente seguro
            $cliente = '—';
            if (!empty($o['customer'])) {
                $cliente = trim(
                    ($o['customer']['first_name'] ?? '') . ' ' .
                    ($o['customer']['last_name'] ?? '')
                );
            }

            // ✅ Prioridad EXPRESS
            $prioridad = 'normal';
            if (!empty($o['tags']) && stripos($o['tags'], 'express') !== false) {
                $prioridad = 'express';
            }

            // ✅ Insert LIMPIO (solo strings / números)
            $this->orders->insert([
                'shopify_order_id'   => (string) $o['id'],
                'numero'             => (string) ($o['name'] ?? ''),
                'cliente'            => $cliente,
                'total'              => (float) ($o['total_price'] ?? 0),
                'estado'             => 'Por preparar',
                'envio_estado'       => 'Sin preparar',
                'prioridad'          => $prioridad,
                'assigned_user_id'   => $this->userId,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);

            $inserted++;
            if ($inserted >= $limit) break;
        }

        return $this->response->setJSON([
            'success'  => true,
            'inserted' => $inserted
        ]);
    }

}
