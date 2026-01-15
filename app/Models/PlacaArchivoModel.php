<?php

namespace App\Models;

use CodeIgniter\Model;



class PlacaArchivoModel extends Model
{
    protected $table      = 'placas_archivos';
    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        // archivo
        'nombre',
        'original',
        'ruta',
        'mime',
        'size_kb', 

        // agrupación
        'fecha',
        'lote_nombre',
        'lote_id',


        // usuario
        'uploaded_by',
        'uploaded_by_name',

        // sistema
        'created_at',
    ];

    protected $useTimestamps = false;

    // ===============================
    // Obtener todos (fallback)
    // ===============================
    public function obtenerTodos(): array
    {
        return $this->orderBy('id', 'DESC')->findAll();
    }

    // ===============================
    // Obtener por día (Drive-like)
    // ===============================
    public function obtenerPorFecha(): array
    {
        return $this->orderBy('fecha', 'DESC')
                    ->orderBy('lote_id', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->findAll();
    }

    // ===============================
    // Obtener por lote
    // ===============================
    public function obtenerPorLote(int $loteId): array
    {
        return $this->where('lote_id', $loteId)
                    ->orderBy('id', 'ASC')
                    ->findAll();
    }
}

