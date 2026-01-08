<?php

namespace App\Models;

use CodeIgniter\Model;



class PlacaArchivoModel extends Model
{
    protected $table = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'nombre','producto','numero_placa','original','ruta','mime','size',
        'lote_id','lote_nombre','created_at'
    ];

    public $timestamps = false; // o true si tienes created_at/updated_at bien
}

if (!array_key_exists('conjunto_id', $r)) {
    return $this->response
        ->setStatusCode(400)
        ->setBody('Datos incompletos del archivo');
}

$conjuntoId = (int) $r['conjunto_id'];


$required = ['conjunto_id', 'filename'];

foreach ($required as $key) {
    if (!isset($r[$key])) {
        return $this->response
            ->setStatusCode(400)
            ->setBody("Falta el campo {$key}");
    }
}


