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
        $estado = $this->request->getGet('estado') ?? 'Preparado'; // Preparado / Todos
        $page   = (int)($this->request->getGet('page') ?? 1);
        $limit  = (int)($this->request->getGet('limit') ?? 50);

        $page = max(1, $page);
        $limit = max(1, min(200, $limit));
        $offset = ($page - 1) * $limit;

        $db = \Config\Database::connect();
        $builder = $db->table('orders');

        if (strtolower($estado) !== 'todos') {
            // por estado o por etiqueta que contenga "preparado"
            $builder->groupStart()
                ->where('LOWER(estado)', 'preparado')
                ->orLike('LOWER(etiquetas)', 'preparado')
            ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder
            ->orderBy('fecha', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        $orders = array_map(function($r){
            return [
                'id' => $r['shopify_id'] ?? $r['id'],
                'numero' => $r['numero'] ?? '-',
                'fecha' => $r['fecha'] ?? '-',
                'cliente' => $r['cliente'] ?? '-',
                'total' => $r['total'] ?? '-',
                'estado' => $r['estado'] ?? '-',
                'etiquetas' => $r['etiquetas'] ?? '',
                'articulos' => $r['articulos'] ?? 0,
                'estado_envio' => $r['estado_envio'] ?? '',
                'forma_envio' => $r['forma_envio'] ?? ''
            ];
        }, $rows);

        $hasNext = ($offset + $limit) < $total;

        return $this->response->setJSON([
            'success' => true,
            'orders' => $orders,
            // usamos page_info como número de página (simple y funciona)
            'next_page_info' => $hasNext ? (string)($page + 1) : null,
        ]);
    }
}
