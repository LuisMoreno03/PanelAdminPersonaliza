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
            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            $db = db_connect();

            $joinMode = $this->detectUserJoinMode($db);

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnion($db, $from, $to);

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'data' => [],
                    'mode' => $joinMode,
                    'sources' => [],
                ]);
            }

            // Campos de tu tabla usuarios (auto)
            $nameField  = $db->fieldExists('nombre', 'usuarios') ? 'nombre'
                       : ($db->fieldExists('name', 'usuarios') ? 'name'
                       : ($db->fieldExists('username', 'usuarios') ? 'username' : null));

            $emailField = $db->fieldExists('correo', 'usuarios') ? 'correo'
                       : ($db->fieldExists('email', 'usuarios') ? 'email' : null);

            $selectEmail = $emailField ? "MAX(u.$emailField) as user_email," : "'-' as user_email,";

            // ✅ Importante: SIEMPRE usar t.user_id (del historial), no u.id
            // ✅ user_id viene normalizado (NULL => 0) en el UNION
            $sql = "
                SELECT
                    t.user_id as user_id,
                    CASE
                        WHEN t.user_id = 0 THEN 'Sin usuario (no registrado)'
                        ELSE COALESCE(MAX(u.$nameField), CONCAT('Usuario #', t.user_id))
                    END as user_name,
                    CASE
                        WHEN t.user_id = 0 THEN '-'
                        ELSE " . ($emailField ? "COALESCE(MAX(u.$emailField), '-')" : "'-'") . "
                    END as user_email,
                    COUNT(*) as total_cambios,
                    MAX(t.created_at) as ultimo_cambio
                FROM ($unionSQL) t
                LEFT JOIN usuarios u ON u.id = t.user_id
                GROUP BY t.user_id
                ORDER BY total_cambios DESC
            ";

            $rows = $db->query($sql, $binds)->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows,
                'mode' => $joinMode,
                'sources' => $sourcesUsed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/resumen ERROR: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * UNION de historiales. Normaliza user_id:
     * - si el campo es NULL / vacío / no numérico -> user_id = 0
     * - si hay campos alternativos con datos, usa el que tenga más registros útiles
     */
    private function buildHistoryUnion($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
        ];

        // Añadí más candidatos típicos
        $userCandidates = [
            'usuario_id', 'id_usuario', 'user_id',
            'admin_id', 'empleado_id', 'operador_id',
            'created_by', 'updated_by', 'changed_by', 'responsable_id'
        ];

        $dateCandidates = [
            'created_at', 'fecha', 'changed_at', 'updated_at', 'fecha_cambio', 'created'
        ];

        $parts = [];
        $binds = [];
        $sources = [];

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) continue;

            $dateField = $this->bestExistingField($db, $table, $dateCandidates);
            if (!$dateField) continue;

            // ✅ escoger el “mejor” campo de usuario (el que tenga más valores útiles)
            $userField = $this->pickUserFieldWithData($db, $table, $userCandidates);

            // Si no hay ningún campo con datos, igual incluimos pero lo marcamos como 0
            if (!$userField) {
                $userExpr = "0";
                $sources[] = "$table(NO_USER,$dateField)";
            } else {
                // Normaliza a número: NULL/'' -> 0
                $userExpr = "COALESCE(NULLIF(TRIM($userField), ''), 0)";
                // CAST final para asegurar numérico
                $userExpr = "CAST($userExpr AS UNSIGNED)";
                $sources[] = "$table($userField,$dateField)";
            }

            $part = "SELECT $userExpr as user_id, $dateField as created_at FROM $table WHERE 1=1";

            if ($from) { $part .= " AND $dateField >= ?"; $binds[] = $from . " 00:00:00"; }
            if ($to)   { $part .= " AND $dateField <= ?"; $binds[] = $to . " 23:59:59"; }

            $parts[] = $part;
        }

        if (!$parts) return [null, [], []];

        return [implode(" UNION ALL ", $parts), $binds, $sources];
    }

    private function bestExistingField($db, string $table, array $fields): ?string
    {
        foreach ($fields as $f) {
            if ($db->fieldExists($f, $table)) return $f;
        }
        return null;
    }

    /**
     * Devuelve el campo de usuario con más “datos útiles” (CAST > 0).
     */
    private function pickUserFieldWithData($db, string $table, array $candidates): ?string
    {
        $bestField = null;
        $bestCount = 0;

        foreach ($candidates as $f) {
            if (!$db->fieldExists($f, $table)) continue;

            // Cuenta cuántas filas tienen un valor “usable” (>0)
            $sql = "SELECT COUNT(*) as c
                    FROM $table
                    WHERE $f IS NOT NULL
                      AND TRIM(CAST($f AS CHAR)) <> ''
                      AND CAST($f AS UNSIGNED) > 0";
            $c = (int)($db->query($sql)->getRowArray()['c'] ?? 0);

            if ($c > $bestCount) {
                $bestCount = $c;
                $bestField = $f;
            }
        }

        return $bestField; // null si ninguno sirve
    }

    private function detectUserJoinMode($db): string
    {
        return ($db->tableExists('usuarios') && $db->fieldExists('id', 'usuarios')) ? 'usuarios' : 'none';
    }
}
