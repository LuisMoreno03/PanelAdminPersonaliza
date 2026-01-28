<?php

namespace App\Models;

use CodeIgniter\Model;

class SeguimientoCambioModel extends Model
{
    protected $table            = 'seguimiento_cambios';
    protected $primaryKey       = 'id';
    protected $allowedFields    = [
        'user_id', 'entidad', 'entidad_id',
        'estado_anterior', 'estado_nuevo', 'created_at'
    ];
    protected $useTimestamps    = false;
}
