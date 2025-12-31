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
    $limit = 50;

    $page = max(1, $page);
    $offset = ($page - 1) * $limit;

    $db = \Config\Database::connect();

    $tablaPedidos = 'pedidos';
    $campoEstado  = 'estado_pedido'; // ← CAMBIA AQUÍ al nombre REAL
    $estadoObjetivo = 'Preparado';

    $builder = $db->table($tablaPedidos);
    $builder->where($campoEstado, $estadoObjetivo);

    $total = (clone $builder)->countAllResults(false);

    $rows = $builder
        ->orderBy('id', 'DESC')
        ->limit($limit, $offset)
        ->get()
        ->getResultArray();

    $orders = array_map(function ($r) use ($campoEstado) {
        return [
            'id' => $r['id'],
            'numero' => $r['numero'] ?? '-',
            'fecha' => $r['created_at'] ?? '-',
            'cliente' => $r['cliente'] ?? '-',
            'total' => $r['total'] ?? '-',
            'estado' => $r[$campoEstado],
            'etiquetas' => $r['etiquetas'] ?? '',
            'articulos' => $r['articulos'] ?? '',
            'estado_envio' => '',
            'forma_envio' => '',
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
