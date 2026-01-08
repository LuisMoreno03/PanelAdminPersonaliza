<?php

namespace App\Models;

use CodeIgniter\Model;



class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
  
  
  1.  protected $allowedFields = [
        'ruta',
        'original',
        'original_name',
        'filename',
        'nombre',
        'lote_id',
        'conjunto_id',
        'placa_id',
        'user_id',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;

    // ✅ MÉTODO SEGURO (si lo estabas usando)
    public function obtenerTodos(): array
    {
        $r = []; // 🔥 CLAVE: inicializar SIEMPRE

        $rows = $this->orderBy('id', 'DESC')->findAll();

        foreach ($rows as $row) {
            $r[] = $row;
        }

        return $r;
    }
}



if (!array_key_exists('lote_id', $r)) {
    return $this->response
        ->setStatusCode(400)
        ->setBody('Datos incompletos del archivo');
}

$loteId = (int) $r['lote_id'];


$required = ['lote_id', 'filename'];

foreach ($required as $key) {
    if (!isset($r[$key])) {
        return $this->response
            ->setStatusCode(400)
            ->setBody("Falta el campo {$key}");
    }
}


