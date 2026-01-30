<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table            = 'placas_archivos';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    // ✅ Si tu tabla tiene created_at/updated_at déjalo true.
    // Si NO los tiene, pon false para evitar errores.
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'lote_id',
        'lote_nombre',
        'numero_placa',
        'pedidos_json',
        'pedidos_text',

        'ruta',
        'original',
        'mime',
        'size',
        'nombre',
    ];
}
