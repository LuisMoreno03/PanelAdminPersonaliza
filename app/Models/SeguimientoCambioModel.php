<?php

namespace App\Models;

use CodeIgniter\Model;

class SeguimientoCambioModel extends Model
{
    protected $table      = 'seguimiento_cambios';
    protected $primaryKey = 'id';
    
    protected $allowedFields = [
        'user_id',
        'entidad',
        'entidad_id',
        'estado_anterior',
        'estado_nuevo',
        'created_at',
    ];

    protected $useTimestamps = false;
}

$seg = new SeguimientoCambioModel();

    $seg->insert([
    'user_id'         => session()->get('user_id'), // o tu método actual
    'entidad'         => 'pedido',
    'entidad_id'      => $pedidoId,
    'estado_anterior' => $estadoAnterior,
    'estado_nuevo'    => $estadoNuevo,
    'created_at'      => date('Y-m-d H:i:s'),
    ]);
