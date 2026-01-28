<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class SeguimientoController extends BaseController
{
    public function index()
    {
        return view('seguimiento/index', ['title' => 'Seguimiento']);
    }

    /**
     * GET /seguimiento/resumen?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function resumen()
    {
        try {
            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            $db = db_connect();

            // UNION normalizado desde historiales reales
            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnion($db, $from, $to);

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'data' => [],
                    'sources' => [],
                ]);
            }

            // Detectar campos en tabla usuarios
            [$nameField, $emailField] = $this->detectUsuarioFields($db);

            // Resumen por user_id (siempre usar t.user_id, no u.id)
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
     * GET /seguimiento/detalle/{userId}?from=...&to=...&limit=50&offset=0
     */
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
     * UNION "resumen": user_id, created_at
     */
    private function buildHistoryUnion($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
            'order_status_history', // si está duplicada no afecta, pero puedes quitar una
        ];

        $userCandidates = [
            'usuario_id','id_usuario','user_id',
            'admin_id','empleado_id','operador_id',
            'created_by','updated_by','changed_by','responsable_id'
        ];

        $dateCandidates = [
            'created_at','fecha','changed_at','updated_at','fecha_cambio','created'
        ];

        $parts = [];
        $binds = [];
        $sources = [];

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) continue;

            $dateField = $this->bestExistingField($db, $table, $dateCandidates);
            if (!$dateField) continue;

            $userField = $this->pickUserFieldWithData($db, $table, $userCandidates);

            $userExpr = $userField
                ? "CAST(COALESCE(NULLIF(TRIM($userField), ''), 0) AS UNSIGNED)"
                : "0";

            $part = "SELECT $userExpr as user_id, $dateField as created_at FROM $table WHERE 1=1";

            if ($from) { $part .= " AND $dateField >= ?"; $binds[] = $from . " 00:00:00"; }
            if ($to)   { $part .= " AND $dateField <= ?"; $binds[] = $to . " 23:59:59"; }

            $parts[] = $part;
            $sources[] = $userField ? "$table($userField,$dateField)" : "$table(NO_USER,$dateField)";
        }

        if (!$parts) return [null, [], []];

        return [implode(" UNION ALL ", $parts), $binds, $sources];
    }

    /**
     * UNION "detalle": user_id, created_at, entidad, entidad_id, estado_anterior, estado_nuevo, source
     */
    private function buildHistoryUnionDetail($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
        ];

        $userCandidates = [
            'usuario_id','id_usuario','user_id',
            'admin_id','empleado_id','operador_id',
            'created_by','updated_by','changed_by','responsable_id'
        ];

        $dateCandidates = ['created_at','fecha','changed_at','updated_at','fecha_cambio','created'];

        $entityCandidates = [
            'pedido_id','id_pedido','pedidos_id',
            'order_id','id_order',
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
            $userExpr = $userField
                ? "CAST(COALESCE(NULLIF(TRIM($userField), ''), 0) AS UNSIGNED)"
                : "0";

            $entityField = $this->bestExistingField($db, $table, $entityCandidates);
            $entityExpr  = $entityField ? "CAST($entityField AS UNSIGNED)" : "NULL";

            $oldField = $this->bestExistingField($db, $table, $oldCandidates);
            $newField = $this->bestExistingField($db, $table, $newCandidates);

            $oldExpr = $oldField ? "CAST($oldField AS CHAR)" : "NULL";
            $newExpr = $newField ? "CAST($newField AS CHAR)" : "NULL";

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

    private function bestExistingField($db, string $table, array $fields): ?string
    {
        foreach ($fields as $f) {
            if ($db->fieldExists($f, $table)) return $f;
        }
        return null;
    }

    /**
     * Escoge el campo de usuario con más valores útiles (>0).
     */
    private function pickUserFieldWithData($db, string $table, array $candidates): ?string
    {
        $bestField = null;
        $bestCount = 0;

        foreach ($candidates as $f) {
            if (!$db->fieldExists($f, $table)) continue;

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

    /**
     * Detecta campos de nombre/email en usuarios. Devuelve [nameField, emailField|null]
     */
    private function detectUsuarioFields($db): array
    {
        $nameField = $db->fieldExists('nombre', 'usuarios') ? 'nombre'
                  : ($db->fieldExists('nombre_completo', 'usuarios') ? 'nombre_completo'
                  : ($db->fieldExists('usuario', 'usuarios') ? 'usuario'
                  : ($db->fieldExists('username', 'usuarios') ? 'username'
                  : ($db->fieldExists('name', 'usuarios') ? 'name'
                  : 'id')))); // fallback seguro

        $emailField = $db->fieldExists('correo', 'usuarios') ? 'correo'
                   : ($db->fieldExists('email', 'usuarios') ? 'email' : null);

        return [$nameField, $emailField];
    }
}
