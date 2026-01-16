<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidoImagenModel extends Model
{
    protected $table = 'pedido_imagenes';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'order_id',
        'line_index',

        // ✅ columnas que usa tu controller
        'original_url',
        'local_url',
        'status',

        'uploaded_by',
        'uploaded_by_name',

        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;

    /** ✅ UPSERT por order_id (string) + line_index */
    public function upsertImagen(
        string $orderId,
        int $lineIndex,
        string $originalUrl,
        ?string $localUrl,
        string $status,
        ?int $userId,
        ?string $userName
    ): bool {
        $orderId = trim((string)$orderId);
        if ($orderId === '') return false;

        $now = date('Y-m-d H:i:s');

        $row = $this->where('order_id', $orderId)
            ->where('line_index', $lineIndex)
            ->first();

        $data = [
            'original_url'      => $originalUrl,
            'local_url'         => $localUrl,
            'status'            => $status,
            'uploaded_by'       => $userId,
            'uploaded_by_name'  => $userName,
            'updated_at'        => $now,
        ];

        if ($row) {
            return (bool) $this->update($row['id'], $data);
        }

        return (bool) $this->insert([
            'order_id'    => $orderId,
            'line_index'  => $lineIndex,
            'created_at'  => $now,
        ] + $data);
    }

    /** ✅ Devuelve local_url por line_index (order_id string) */
    public function getByOrder(string $orderId): array
    {
        $orderId = trim((string)$orderId);
        if ($orderId === '') return [];

        $rows = $this->where('order_id', $orderId)->findAll();

        $map = [];
        foreach ($rows as $r) {
            $idx = (int)($r['line_index'] ?? -1);
            $url = (string)($r['local_url'] ?? '');
            if ($idx >= 0 && $url !== '') {
                $map[$idx] = $url;
            }
        }

        return $map;
    }
}
