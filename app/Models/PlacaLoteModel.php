<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaLoteModel extends Model
{
    protected $table = 'placas_lotes';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'fecha',
        'uploaded_by',
        'uploaded_by_name',
        'created_at',
    ];

    protected $useTimestamps = false;
}
