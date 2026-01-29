<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table            = 'placas_archivos';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'lote_id',
        'lote_nombre',

        // ✅ meta del lote (guardado como JSON string)
        'pedidos_json',
        'productos_json',

        // ✅ archivo
        'original',
        'nombre',
        'mime',
        'size',
        'ruta',

        // ✅ para mostrar miniatura / principal
        'is_primary',
    ];

    protected $casts = [
        'id'         => 'integer',
        'size'       => 'integer',
        'is_primary' => 'integer',
    ];
}
