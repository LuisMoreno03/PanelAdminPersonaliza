<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderStatusHistoryModel extends Model
{
    protected $table = 'order_status_history';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'order_id','prev_estado','nuevo_estado',
        'user_id','user_name','ip','user_agent','created_at'
    ];
    public $useTimestamps = false;
}
