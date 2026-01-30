<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPedidosJsonToPlacasArchivos extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        if (!$db->fieldExists('pedidos_json', 'placas_archivos')) {
            $this->forge->addColumn('placas_archivos', [
                'pedidos_json' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
            ]);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();

        if ($db->fieldExists('pedidos_json', 'placas_archivos')) {
            $this->forge->dropColumn('placas_archivos', 'pedidos_json');
        }
    }
}
