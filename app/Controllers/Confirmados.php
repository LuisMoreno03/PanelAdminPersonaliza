<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class Confirmados extends BaseController
{
    public function index()
    {
        return view('confirmados');
    }

    public function filter()
{
    // Si tu JS manda page_info, lo usamos como "page" (1,2,3...)
    $page = (int)($this->request->getGet('page_info') ?? 1);
    $limit = (int)($this->request->getGet('limit') ?? 50);

    $page = max(1, $page);
    $limit = max(1, min(200, $limit));
    $offset = ($page - 1) * $limit;

    // ✅ CAMBIA ESTO:
    $tablaPedidos = 'pedidos';        // <--- tu tabla real (ej: pedidos, ordenes, shopify_pedidos, etc.)
    $campoEstado  = 'estado_interno'; // <--- tu campo real (ej: estado, estado_interno, estatus, status_interno, etc.)

    // ✅ Estado que quieres mostrar en "Confirmados"
    $estadoObjetivo = 'Preparado';

    $db = \Config\Database::connect();
    $builder = $db->table($tablaPedidos);

    // Trae SOLO preparados (case-insensitive)
    $builder->where("LOWER($campoEstado)", strtolower($estadoObjetivo));

    // Total para paginación
    $total = (clone $builder)->countAllResults(false);

    // Datos
    $rows = $builder
        ->orderBy('id', 'DESC') // si tienes created_at mejor: ->orderBy('created_at','DESC')
        ->limit($limit, $offset)
        ->get()
        ->getResultArray();

    // Mapea al formato que tu tabla frontend espera
    $orders = array_map(function ($r) use ($campoEstado) {
        return [
            'id' => $r['id'] ?? ($r['shopify_order_id'] ?? null),
            'numero' => $r['numero'] ?? ($r['order_number'] ?? ($r['name'] ?? '-')),
            'fecha' => $r['fecha'] ?? ($r['created_at'] ?? '-'),
            'cliente' => $r['cliente'] ?? ($r['customer_name'] ?? '-'),
            'total' => $r['total'] ?? ($r['total_price'] ?? '-'),
            // ✅ estado interno
            'estado' => $r[$campoEstado] ?? '-',
            'etiquetas' => $r['etiquetas'] ?? ($r['tags'] ?? ''),
            'articulos' => $r['articulos'] ?? ($r['items_count'] ?? '-'),
            'estado_envio' => $r['estado_envio'] ?? '',
            'forma_envio' => $r['forma_envio'] ?? '',
        ];
    }, $rows);

    $hasNext = ($offset + $limit) < $total;

    return $this->response->setJSON([
        'success' => true,
        'orders' => $orders,
        // seguimos usando page_info como "page"
        'next_page_info' => $hasNext ? (string)($page + 1) : null,
    ]);
}

}
