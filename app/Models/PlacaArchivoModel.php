<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table = 'placas_archivos';
    protected $primaryKey = 'id';
    protected $allowedFields = ['nombre','original','ruta','mime','size','dia'];
    protected $useTimestamps = true;
}

