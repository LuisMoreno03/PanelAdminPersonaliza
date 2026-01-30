<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';

    /**
     * ✅ Si tu tabla tiene columnas created_at y updated_at, déjalo en true.
     * Si NO las tiene, cámbialo a false.
     */
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * ✅ Formato datetime estándar (ej: 2026-01-29 09:50:13)
     */
    protected $dateFormat = 'datetime';

    /**
     * ✅ Lista de campos que tu app puede manejar.
     * OJO: Aunque pongas campos que aún no existen en BD, NO se romperá
     * porque stripUnknownColumns los elimina antes del INSERT/UPDATE.
     */
    protected $allowedFields = [
        'lote_id',
        'lote_nombre',

        'numero_placa',
        'producto',

        // ✅ pedidos/productos asociados
        'pedidos_json',
        'pedidos_text',

        // ✅ archivo
        'ruta',
        'thumb_ruta',
        'original',
        'mime',
        'size',
        'nombre',

        // ✅ flags opcionales
        'is_primary',

        // timestamps
        'created_at',
        'updated_at',
    ];

    /**
     * ✅ Evita errores "Unknown column X" al insertar/actualizar
     * eliminando automáticamente claves que NO existen en la tabla real.
     */
    protected $beforeInsert = ['stripUnknownColumns'];
    protected $beforeUpdate = ['stripUnknownColumns'];

    protected function stripUnknownColumns(array $data): array
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return $data;
        }

        $db = db_connect();

        // cachea las columnas reales de la tabla para no consultarlas siempre
        static $colsByTable = [];

        if (!isset($colsByTable[$this->table])) {
            $colsByTable[$this->table] = array_flip($db->getFieldNames($this->table));
        }

        $validCols = $colsByTable[$this->table];

        // deja solo columnas que existan realmente
        $data['data'] = array_intersect_key($data['data'], $validCols);

        return $data;
    }
}
