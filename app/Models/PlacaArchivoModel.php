<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    // ✅ timestamps
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * ✅ Campos permitidos en INSERT/UPDATE
     * - productos_json y pedidos_json: se guardan como JSON string
     * - productos y pedidos: el JS los manda así, y aquí los convertimos a *_json
     */
     protected $allowedFields = [
        'lote_id',
        'lote_nombre',
        'numero_placa',
        'producto',
        'pedidos_json',
        'pedidos_text',
        'ruta',
        'original',
        'mime',
        'size',
        'nombre',
        'created_at',
        'updated_at',
        'thumb_ruta',
        'is_primary', // ✅ si no existe en BD, se eliminará automáticamente
    ];


    // ✅ Convierte productos/pedidos en productos_json/pedidos_json automáticamente
    protected $beforeInsert = ['normalizeMeta'];
    protected $beforeUpdate = ['normalizeMeta'];

    protected function normalizeMeta(array $data): array
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return $data;
        }

        $row = &$data['data'];

        // Normaliza lote_id siempre a string si llega
        if (isset($row['lote_id'])) {
            $row['lote_id'] = (string) $row['lote_id'];
        }

        // ✅ productos => productos_json
        if (array_key_exists('productos', $row)) {
            $row['productos_json'] = $this->toJsonString($row['productos']);
            unset($row['productos']);
        }
        if (isset($row['productos_json']) && is_array($row['productos_json'])) {
            $row['productos_json'] = $this->toJsonString($row['productos_json']);
        }

        // ✅ pedidos => pedidos_json
        if (array_key_exists('pedidos', $row)) {
            $row['pedidos_json'] = $this->toJsonString($row['pedidos']);
            unset($row['pedidos']);
        }
        if (isset($row['pedidos_json']) && is_array($row['pedidos_json'])) {
            $row['pedidos_json'] = $this->toJsonString($row['pedidos_json']);
        }

        // defaults seguros
        if (!isset($row['is_primary']) || $row['is_primary'] === '') {
            $row['is_primary'] = 0;
        }

        return $data;
    }

    private function toJsonString($val): ?string
    {
        if ($val === null) return null;

        // ya viene JSON string
        if (is_string($val)) {
            $s = trim($val);
            if ($s === '') return '[]';

            // si ya es JSON válido, lo dejamos
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }

            // si viene "a,b,c" => array
            $arr = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $s))));
            return json_encode($arr, JSON_UNESCAPED_UNICODE);
        }

        // array => JSON
        if (is_array($val)) {
            return json_encode(array_values($val), JSON_UNESCAPED_UNICODE);
        }

        // cualquier otro tipo => string
        return json_encode([(string) $val], JSON_UNESCAPED_UNICODE);
    }
}
