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
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('confirmacion', [
            'etiquetasPredeterminadas' => $this->getEtiquetasUsuario(),
        ]);
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
            ->where('estado', 'Por preparar')
            ->where('assigned_user_id', $this->userId)
            ->orderBy("prioridad = 'express'", 'DESC')
            ->orderBy('created_at', 'ASC')
            ->findAll($limit);

        return $this->response->setJSON([
            'success' => true,
            'orders'  => $orders,
        ]);
    }

    /**
     * POST /confirmacion/pull
     * Trae pedidos NUEVOS desde Shopify
     */
    public function pull()
    {
        $limit = 10;

        // 1️⃣ Obtener pedidos desde Shopify
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

            // 2️⃣ Ya existe en BD?
            $exists = $this->orders
                ->where('shopify_order_id', $o['id'])
                ->first();

            if ($exists) continue;

            // 3️⃣ Detectar prioridad EXPRESS
            $isExpress = false;
            if (!empty($o['tags'])) {
                $tags = strtolower($o['tags']);
                $isExpress = str_contains($tags, 'express');
            }

            // 4️⃣ Insertar pedido
            $this->orders->insert([
                'shopify_order_id'  => $o['id'],
                'numero'            => $o['name'],
                'cliente'           => trim(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? '')),
                'total'             => $o['total_price'],
                'estado'            => 'Por preparar',
                'envio_estado'      => 'Sin preparar',
                'fulfillment_status'=> null,
                'prioridad'         => $isExpress ? 'express' : 'normal',
                'assigned_user_id'  => $this->userId,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            $inserted++;
            if ($inserted >= $limit) break;
        }

        return $this->response->setJSON([
            'success'  => true,
            'inserted' => $inserted,
        ]);
    }
}
