<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\SeguimientoCambioModel;

class SeguimientoController extends BaseController
{
    public function index()
    {
        // Puedes proteger esto con tu auth/roles si lo necesitas
        return view('seguimiento/index', [
            'title' => 'Seguimiento'
        ]);
    }

    
    public function resumen()
    {
        try {
            $from = $this->request->getGet('from'); // YYYY-MM-DD
            $to   = $this->request->getGet('to');   // YYYY-MM-DD

            $db = db_connect();

            // Base: SIEMPRE funciona aunque no haya tabla users/usuarios
            $builder = $db->table('seguimiento_cambios sc');
            $builder->select([
                'sc.user_id as user_id',
                'COUNT(sc.id) as total_cambios',
                'MAX(sc.created_at) as ultimo_cambio',
            ]);

            if ($from) $builder->where('sc.created_at >=', $from . ' 00:00:00');
            if ($to)   $builder->where('sc.created_at <=', $to . ' 23:59:59');

            // Intentar JOIN dinámico con tablas típicas de usuarios (si existen)
            $userJoin = $this->detectUserJoin($db);
            if ($userJoin) {
                [$table, $idField, $nameField, $emailField] = $userJoin;

                $builder->select([
                    "u.$nameField as user_name",
                    "u.$emailField as user_email",
                ]);

                $builder->join("$table u", "u.$idField = sc.user_id", 'left');
                $builder->groupBy(["sc.user_id", "u.$nameField", "u.$emailField"]);
            } else {
                $builder->groupBy(["sc.user_id"]);
            }

            $builder->orderBy('total_cambios', 'DESC');

            $rows = $builder->get()->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/resumen ERROR: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' => $e->getMessage()
                ]);
        }
    }

    /**
     * Detecta automáticamente una tabla de usuarios y campos comunes.
     * Devuelve: [table, idField, nameField, emailField] o null
     */
    private function detectUserJoin($db): ?array
    {
        $candidates = [
            // CodeIgniter Shield (a veces username)
            ['users', 'id', 'name', 'email'],
            ['users', 'id', 'username', 'email'],

            // Tipos comunes en español
            ['usuarios', 'id', 'nombre', 'correo'],
            ['usuarios', 'id', 'nombre', 'email'],
            ['usuarios', 'id', 'usuario', 'email'],
        ];

        foreach ($candidates as $c) {
            [$table, $idField, $nameField, $emailField] = $c;

            if (!$db->tableExists($table)) continue;
            if (!$db->fieldExists($idField, $table)) continue;
            if (!$db->fieldExists($nameField, $table)) continue;
            if (!$db->fieldExists($emailField, $table)) continue;

            return $c;
        }

        return null;
    }

}
