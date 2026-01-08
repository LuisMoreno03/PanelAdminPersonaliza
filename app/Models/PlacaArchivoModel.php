<?php

namespace App\Models;

use CodeIgniter\Model;



class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
    'ruta',
    'original',
    'original_name',
    'filename',
    'nombre',

    'lote_id',
    'lote_nombre',
    'conjunto_id',
    'placa_id',

    'producto',
    'numero_placa',
    'mime',
    'size',

    'user_id',
    'created_at',
    'updated_at',
];

    protected $useTimestamps = false;


    public function obtenerTodos(): array
    {
        return $this->orderBy('id', 'DESC')->findAll();
    }
}
