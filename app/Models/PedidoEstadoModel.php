<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidosEstadoModel extends Model
{
    protected $table = 'pedidos_estado';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'order_id',
        'estado',
        'actualizado',

        // si existen en tu tabla, déjalos:
        'estado_updated_at',
        'estado_updated_by',
        'estado_updated_by_name',

        'estado_imagenes',
        'imagenes_updated_at',
        'imagenes_updated_by',
        'imagenes_updated_by_name',
    ];

    protected $useTimestamps = false;

    /** ✅ Guarda el ESTADO GENERAL del pedido */
    public function setEstadoPedido(string $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '') return false;

        $now = date('Y-m-d H:i:s');

        $row = $this->where('order_id', $orderId)->first();

        $data = [
            'estado' => $estado,

            // tu columna vieja:
            'actualizado' => $now,

            // tus columnas nuevas (si existen):
            'estado_updated_at' => $now,
            'estado_updated_by' => $userId,
            'estado_updated_by_name' => $userName,
        ];

        if ($row) {
            return (bool) $this->update($row['id'], $data);
        }

        return (bool) $this->insert(['order_id' => $orderId] + $data);
    }

    /** ✅ Obtiene el ÚLTIMO estado por order_id */
    public function getEstadosForOrderIds(array $orderIds): array
    {
        if (!$orderIds) return [];

        $orderIds = array_values(array_unique(array_map('strval', $orderIds)));

        $rows = $this->select('order_id, estado, actualizado, estado_updated_at, estado_updated_by, estado_updated_by_name')
            ->whereIn('order_id', $orderIds)
            ->orderBy('COALESCE(estado_updated_at, actualizado)', 'DESC', false)
            ->findAll();

        $map = [];
        foreach ($rows as $r) {
            $oid = (string)($r['order_id'] ?? '');
            if ($oid === '') continue;
            if (!isset($map[$oid])) $map[$oid] = $r;
        }

        return $map;
    }
}
