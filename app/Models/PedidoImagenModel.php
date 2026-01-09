<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidoImagenModel extends Model
{
    protected $table = 'pedidos_imagenes';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'order_id','line_index','url',
        'uploaded_by','uploaded_by_name',
        'created_at','updated_at'
    ];

    protected $useTimestamps = false;

    public function upsertImagen(int $orderId, int $lineIndex, string $url, ?int $userId, ?string $userName): bool
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->where('order_id', $orderId)
                    ->where('line_index', $lineIndex)
                    ->first();

        if ($row) {
            return (bool) $this->update($row['id'], [
                'url' => $url,
                'uploaded_by' => $userId,
                'uploaded_by_name' => $userName,
                'updated_at' => $now,
            ]);
        }

        return (bool) $this->insert([
            'order_id' => $orderId,
            'line_index' => $lineIndex,
            'url' => $url,
            'uploaded_by' => $userId,
            'uploaded_by_name' => $userName,
            'created_at' => $now,
        ]);
    }

    public function getByOrder(int $orderId): array
    {
        $rows = $this->where('order_id', $orderId)->findAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['line_index']] = $r['url'];
        }
        return $map;
    }
}
