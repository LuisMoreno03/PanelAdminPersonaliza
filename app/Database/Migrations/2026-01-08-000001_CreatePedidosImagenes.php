<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePedidosImagenes extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT','constraint' => 11,'unsigned' => true,'auto_increment' => true],
            'order_id' => ['type' => 'BIGINT','null' => false],
            'line_index' => ['type' => 'INT','null' => false],
            'url' => ['type' => 'VARCHAR','constraint' => 255,'null' => false],

            'uploaded_by' => ['type' => 'INT','null' => true],
            'uploaded_by_name' => ['type' => 'VARCHAR','constraint' => 120,'null' => true],

            'created_at' => ['type' => 'DATETIME','null' => false],
            'updated_at' => ['type' => 'DATETIME','null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['order_id','line_index'], 'uq_order_line');
        $this->forge->createTable('pedidos_imagenes', true);
    }

    public function down()
    {
        $this->forge->dropTable('pedidos_imagenes', true);
    }
}
