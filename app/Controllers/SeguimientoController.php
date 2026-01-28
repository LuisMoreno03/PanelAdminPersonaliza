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
        $from = $this->request->getGet('from'); // YYYY-MM-DD
        $to   = $this->request->getGet('to');   // YYYY-MM-DD

        $db = db_connect();

        /**
         * Ajusta el nombre de tu tabla de usuarios:
         * - Si usas Shield u otra auth, tu tabla puede llamarse diferente.
         * Aquí asumo: users (id, name, email)
         */
        $builder = $db->table('seguimiento_cambios sc');
        $builder->select([
            'u.id as user_id',
            'u.name as user_name',
            'u.email as user_email',
            'COUNT(sc.id) as total_cambios',
            'MAX(sc.created_at) as ultimo_cambio'
        ]);
        $builder->join('users u', 'u.id = sc.user_id', 'left');

        if ($from) {
            $builder->where('sc.created_at >=', $from . ' 00:00:00');
        }
        if ($to) {
            $builder->where('sc.created_at <=', $to . ' 23:59:59');
        }

        $builder->groupBy(['u.id', 'u.name', 'u.email']);
        $builder->orderBy('total_cambios', 'DESC');

        $rows = $builder->get()->getResultArray();

        return $this->response->setJSON([
            'ok' => true,
            'data' => $rows
        ]);
    }
}
