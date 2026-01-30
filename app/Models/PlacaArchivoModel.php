<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // ✅ NO asumimos timestamps para evitar 500 si tu tabla no los tiene
    protected $useTimestamps = false;

    protected $allowedFields = [
        'lote_id',
        'lote_nombre',
        'numero_placa',

        // pedidos asociados al lote/placa
        'pedidos_json',
        'pedidos_text',

        // archivo
        'ruta',
        'original',
        'mime',
        'size',
        'nombre',

        // ✅ si existen en tu tabla, se guardan; si no, simplemente se ignoran
        'created_at',
        'updated_at',
    ];
}
 