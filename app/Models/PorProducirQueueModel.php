<?php

namespace App\Models;

use CodeIgniter\Model;

class PorProducirQueueModel extends Model
{
    protected $table = 'por_producir_queue';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'order_id',
        'order_name',
        'assigned_to',
        'assigned_at',
    ];
}
