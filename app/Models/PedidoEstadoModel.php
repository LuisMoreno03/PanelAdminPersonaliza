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
        'estado_updated_at',
        'estado_updated_by',
        'estado_updated_by_name',
        'estado_imagenes',
        'imagenes_updated_at',
        'imagenes_updated_by',
        'imagenes_updated_by_name',
    ];

    protected $useTimestamps = false;

    /** ✅ Guarda el ESTADO GENERAL del pedido (orderId = string) */
    public function setEstadoPedido(string $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '') return false;

        $now = date('Y-m-d H:i:s');
        $row = $this->where('order_id', $orderId)->first();

        $data = [
            'estado' => $estado,
            'estado_updated_at' => $now,
            'estado_updated_by' => $userId,
            'estado_updated_by_name' => $userName,
        ];

        if ($row) return (bool) $this->update($row['id'], $data);
        return (bool) $this->insert(['order_id' => $orderId] + $data);
    }
    /** ✅ Guarda el ESTADO DE IMÁGENES (orderId = string) */
    public function setEstadoImagenes(string $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $orderId = trim((string)$orderId);
        if ($orderId === '') return false;

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

    /** ✅ Obtiene el ÚLTIMO estado por order_id (ids = string[]) */
    public function getEstadosForOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds))));
        if (!$orderIds) return [];

        $rows = $this->select('order_id, estado, estado_updated_at, estado_updated_by, estado_updated_by_name')
            ->whereIn('order_id', $orderIds)
            ->orderBy('estado_updated_at', 'DESC')
            ->findAll();

        $map = [];
        foreach ($rows as $r) {
            $oid = (string)($r['order_id'] ?? '');
            if ($oid !== '' && !isset($map[$oid])) $map[$oid] = $r;
        }
        return $map;
    }
    /** ✅ (Opcional pero recomendado) Trae un estado por order_id */
    public function getEstadoPedido(string $orderId): ?array
    {
        $orderId = trim((string)$orderId);
        if ($orderId === '') return null;

        return $this->where('order_id', $orderId)->first();
    }
}