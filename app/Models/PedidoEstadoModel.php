<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidosEstadoModel extends Model
{
    protected $table = 'pedidos_estado';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'order_id',
        'estado_imagenes',
        'imagenes_updated_at',
        'imagenes_updated_by',
        'imagenes_updated_by_name',
    ];
    protected $useTimestamps = false;

    public function setEstadoImagenes(int $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->where('order_id', $orderId)->first();

        if ($row) {
            return (bool) $this->update($row['id'], [
                'estado_imagenes' => $estado,
                'imagenes_updated_at' => $now,
                'imagenes_updated_by' => $userId,
                'imagenes_updated_by_name' => $userName,
            ]);
        }

        return (bool) $this->insert([
            'order_id' => $orderId,
            'estado_imagenes' => $estado,
            'imagenes_updated_at' => $now,
            'imagenes_updated_by' => $userId,
            'imagenes_updated_by_name' => $userName,
        ]);
    }
}
