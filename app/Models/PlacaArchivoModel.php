<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model

{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'nombre',
        'producto',
        'numero_placa',
        'original',
        'ruta',
        'mime',
        'size',
        'lote_id',
        'lote_nombre',
        'created_at',
    ];
}


    protected $useTimestamps    = false; // o true si lo manejas con CI



