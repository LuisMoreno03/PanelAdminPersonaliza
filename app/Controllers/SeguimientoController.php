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
     * Si no mandas fechas => HOY por defecto.
     */
    public function resumen()
    {
        try {
            $db = db_connect();

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            // ✅ Por defecto HOY si no hay filtros
            if (!$from && !$to) {
                $tz = config('App')->appTimezone ?? 'UTC';
                $today = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
                $from = $today;
                $to = $today;
            }

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnion($db, $from, $to);

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'data' => [],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // Detecta cómo leer nombre/email y cómo hacer el JOIN
            $userMap = $this->detectUsuariosMapping($db);

            $joinUsuarios = $userMap['hasUsuarios']
                ? "LEFT JOIN usuarios u ON {$userMap['joinOn']}"
                : "";

            // Armamos expresiones de nombre/email
            $nameAgg  = $userMap['nameExpr']  ? "MAX({$userMap['nameExpr']})"  : "NULL";
            $emailAgg = $userMap['emailExpr'] ? "MAX({$userMap['emailExpr']})" : "NULL";

            $sql = "
                SELECT
                    t.user_id as user_id,
                    CASE
                        WHEN t.user_id = 0 THEN 'Sin usuario (no registrado)'
                        ELSE COALESCE($nameAgg, CONCAT('Usuario #', t.user_id))
                    END as user_name,
                    CASE
                        WHEN t.user_id = 0 THEN '-'
                        ELSE COALESCE($emailAgg, '-')
                    END as user_email,
                    COUNT(*) as total_cambios,
                    MAX(t.created_at) as ultimo_cambio
                FROM ($unionSQL) t
                $joinUsuarios
                GROUP BY t.user_id
                ORDER BY total_cambios DESC
            ";

            $rows = $db->query($sql, $binds)->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows,
                'sources' => $sourcesUsed,
                'range' => ['from' => $from, 'to' => $to],
                'user_map' => [
                    'join_on' => $userMap['joinOn'],
                    'name_expr' => $userMap['nameExpr'],
                    'email_expr' => $userMap['emailExpr'],
                ],
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
     * Si no mandas fechas => HOY por defecto.
     */
    public function detalle($userId)
    {
        try {
            $db = db_connect();
            $userId = (int)$userId;

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            // ✅ Por defecto HOY si no hay filtros
            if (!$from && !$to) {
                $tz = config('App')->appTimezone ?? 'UTC';
                $today = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
                $from = $today;
                $to = $today;
            }

            $limit  = (int)($this->request->getGet('limit') ?? 50);
            $offset = (int)($this->request->getGet('offset') ?? 0);

            if ($limit < 1) $limit = 50;
            if ($limit > 200) $limit = 200;
            if ($offset < 0) $offset = 0;

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
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            $totalSql = "SELECT COUNT(*) as total FROM ($unionSQL) t WHERE t.user_id = ?";
            $total = (int)($db->query($totalSql, array_merge($binds, [$userId]))->getRowArray()['total'] ?? 0);

            $pageSql = "
                SELECT
                  t.user_id, t.created_at, t.entidad, t.entidad_id,
                  t.estado_anterior, t.estado_nuevo, t.source
                FROM ($unionSQL) t
                WHERE t.user_id = ?
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ";
            $rows = $db->query($pageSql, array_merge($binds, [$userId, $limit, $offset]))->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'user_id' => $userId,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'data' => $rows,
                'sources' => $sourcesUsed,
                'range' => ['from' => $from, 'to' => $to],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/detalle ERROR: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // UNION (RESUMEN)
    // ---------------------------------------------------------------------
    private function buildHistoryUnion($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
        ];

        $userCandidates = [
            'usuario_id','id_usuario','user_id','users_id','id_user',
            'admin_id','empleado_id','operador_id',
            'created_by','updated_by','changed_by','responsable_id'
        ];

        // ✅ Agregamos más candidatos para cubrir "hoy"
        $dateCandidates = [
            'created_at','updated_at','changed_at','fecha','fecha_cambio','created',
            'timestamp','fecha_hora','fechaHora','createdAt','created_on','date_created'
        ];

        $parts = [];
        $binds = [];
        $sources = [];

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) continue;

            $dateField = $this->bestExistingField($db, $table, $dateCandidates);
            if (!$dateField) continue;

            $userField = $this->pickUserFieldWithData($db, $table, $userCandidates);

            // Normaliza user_id => 0 si no existe campo o si viene vacío
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

    // ---------------------------------------------------------------------
    // UNION (DETALLE)
    // ---------------------------------------------------------------------
    private function buildHistoryUnionDetail($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
        ];

        $userCandidates = [
            'usuario_id','id_usuario','user_id','users_id','id_user',
            'admin_id','empleado_id','operador_id',
            'created_by','updated_by','changed_by','responsable_id'
        ];

        $dateCandidates = [
            'created_at','updated_at','changed_at','fecha','fecha_cambio','created',
            'timestamp','fecha_hora','fechaHora','createdAt','created_on','date_created'
        ];

        $entityCandidates = [
            'pedido_id','id_pedido','pedidos_id',
            'order_id','id_order','orders_id',
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

    // ---------------------------------------------------------------------
    // USERS MAPPING (para traer nombres)
    // ---------------------------------------------------------------------
    private function detectUsuariosMapping($db): array
    {
        $hasUsuarios = $db->tableExists('usuarios');

        if (!$hasUsuarios) {
            return [
                'hasUsuarios' => false,
                'joinOn' => '1=0',
                'nameExpr' => null,
                'emailExpr' => null,
            ];
        }

        // Columnas posibles de ID en usuarios
        $idCols = [
            'id','id_usuario','usuario_id','user_id','users_id','id_user'
        ];

        $joinParts = [];
        foreach ($idCols as $c) {
            if ($db->fieldExists($c, 'usuarios')) {
                $joinParts[] = "u.$c = t.user_id";
            }
        }
        $joinOn = $joinParts ? '(' . implode(' OR ', $joinParts) . ')' : '1=0';

        // Nombre: detecta el mejor formato
        $nameExpr = null;

        $hasNombres = $db->fieldExists('nombres', 'usuarios');
        $hasApellidos = $db->fieldExists('apellidos', 'usuarios');
        if ($hasNombres && $hasApellidos) {
            $nameExpr = "CONCAT(u.nombres, ' ', u.apellidos)";
        } elseif ($db->fieldExists('nombre', 'usuarios')) {
            $nameExpr = "u.nombre";
        } elseif ($db->fieldExists('nombre_completo', 'usuarios')) {
            $nameExpr = "u.nombre_completo";
        } elseif ($db->fieldExists('usuario', 'usuarios')) {
            $nameExpr = "u.usuario";
        } elseif ($db->fieldExists('username', 'usuarios')) {
            $nameExpr = "u.username";
        } elseif ($db->fieldExists('name', 'usuarios')) {
            $nameExpr = "u.name";
        } else {
            $nameExpr = null; // fallback: Usuario #id
        }

        // Email:
        $emailExpr = null;
        if ($db->fieldExists('correo', 'usuarios')) {
            $emailExpr = "u.correo";
        } elseif ($db->fieldExists('email', 'usuarios')) {
            $emailExpr = "u.email";
        } elseif ($db->fieldExists('mail', 'usuarios')) {
            $emailExpr = "u.mail";
        } else {
            $emailExpr = null;
        }

        return [
            'hasUsuarios' => true,
            'joinOn' => $joinOn,
            'nameExpr' => $nameExpr,
            'emailExpr' => $emailExpr,
        ];
    }

    private function bestExistingField($db, string $table, array $fields): ?string
    {
        foreach ($fields as $f) {
            if ($db->fieldExists($f, $table)) return $f;
        }
        return null;
    }

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

        return $bestField;
    }
}
