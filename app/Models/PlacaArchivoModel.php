<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model

{
    protected $table            = 'placas_archivos';
    protected $primaryKey       = 'id';
    protected $allowedFields    = [
        'conjunto_id',
        'filename',
        'original_name',
        'mime',
        'size',
        'created_at',
    ];
    protected $useTimestamps    = false; // o true si lo manejas con CI
}


