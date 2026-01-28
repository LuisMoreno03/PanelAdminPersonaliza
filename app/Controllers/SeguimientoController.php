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
    public function detalle($userId)
    {
        try {
            $userId = (int)$userId;

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            $limit  = (int)($this->request->getGet('limit') ?? 50);
            $offset = (int)($this->request->getGet('offset') ?? 0);

            if ($limit < 1) $limit = 50;
            if ($limit > 200) $limit = 200;
            if ($offset < 0) $offset = 0;

            $db = db_connect();

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnionDetail($db, $from, $to);

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'user_id' => $userId,
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'data' => [],
                    'sources' => [],
                ]);
            }

            // total
            $totalSql = "SELECT COUNT(*) as total FROM ($unionSQL) t WHERE t.user_id = ?";
            $totalBinds = array_merge($binds, [$userId]);
            $total = (int)($db->query($totalSql, $totalBinds)->getRowArray()['total'] ?? 0);

            // page
            $pageSql = "
                SELECT
                t.user_id, t.created_at, t.entidad, t.entidad_id,
                t.estado_anterior, t.estado_nuevo, t.source
                FROM ($unionSQL) t
                WHERE t.user_id = ?
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ";

            $pageBinds = array_merge($binds, [$userId, $limit, $offset]);
            $rows = $db->query($pageSql, $pageBinds)->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'user_id' => $userId,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'data' => $rows,
                'sources' => $sourcesUsed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/detalle ERROR: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * UNION de detalle: normaliza a columnas comunes:
     * user_id, created_at, entidad, entidad_id, estado_anterior, estado_nuevo, source
     */
    private function buildHistoryUnionDetail($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
            // si luego quieres, añade otras aquí
        ];

        $userCandidates = [
            'usuario_id','id_usuario','user_id','admin_id','empleado_id',
            'created_by','updated_by','changed_by','responsable_id'
        ];

        $dateCandidates = ['created_at','fecha','changed_at','updated_at','fecha_cambio','created'];

        $entityCandidates = [
            // pedidos
            'pedido_id','id_pedido','pedidos_id',
            // orders
            'order_id','id_order',
            // genérico
            'entidad_id'
        ];

        $oldCandidates = [
            'estado_anterior','status_anterior',
            'from_status','status_from',
            'old_status','previous_status',
            'old_status_id','from_status_id'
        ];

        $newCandidates = [
            'estado_nuevo','status_nuevo',
            'to_status','status_to',
            'new_status','current_status',
            'new_status_id','to_status_id',
            // a veces guardan solo el nuevo
            'status_id','estado','status'
        ];

        $parts = [];
        $binds = [];
        $sources = [];

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) continue;

            $dateField = $this->bestExistingField($db, $table, $dateCandidates);
            if (!$dateField) continue;

            $userField = $this->pickUserFieldWithData($db, $table, $userCandidates);

            // si no hay userField con datos, igual registramos como 0 (sin usuario)
            $userExpr = $userField
                ? "CAST(COALESCE(NULLIF(TRIM($userField), ''), 0) AS UNSIGNED)"
                : "0";

            $entityField = $this->bestExistingField($db, $table, $entityCandidates);
            $entityExpr  = $entityField ? "CAST($entityField AS UNSIGNED)" : "NULL";

            $oldField = $this->bestExistingField($db, $table, $oldCandidates);
            $newField = $this->bestExistingField($db, $table, $newCandidates);

            $oldExpr = $oldField ? "CAST($oldField AS CHAR)" : "NULL";
            $newExpr = $newField ? "CAST($newField AS CHAR)" : "NULL";

            // etiqueta entidad según el campo o tabla
            $entidad = 'pedido';
            if (($entityField && stripos($entityField, 'order') !== false) || stripos($table, 'order_') !== false) {
                $entidad = 'order';
            }

            $part = "
                SELECT
                $userExpr as user_id,
                $dateField as created_at,
                '$entidad' as entidad,
                $entityExpr as entidad_id,
                $oldExpr as estado_anterior,
                $newExpr as estado_nuevo,
                '$table' as source
                FROM $table
                WHERE 1=1
            ";

            if ($from) { $part .= " AND $dateField >= ?"; $binds[] = $from . " 00:00:00"; }
            if ($to)   { $part .= " AND $dateField <= ?"; $binds[] = $to . " 23:59:59"; }

            $parts[] = $part;
            $sources[] = $userField ? "$table($userField,$dateField)" : "$table(NO_USER,$dateField)";
        }

        if (!$parts) return [null, [], []];

        return [implode(" UNION ALL ", $parts), $binds, $sources];
    }

}
