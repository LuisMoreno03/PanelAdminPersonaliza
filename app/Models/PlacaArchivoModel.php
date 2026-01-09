<?php

namespace App\Models;

use CodeIgniter\Model;



class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
  'nombre','producto','numero_placa','original','ruta','mime','size',
  'lote_id','lote_nombre','user_id','created_at'
];




    protected $useTimestamps = false;


    public function obtenerTodos(): array
    {
        return $this->orderBy('id', 'DESC')->findAll();
    }
}
