<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class SeguimientoController extends BaseController
{
    public function index()
    {
        return view('seguimiento/index', [
            'title' => 'Seguimiento',
        ]);
    }

    public function resumen()
    {
        try {
            $from = $this->request->getGet('from'); // YYYY-MM-DD
            $to   = $this->request->getGet('to');   // YYYY-MM-DD

            $db = db_connect();

            // Base query SIEMPRE válida si existe seguimiento_cambios
            $b = $db->table('seguimiento_cambios sc');

            // Detectar join de usuarios
            $joinMode = $this->detectUserJoinMode($db);

            if ($joinMode === 'shield') {
                // Shield: users + auth_identities (email suele estar en auth_identities.secret)
                $nameField = $db->fieldExists('username', 'users') ? 'username' : 'name';

                $b->select([
                    'u.id as user_id',
                    "u.$nameField as user_name",
                    // email desde auth_identities.secret: elegimos uno tipo email
                    "MAX(CASE 
                        WHEN ai.type IN ('email_password','email') THEN ai.secret
                        WHEN ai.type LIKE '%email%' THEN ai.secret
                        ELSE NULL
                    END) as user_email",
                    'COUNT(sc.id) as total_cambios',
                    'MAX(sc.created_at) as ultimo_cambio',
                ], false);

                $b->join('users u', 'u.id = sc.user_id', 'left');
                $b->join('auth_identities ai', 'ai.user_id = u.id', 'left');

                if ($from) $b->where('sc.created_at >=', $from . ' 00:00:00');
                if ($to)   $b->where('sc.created_at <=', $to . ' 23:59:59');

                $b->groupBy(['u.id', "u.$nameField"]);
                $b->orderBy('total_cambios', 'DESC');

                $rows = $b->get()->getResultArray();
            } elseif ($joinMode === 'usuarios') {
                // Tabla "usuarios" típica
                $nameField  = $db->fieldExists('nombre', 'usuarios') ? 'nombre'
                           : ($db->fieldExists('name', 'usuarios') ? 'name'
                           : ($db->fieldExists('username', 'usuarios') ? 'username' : 'id'));

                $emailField = $db->fieldExists('correo', 'usuarios') ? 'correo'
                           : ($db->fieldExists('email', 'usuarios') ? 'email' : 'id');

                $b->select([
                    'u.id as user_id',
                    "u.$nameField as user_name",
                    "u.$emailField as user_email",
                    'COUNT(sc.id) as total_cambios',
                    'MAX(sc.created_at) as ultimo_cambio',
                ]);

                $b->join('usuarios u', 'u.id = sc.user_id', 'left');

                if ($from) $b->where('sc.created_at >=', $from . ' 00:00:00');
                if ($to)   $b->where('sc.created_at <=', $to . ' 23:59:59');

                $b->groupBy(['u.id', "u.$nameField", "u.$emailField"]);
                $b->orderBy('total_cambios', 'DESC');

                $rows = $b->get()->getResultArray();
            } else {
                // Sin join: solo user_id + conteo (nunca debe explotar por usuarios)
                $b->select([
                    'sc.user_id as user_id',
                    'COUNT(sc.id) as total_cambios',
                    'MAX(sc.created_at) as ultimo_cambio',
                ]);

                if ($from) $b->where('sc.created_at >=', $from . ' 00:00:00');
                if ($to)   $b->where('sc.created_at <=', $to . ' 23:59:59');

                $b->groupBy('sc.user_id');
                $b->orderBy('total_cambios', 'DESC');

                $rows = $b->get()->getResultArray();
            }

            return $this->response->setJSON([
                'ok'   => true,
                'data' => $rows,
                'mode' => $joinMode,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/resumen ERROR: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok'      => false,
                    'message' => $e->getMessage(),
                ]);
        }
    }

    private function detectUserJoinMode($db): string
    {
        // Shield
        if (
            $db->tableExists('users') &&
            $db->tableExists('auth_identities') &&
            $db->fieldExists('id', 'users') &&
            $db->fieldExists('user_id', 'auth_identities') &&
            $db->fieldExists('secret', 'auth_identities') &&
            $db->fieldExists('type', 'auth_identities')
        ) {
            return 'shield';
        }

        // Tabla usuarios propia
        if ($db->tableExists('usuarios') && $db->fieldExists('id', 'usuarios')) {
            return 'usuarios';
        }

        return 'none';
    }
}
