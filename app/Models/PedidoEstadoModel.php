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

        // ✅ estado de imágenes
        'estado_imagenes',
        'imagenes_updated_at',
        'imagenes_updated_by',
        'imagenes_updated_by_name',
    ];

    protected $useTimestamps = false;

    /** ✅ Guarda el ESTADO GENERAL del pedido (orderId = string) */
    public function setEstadoPedido(string $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $orderId = trim((string)$orderId);
        if ($orderId === '') return false;

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
        if (!$orderIds) return [];

        // normalizar strings y eliminar vacíos
        $ids = [];
        foreach ($orderIds as $id) {
            $id = trim((string)$id);
            if ($id !== '') $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) return [];

        // ✅ Ordena por la fecha más reciente de update
        $rows = $this->select('order_id, estado, estado_updated_at, estado_updated_by, estado_updated_by_name')
            ->whereIn('order_id', $ids)
            ->orderBy('estado_updated_at', 'DESC')
            ->findAll();

        // ✅ Como vienen DESC, nos quedamos con la primera fila de cada order_id
        $map = [];
        foreach ($rows as $r) {
            $oid = (string)($r['order_id'] ?? '');
            if ($oid === '') continue;

            if (!isset($map[$oid])) {
                $map[$oid] = $r;
            }
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
