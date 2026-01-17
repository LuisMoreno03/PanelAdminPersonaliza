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
        'estado_updated_at',
        'estado_updated_by',
        'estado_updated_by_name',
    ];
    protected $useTimestamps = false;

    /** ✅ Leer estado actual (para NO pisar manual con "Sistema") */
   public function getEstadoPedido(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') return null;

        return $this->where('order_id', $orderId)->first();
    }

    /** ✅ Guarda el ESTADO GENERAL del pedido */
    public function setEstadoPedido(string $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '' || $orderId === '0') return false;

        $now = date('Y-m-d H:i:s');

        $data = [
            'order_id' => $orderId,
            'estado' => $estado,
            // 👇 estos 2 SIEMPRE
            'actualizado' => $now,
            'estado_updated_at' => $now,
            // 👇 tracking
            'estado_updated_by' => $userId,
            'estado_updated_by_name' => $userName,
        ];

        $row = $this->select('id')->where('order_id', $orderId)->first();

        if ($row && isset($row['id'])) {
            return (bool) $this->update((int)$row['id'], $data);
        }

        return (bool) $this->insert($data);
    }
    
    /** ✅ Obtiene el ÚLTIMO estado por order_id */
    public function getEstadosForOrderIds(array $orderIds): array
    {
        if (!$orderIds) return [];

        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds))));
        if (!$orderIds) return [];

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
