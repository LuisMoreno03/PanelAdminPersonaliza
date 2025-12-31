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
    $page  = (int)($this->request->getGet('page_info') ?? 1);
    $limit = (int)($this->request->getGet('limit') ?? 50);

    $page  = max(1, $page);
    $limit = max(1, min(200, $limit));
    $offset = ($page - 1) * $limit;

    $tablaPedidos = 'pedidos';

    // ✅ PON AQUÍ el nombre real del campo de estado en tu tabla:
    $campoEstado = 'estado'; // <-- CAMBIA ESTO si tu columna tiene otro nombre

    // ✅ Estado objetivo para esta sección:
    $estadoObjetivo = 'Preparado';

    $db = \Config\Database::connect();
    $builder = $db->table($tablaPedidos);

    // Filtra por estado (exacto)
    $builder->where($campoEstado, $estadoObjetivo);

    $total = (clone $builder)->countAllResults(false);

    $rows = $builder
        ->orderBy('id', 'DESC')
        ->limit($limit, $offset)
        ->get()
        ->getResultArray();

    $orders = array_map(function ($r) use ($campoEstado) {
        return [
            'id' => $r['id'] ?? ($r['shopify_order_id'] ?? null),
            'numero' => $r['numero'] ?? ($r['order_number'] ?? ($r['name'] ?? '-')),
            'fecha' => $r['fecha'] ?? ($r['created_at'] ?? '-'),
            'cliente' => $r['cliente'] ?? ($r['customer_name'] ?? '-'),
            'total' => $r['total'] ?? ($r['total_price'] ?? '-'),
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
        'next_page_info' => $hasNext ? (string)($page + 1) : null,
    ]);
}


}
