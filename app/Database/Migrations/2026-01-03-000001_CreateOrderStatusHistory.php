<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderStatusHistory extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'order_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'prev_estado' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'nuevo_estado' => ['type' => 'VARCHAR', 'constraint' => 80],
            'user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'user_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'ip' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('order_id');
        $this->forge->createTable('order_status_history', true);
    }

    public function down()
    {
        $this->forge->dropTable('order_status_history', true);
    }
}
