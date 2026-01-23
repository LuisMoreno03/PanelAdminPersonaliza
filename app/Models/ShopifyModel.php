<?php

namespace App\Models;

use CodeIgniter\Model;

class ShopModel extends Model
{
    protected $table      = 'shops';
    protected $primaryKey = 'id';
    protected $allowedFields = ['shop', 'access_token', 'created_at', 'updated_at'];
    protected $useTimestamps = true;

    public function upsertToken(string $shop, string $token): void
    {
        $row = $this->where('shop', $shop)->first();
        if ($row) {
            $this->update($row['id'], ['access_token' => $token]);
        } else {
            $this->insert(['shop' => $shop, 'access_token' => $token]);
        }
    }

    public function getToken(string $shop): ?string
    {
        $row = $this->where('shop', $shop)->first();
        return $row['access_token'] ?? null;
    }
}
