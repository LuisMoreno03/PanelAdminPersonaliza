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
     * - Devuelve TODOS los users, incluso si tienen 0 cambios en el rango
     */
    public function resumen()
    {
        try {
            $db = db_connect();

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnion($db, $from, $to);

            // tabla users (preferida)
            $usersTable = $this->detectUsersTable($db); // users primero
            if (!$usersTable) {
                // si no hay users, devolver solo historial
                if (!$unionSQL) {
                    return $this->response->setJSON([
                        'ok' => true,
                        'data' => [],
                        'sources' => [],
                        'range' => ['from' => $from, 'to' => $to],
                    ]);
                }

                $countsSQL = "
                    SELECT user_id, COUNT(*) AS total_cambios, MAX(created_at) AS ultimo_cambio
                    FROM ($unionSQL) h
                    GROUP BY user_id
                ";

                $sqlOnly = "
                    SELECT
                      c.user_id,
                      CASE WHEN c.user_id = 0 THEN 'Sin usuario (no registrado)'
                           ELSE CONCAT('Usuario #', c.user_id) END AS user_name,
                      '-' AS user_email,
                      c.total_cambios,
                      c.ultimo_cambio
                    FROM ($countsSQL) c
                    ORDER BY c.total_cambios DESC
                ";

                $rows = $db->query($sqlOnly, $binds)->getResultArray();
                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $rows,
                    'sources' => $sourcesUsed,
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // si no hay union (no hay historial) igual devolvemos users con 0
            if (!$unionSQL) {
                $meta = $this->detectUsersMeta($db, $usersTable);
                $idCol = $meta['idCandidates'][0] ?? 'id';

                $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.$idCol)";
                $emailExpr = $meta['emailExpr'] ?: "NULL";

                $sqlAllZero = "
                    SELECT
                      CAST(u.$idCol AS UNSIGNED) AS user_id,
                      COALESCE($nameExpr, CONCAT('Usuario #', u.$idCol)) AS user_name,
                      COALESCE($emailExpr, '-') AS user_email,
                      0 AS total_cambios,
                      NULL AS ultimo_cambio
                    FROM $usersTable u
                    ORDER BY user_name ASC
                ";

                $rows = $db->query($sqlAllZero)->getResultArray();

                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $rows,
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // Subquery counts
            $countsSQL = "
                SELECT user_id, COUNT(*) AS total_cambios, MAX(created_at) AS ultimo_cambio
                FROM ($unionSQL) h
                GROUP BY user_id
            ";

            // detectar columnas de users y mejor columna para join
            $meta = $this->detectUsersMeta($db, $usersTable);

            $bestJoinCol = $this->pickBestUsersJoinColumn(
                $db,
                $usersTable,
                $countsSQL,
                $binds,
                $meta['idCandidates']
            );

            if (!$bestJoinCol) {
                $bestJoinCol = $meta['idCandidates'][0] ?? 'id';
            }

            $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.$bestJoinCol)";
            $emailExpr = $meta['emailExpr'] ?: "NULL";

            // 1) todos los users con cambios (o 0)
            $sqlAllUsers = "
                SELECT
                  CAST(u.$bestJoinCol AS UNSIGNED) AS user_id,
                  COALESCE($nameExpr, CONCAT('Usuario #', u.$bestJoinCol)) AS user_name,
                  COALESCE($emailExpr, '-') AS user_email,
                  COALESCE(c.total_cambios, 0) AS total_cambios,
                  c.ultimo_cambio
                FROM $usersTable u
                LEFT JOIN ($countsSQL) c ON c.user_id = CAST(u.$bestJoinCol AS UNSIGNED)
            ";

            // 2) fila user_id = 0
            $sqlNoUser = "
                SELECT
                  0 AS user_id,
                  'Sin usuario (no registrado)' AS user_name,
                  '-' AS user_email,
                  COALESCE(c0.total_cambios, 0) AS total_cambios,
                  c0.ultimo_cambio
                FROM ($countsSQL) c0
                WHERE c0.user_id = 0
            ";

            // 3) IDs del historial que no existen en users
            $sqlUnknown = "
                SELECT
                  c.user_id,
                  CONCAT('Usuario #', c.user_id) AS user_name,
                  '-' AS user_email,
                  c.total_cambios,
                  c.ultimo_cambio
                FROM ($countsSQL) c
                LEFT JOIN $usersTable u ON CAST(u.$bestJoinCol AS UNSIGNED) = c.user_id
                WHERE c.user_id > 0 AND u.$bestJoinCol IS NULL
            ";

            $sqlFinal = "
                SELECT * FROM (
                    $sqlAllUsers
                    UNION ALL
                    $sqlNoUser
                    UNION ALL
                    $sqlUnknown
                ) x
                ORDER BY x.total_cambios DESC, x.user_name ASC
            ";

            $rows = $db->query($sqlFinal, $binds)->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows,
                'sources' => $sourcesUsed,
                'range' => ['from' => $from, 'to' => $to],
                'debug' => [
                    'users_table' => $usersTable,
                    'best_join_col' => $bestJoinCol,
                    'name_expr' => $meta['nameExpr'],
                    'email_expr' => $meta['emailExpr'],
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
     * - Devuelve detalle con estado_anterior REAL calculado (LAG)
     * - Devuelve user_name/user_email desde users
     */
    public function detalle($userId)
    {
        try {
            $db = db_connect();
            $userId = (int)$userId;

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            $limit  = (int)($this->request->getGet('limit') ?? 50);
            $offset = (int)($this->request->getGet('offset') ?? 0);

            if ($limit < 1) $limit = 50;
            if ($limit > 200) $limit = 200;
            if ($offset < 0) $offset = 0;

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnionDetail($db, $from, $to);

            $usersTable = $this->detectUsersTable($db);
            $userInfo = $this->getUserInfo($db, $usersTable, $userId); // name/email

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'user_id' => $userId,
                    'user_name' => $userInfo['name'],
                    'user_email' => $userInfo['email'],
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'data' => [],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // total solo del usuario
            $totalSql = "SELECT COUNT(*) as total FROM ($unionSQL) t WHERE t.user_id = ?";
            $total = (int)($db->query($totalSql, array_merge($binds, [$userId]))->getRowArray()['total'] ?? 0);

            /**
             * ✅ Calcula ANTES con LAG:
             * - se calcula sobre TODO el historial (no solo el del usuario)
             * - y luego filtramos por user_id en el SELECT externo
             */
            $pageSql = "
                SELECT
                    x.user_id,
                    x.created_at,
                    x.entidad,
                    x.entidad_id,
                    COALESCE(NULLIF(x.estado_anterior,''), x.prev_estado) AS estado_anterior,
                    x.estado_nuevo,
                    x.source
                FROM (
                    SELECT
                        t.*,
                        LAG(t.estado_nuevo) OVER (
                            PARTITION BY t.entidad, t.entidad_id
                            ORDER BY t.created_at
                        ) AS prev_estado
                    FROM ($unionSQL) t
                ) x
                WHERE x.user_id = ?
                ORDER BY x.created_at DESC
                LIMIT ? OFFSET ?
            ";

            $rows = $db->query($pageSql, array_merge($binds, [$userId, $limit, $offset]))->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'user_id' => $userId,
                'user_name' => $userInfo['name'],
                'user_email' => $userInfo['email'],
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

    // ------------------------ USERS ------------------------

    private function detectUsersTable($db): ?string
    {
        // ✅ tu caso: "users"
        if ($db->tableExists('users')) return 'users';
        if ($db->tableExists('usuarios')) return 'usuarios';
        return null;
    }

    private function detectUsersMeta($db, string $table): array
    {
        $idCandidatesAll = ['id','id_usuario','usuario_id','user_id','users_id','id_user'];
        $idCandidates = [];
        foreach ($idCandidatesAll as $c) {
            if ($db->fieldExists($c, $table)) $idCandidates[] = $c;
        }
        if (!$idCandidates) $idCandidates = ['id'];

        // Nombre (users suele tener name/username)
        $nameExpr = null;
        if ($db->fieldExists('name', $table)) $nameExpr = "u.name";
        elseif ($db->fieldExists('username', $table)) $nameExpr = "u.username";
        elseif ($db->fieldExists('usuario', $table)) $nameExpr = "u.usuario";
        elseif ($db->fieldExists('nombre', $table)) $nameExpr = "u.nombre";
        elseif ($db->fieldExists('nombre_completo', $table)) $nameExpr = "u.nombre_completo";
        elseif ($db->fieldExists('nombres', $table) && $db->fieldExists('apellidos', $table)) $nameExpr = "CONCAT(u.nombres,' ',u.apellidos)";

        // Email
        $emailExpr = null;
        if ($db->fieldExists('email', $table)) $emailExpr = "u.email";
        elseif ($db->fieldExists('correo', $table)) $emailExpr = "u.correo";
        elseif ($db->fieldExists('mail', $table)) $emailExpr = "u.mail";

        return [
            'idCandidates' => $idCandidates,
            'nameExpr' => $nameExpr,
            'emailExpr' => $emailExpr,
        ];
    }

    private function pickBestUsersJoinColumn($db, string $usersTable, string $countsSQL, array $binds, array $idCandidates): ?string
    {
        $bestCol = null;
        $bestCount = -1;

        foreach ($idCandidates as $col) {
            $sql = "
                SELECT COUNT(*) AS c
                FROM (
                    SELECT DISTINCT user_id FROM ($countsSQL) c
                    WHERE c.user_id > 0
                ) t
                JOIN $usersTable u ON CAST(u.$col AS UNSIGNED) = t.user_id
            ";

            $c = (int)($db->query($sql, $binds)->getRowArray()['c'] ?? 0);
            if ($c > $bestCount) {
                $bestCount = $c;
                $bestCol = $col;
            }
        }

        return $bestCol;
    }

    private function getUserInfo($db, ?string $usersTable, int $userId): array
    {
        if (!$usersTable) {
            return [
                'name' => $userId === 0 ? 'Sin usuario (no registrado)' : "Usuario #$userId",
                'email' => '-',
            ];
        }

        $meta = $this->detectUsersMeta($db, $usersTable);

        // intentamos encontrar el registro por cualquier id candidate
        foreach ($meta['idCandidates'] as $idCol) {
            $sql = "SELECT * FROM $usersTable WHERE $idCol = ? LIMIT 1";
            $row = $db->query($sql, [$userId])->getRowArray();
            if ($row) {
                $name = null;
                foreach (['name','username','usuario','nombre','nombre_completo'] as $k) {
                    if (isset($row[$k]) && $row[$k] !== '') { $name = $row[$k]; break; }
                }
                if (!$name && isset($row['nombres'])) {
                    $name = trim(($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? ''));
                    if ($name === '') $name = null;
                }
                if (!$name) $name = "Usuario #$userId";

                $email = $row['email'] ?? ($row['correo'] ?? ($row['mail'] ?? '-'));

                return ['name' => $name, 'email' => $email ?: '-'];
            }
        }

        return [
            'name' => $userId === 0 ? 'Sin usuario (no registrado)' : "Usuario #$userId",
            'email' => '-',
        ];
    }

    // ------------------------ HISTORIAL ------------------------

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

        // en muchas tablas NO existe "antes"; por eso calculamos con LAG
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
