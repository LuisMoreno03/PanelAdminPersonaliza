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
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            // ✅ lote/conjunto
            'lote_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => false,
            ],
            'lote_nombre' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],

            // opcional: número de placa / nota
            'numero_placa' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],

            // archivo
            'ruta' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'mime' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'size' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => 0,
            ],

            // nombres
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

            // ✅ marca principal (para thumbnail del lote)
            'is_primary' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],

            // ✅ meta guardada por lote/placa (se repite por fila, pero sirve para listar rápido)
            'productos_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'pedidos_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],

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

        // índices útiles
        $this->forge->addKey('lote_id');
        $this->forge->addKey('created_at');

        $this->forge->createTable('placas_archivos', true);
    }

    public function down()
    {
        $this->forge->dropTable('placas_archivos', true);
    }
}
