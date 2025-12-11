<?php
namespace App\Models;

use CodeIgniter\Model;

class PedidoModel extends Model
{
    protected $table      = 'pedidos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
}
