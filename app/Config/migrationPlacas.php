<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDiaToPlacasArchivos extends Migration
{
    public function up()
    {
        $this->forge->addColumn('placas_archivos', [
            'dia' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'size'
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('placas_archivos', 'dia');
    }
}
