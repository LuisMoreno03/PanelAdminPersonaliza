<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidoEstadoModel extends Model
{
    protected $table = 'pedidos_estado';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'pedido_id',
        'estado',
        'user_id',
        'created_at'
    ];

    public function getLastStatusChange($pedidoId)
    {
        return $this->select('pedidos_estado.created_at AS changed_at, users.nombre AS user_name')
            ->join('users', 'users.id = pedidos_estado.user_id', 'left')
            ->where('pedidos_estado.pedido_id', $pedidoId)
            ->orderBy('pedidos_estado.created_at', 'DESC')
            ->first();

            $estadoModel = new PedidoEstadoModel();

foreach ($pedidos as &$pedido) {

    $last = $estadoModel->getLastStatusChange($pedido['id']);

    if ($last) {
        $pedido['last_status_change'] = [
            'user_name'  => $last['user_name'] ?? 'Sistema',
            'changed_at' => $last['changed_at']
        ];
    } else {
        // Si nunca se modificó desde tu sistema
        $pedido['last_status_change'] = [
            'user_name'  => 'Shopify',
            'changed_at' => $pedido['created_at']
        ];
    }
}

    }
}
