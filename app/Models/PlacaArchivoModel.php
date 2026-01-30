<?php

namespace App\Models;

use CodeIgniter\Model;

class PlacaArchivoModel extends Model
{
    protected $table            = 'placas_archivos';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    // ✅ Importante: si protectFields=true, SOLO se insertan campos que estén aquí.
    // Si faltan campos como "ruta", te quedará NULL y luego /inline/{id} dará 404.
    protected $protectFields = true;

    protected $allowedFields = [
        // Lote
        'lote_id',
        'lote_nombre',
        'numero_placa',

        // Pedidos vinculados
        'pedidos_json',
        'pedidos_text', // opcional si existe

        // Archivo
        'ruta',         // ✅ OBLIGATORIO para que inline/descargar funcione
        'original',
        'mime',
        'size',
        'nombre',

        // Timestamps (si existen en la tabla)
        'created_at',
        'updated_at',
    ];

    // ✅ No obligamos timestamps porque tu tabla puede o no tenerlos.
    // El controller ya los agrega si existen.
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';

    // (Opcional) Si tu tabla tiene otros campos, puedes añadirlos aquí sin problema,
    // siempre que existan realmente en BD o el controller filtre antes de insertar.
}
