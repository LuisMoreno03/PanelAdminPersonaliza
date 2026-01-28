<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class SeguimientoController extends BaseController
{
    public function index()
    {
        return view('seguimiento/index', ['title' => 'Seguimiento']);
    }

    public function resumen()
    {
        try {
            $from = $this->request->getGet('from'); // YYYY-MM-DD
            $to   = $this->request->getGet('to');   // YYYY-MM-DD

            $db = db_connect();

            // 1) Detectar modo usuarios (tu API ya mostraba mode: "usuarios")
            $joinMode = $this->detectUserJoinMode($db);

            // 2) Construir UNION desde las tablas de historial reales
            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnion($db, $from, $to);

            // Si no hay ninguna fuente válida, devolvemos vacío
            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'data' => [],
                    'mode' => $joinMode,
                    'sources' => [],
                ]);
            }

            // 3) Resumen por usuario desde el UNION
            //    Luego join a usuarios (si existe) para nombre/correo
            if ($joinMode === 'usuarios') {
                // Detectar campos de usuarios automáticamente
                $nameField  = $db->fieldExists('nombre', 'usuarios') ? 'nombre'
                           : ($db->fieldExists('name', 'usuarios') ? 'name'
                           : ($db->fieldExists('username', 'usuarios') ? 'username' : null));

                $emailField = $db->fieldExists('correo', 'usuarios') ? 'correo'
                           : ($db->fieldExists('email', 'usuarios') ? 'email' : null);

                // Si no hay name/email, igual devolvemos por ID
                $selectName  = $nameField  ? "u.$nameField as user_name," : "NULL as user_name,";
                $selectEmail = $emailField ? "u.$emailField as user_email," : "NULL as user_email,";

                $sql = "
                    SELECT
                        u.id as user_id,
                        $selectName
                        $selectEmail
                        COUNT(t.user_id) as total_cambios,
                        MAX(t.created_at) as ultimo_cambio
                    FROM ($unionSQL) t
                    LEFT JOIN usuarios u ON u.id = t.user_id
                    GROUP BY u.id" . ($nameField ? ", u.$nameField" : "") . ($emailField ? ", u.$emailField" : "") . "
                    ORDER BY total_cambios DESC
                ";

                $rows = $db->query($sql, $binds)->getResultArray();
            } else {
                // Sin join a tabla usuarios (por si no existiera)
                $sql = "
                    SELECT
                        t.user_id as user_id,
                        MAX(u.$nameField) as user_name,
                        " . ($emailField ? "MAX(u.$emailField) as user_email," : "NULL as user_email,") . "
                        COUNT(*) as total_cambios,
                        MAX(t.created_at) as ultimo_cambio
                    FROM ($unionSQL) t
                    LEFT JOIN usuarios u ON u.id = t.user_id
                    WHERE t.user_id IS NOT NULL AND t.user_id > 0
                    GROUP BY t.user_id
                    ORDER BY total_cambios DESC
                ";


                $rows = $db->query($sql, $binds)->getResultArray();
            }

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows,
                'mode' => $joinMode,
                'sources' => $sourcesUsed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/resumen ERROR: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ]);
        }
    }

    /**
     * Construye un UNION ALL de las tablas de historial que existan y tengan:
     * - campo de usuario
     * - campo fecha
     * Devuelve: [sql, binds, sourcesUsed]
     */
    private function buildHistoryUnion($db, ?string $from, ?string $to): array
    {
        $candidates = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
        ];

        // posibles nombres de campo usuario
        $userFields = ['user_id', 'usuario_id', 'id_usuario', 'created_by', 'updated_by', 'changed_by', 'admin_id'];

        // posibles nombres de fecha
        $dateFields = ['created_at', 'fecha', 'changed_at', 'updated_at', 'fecha_cambio', 'created'];

        $parts = [];
        $binds = [];
        $sourcesUsed = [];

        foreach ($candidates as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }

            $userField = $this->firstExistingField($db, $table, $userFields);
            $dateField = $this->firstExistingField($db, $table, $dateFields);

            // si no cumple mínimo, saltar
            if (!$userField || !$dateField) {
                continue;
            }

            // Armamos SELECT normalizado
            $part = "SELECT CAST($userField AS UNSIGNED) as user_id, $dateField as created_at FROM $table WHERE 1=1";


            if ($from) {
                $part .= " AND $dateField >= ?";
                $binds[] = $from . ' 00:00:00';
            }
            if ($to) {
                $part .= " AND $dateField <= ?";
                $binds[] = $to . ' 23:59:59';
            }

            // Excluir null/0 si aplica
            $part .= " AND $userField IS NOT NULL AND CAST($userField AS UNSIGNED) > 0";


            $parts[] = $part;
            $sourcesUsed[] = "$table($userField,$dateField)";
        }

        if (empty($parts)) {
            return [null, [], []];
        }

        $unionSQL = implode(" UNION ALL ", $parts);
        return [$unionSQL, $binds, $sourcesUsed];
    }

    private function firstExistingField($db, string $table, array $fields): ?string
    {
        foreach ($fields as $f) {
            if ($db->fieldExists($f, $table)) {
                return $f;
            }
        }
        return null;
    }

    private function detectUserJoinMode($db): string
    {
        // Tu caso parece usar tabla "usuarios"
        if ($db->tableExists('usuarios') && $db->fieldExists('id', 'usuarios')) {
            return 'usuarios';
        }
        return 'none';
    }
}
