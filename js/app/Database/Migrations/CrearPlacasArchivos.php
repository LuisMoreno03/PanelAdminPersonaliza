<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlacasArchivos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
            'original' => ['type' => 'VARCHAR', 'constraint' => 255],
            'ruta' => ['type' => 'VARCHAR', 'constraint' => 500],
            'mime' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'size' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('placas_archivos');
    }

    public function down()
    {
        $this->forge->dropTable('placas_archivos');
    }
}
