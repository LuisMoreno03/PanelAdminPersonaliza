<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidosEstadoModel extends Model
{
    protected $table = 'pedidos_estado';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'order_id',

        // ✅ estado general del pedido
        'estado',
        'estado_updated_at',
        'estado_updated_by',
        'estado_updated_by_name',

        // ✅ estado de imágenes (lo que ya tenías)
        'estado_imagenes',
        'imagenes_updated_at',
        'imagenes_updated_by',
        'imagenes_updated_by_name',
    ];

    protected $useTimestamps = false;

    /** ✅ Guarda el ESTADO GENERAL del pedido */
    public function setEstadoPedido(int $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->where('order_id', $orderId)->first();

        $data = [
            'estado' => $estado,
            'estado_updated_at' => $now,
            'estado_updated_by' => $userId,
            'estado_updated_by_name' => $userName,
        ];

        if ($row) {
            return (bool) $this->update($row['id'], $data);
        }

        return (bool) $this->insert(['order_id' => $orderId] + $data);
    }

    /** (lo tuyo) Guarda el ESTADO DE IMÁGENES */
    public function setEstadoImagenes(int $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->where('order_id', $orderId)->first();

        $data = [
            'estado_imagenes' => $estado,
            'imagenes_updated_at' => $now,
            'imagenes_updated_by' => $userId,
            'imagenes_updated_by_name' => $userName,
        ];

        if ($row) {
            return (bool) $this->update($row['id'], $data);
        }

        return (bool) $this->insert(['order_id' => $orderId] + $data);
    }

    /** ✅ Obtiene override de estado para un set de order_ids */
    public function getEstadosForOrderIds(array $orderIds): array
    {
        if (!$orderIds) return [];

        $rows = $this->select('order_id, estado, estado_updated_at, estado_updated_by, estado_updated_by_name')
            ->whereIn('order_id', $orderIds)
            ->findAll();

        $map = [];
        foreach ($rows as $r) {
            $oid = (string)($r['order_id'] ?? '');
            if (!$oid) continue;
            $map[$oid] = $r;
        }
        return $map;
    }
}
