<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlacasArchivos extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            // ✅ Identificador del lote
            'lote_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => false,
            ],

            // ✅ Nombre visible del lote
            'lote_nombre' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => true,
            ],

            // ✅ Meta (guardado JSON)
            'pedidos_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'productos_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            // ✅ Archivo
            'original' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'nombre' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'mime' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'size' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'ruta' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => false,
            ],

            // ✅ para marcar principal (thumb)
            'is_primary' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'default'  => 0,
            ],

            // timestamps
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('lote_id');
        $this->forge->addKey('created_at');

        $this->forge->createTable('placas_archivos', true);
    }

    public function down()
    {
        $this->forge->dropTable('placas_archivos', true);
    }
}
