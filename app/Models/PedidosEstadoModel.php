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

    // =========================
    // Confirmados / filtros
    // =========================
    public function getOrderIdsByEstado(string $estado, int $limit, int $offset): array
    {
        $estadoNorm = mb_strtolower(trim($estado));

        $rows = $this->db->table($this->table)
            ->select('order_id')
            // ✅ IMPORTANTE: escape=false para que LOWER/TRIM se ejecute
            ->where("LOWER(TRIM(estado)) = '{$estadoNorm}'", null, false)
            ->orderBy('COALESCE(estado_updated_at, actualizado)', 'DESC', false)
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return array_values(array_filter(array_map(
            fn($r) => isset($r['order_id']) ? trim((string)$r['order_id']) : null,
            $rows
        )));
    }

    public function countByEstado(string $estado): int
    {
        $estadoNorm = mb_strtolower(trim($estado));

        return (int) $this->db->table($this->table)
            ->where("LOWER(TRIM(estado)) = '{$estadoNorm}'", null, false)
            ->countAllResults();
    }

    // =========================
    // Lectura estado
    // =========================
    public function getEstadoPedido(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '' || $orderId === '0') return null;

        return $this->where('order_id', $orderId)->first();
    }

    // =========================
    // Guardar estado (UPSERT)
    // =========================
    public function setEstadoPedido(string $orderId, string $estado, ?int $userId, ?string $userName): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '' || $orderId === '0') return false;

        $now = date('Y-m-d H:i:s');

        $data = [
            'order_id'               => $orderId,
            'estado'                 => trim($estado),
            'estado_updated_by'      => $userId,
            'estado_updated_by_name' => $userName ?: 'Sistema',
            'estado_updated_at'      => $now,
            'actualizado'            => $now, // ✅ MUY IMPORTANTE para tu fallback
        ];

        $row = $this->select('id')->where('order_id', $orderId)->first();

        if ($row && isset($row['id'])) {
            return (bool) $this->update((int)$row['id'], $data);
        }

        return (bool) $this->insert($data);
    }

    // =========================
    // Mapa estados por IDs
    // =========================
    public function getEstadosForOrderIds(array $orderIds): array
    {
        if (!$orderIds) return [];

        $orderIds = array_values(array_unique(array_filter(array_map(function ($v) {
            $s = trim((string)$v);
            return ($s !== '' && $s !== '0') ? $s : null;
        }, $orderIds))));

        if (!$orderIds) return [];

        $rows = $this->select('order_id, estado, actualizado, estado_updated_at, estado_updated_by, estado_updated_by_name')
            ->whereIn('order_id', $orderIds)
            ->orderBy('COALESCE(estado_updated_at, actualizado)', 'DESC', false)
            ->findAll();

        $map = [];
        foreach ($rows as $r) {
            $oid = trim((string)($r['order_id'] ?? ''));
            if ($oid === '') continue;
            if (!isset($map[$oid])) $map[$oid] = $r; // ✅ se queda con el más nuevo
        }

        return $map;
    }
}
