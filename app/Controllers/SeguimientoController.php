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
     * - Devuelve TODOS los users (aunque tengan 0 cambios)
     * - Devuelve pedidos_tocados por usuario
     * - Devuelve stats.pedidos_tocados (general del rango)
     */
    public function resumen()
    {
        try {
            $db = db_connect();

            $from = $this->request->getGet('from'); // YYYY-MM-DD
            $to   = $this->request->getGet('to');   // YYYY-MM-DD

            if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'from inválido (usa YYYY-MM-DD)']);
            }
            if ($to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'to inválido (usa YYYY-MM-DD)']);
            }
            if ($from && $to && $from > $to) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'Rango inválido: from > to']);
            }

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnionDetail($db, $from, $to);
            $usersTable = $this->detectUsersTable($db);

            // No hay historial
            if (!$unionSQL) {
                if (!$usersTable) {
                    return $this->response->setJSON([
                        'ok' => true,
                        'data' => [],
                        'stats' => ['pedidos_tocados' => 0],
                        'sources' => [],
                        'range' => ['from' => $from, 'to' => $to],
                    ]);
                }

                $meta = $this->detectUsersMeta($db, $usersTable);
                $idCol = $meta['idCandidates'][0] ?? 'id';
                $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.`$idCol`)";
                $emailExpr = $meta['emailExpr'] ?: "NULL";

                $sql = "
                    SELECT
                      CAST(u.`$idCol` AS UNSIGNED) AS user_id,
                      COALESCE($nameExpr, CONCAT('Usuario #', u.`$idCol`)) AS user_name,
                      COALESCE($emailExpr, '-') AS user_email,
                      0 AS total_cambios,
                      0 AS pedidos_tocados,
                      NULL AS ultimo_cambio
                    FROM `$usersTable` u
                    ORDER BY user_name ASC
                ";

                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $db->query($sql)->getResultArray(),
                    'stats' => ['pedidos_tocados' => 0],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // ✅ CTE: UNION UNA sola vez (evita 500 con fechas)
            $cte = "
                WITH h AS (
                    $unionSQL
                ),
                c AS (
                    SELECT
                      user_id,
                      COUNT(*) AS total_cambios,
                      MAX(created_at) AS ultimo_cambio,
                      COUNT(DISTINCT (CASE
                        WHEN entidad IN ('pedido','order')
                         AND entidad_id IS NOT NULL
                         AND entidad_id <> 0
                        THEN CONCAT(entidad,':',entidad_id)
                      END)) AS pedidos_tocados
                    FROM h
                    GROUP BY user_id
                ),
                p AS (
                    SELECT COUNT(DISTINCT CONCAT(entidad,':',entidad_id)) AS pedidos_tocados
                    FROM h
                    WHERE entidad IN ('pedido','order')
                      AND entidad_id IS NOT NULL
                      AND entidad_id <> 0
                )
            ";

            // Sin users
            if (!$usersTable) {
                $sql = $cte . "
                    SELECT
                      c.user_id,
                      CASE WHEN c.user_id = 0 THEN 'Sin usuario (no registrado)'
                           ELSE CONCAT('Usuario #', c.user_id) END AS user_name,
                      '-' AS user_email,
                      c.total_cambios,
                      c.pedidos_tocados,
                      c.ultimo_cambio
                    FROM c
                    ORDER BY (c.user_id=0) DESC, c.total_cambios DESC, user_name ASC
                ";

                $rows  = $db->query($sql, $binds)->getResultArray();
                $stats = $db->query($cte . " SELECT pedidos_tocados FROM p", $binds)->getRowArray();

                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $rows,
                    'stats' => ['pedidos_tocados' => (int)($stats['pedidos_tocados'] ?? 0)],
                    'sources' => $sourcesUsed,
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // Users existe
            $meta = $this->detectUsersMeta($db, $usersTable);
            $bestJoinCol = $meta['idCandidates'][0] ?? 'id';
            $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.`$bestJoinCol`)";
            $emailExpr = $meta['emailExpr'] ?: "NULL";

            $sql = $cte . "
                SELECT * FROM (
                    -- todos los users (con o sin cambios)
                    SELECT
                      CAST(u.`$bestJoinCol` AS UNSIGNED) AS user_id,
                      COALESCE($nameExpr, CONCAT('Usuario #', u.`$bestJoinCol`)) AS user_name,
                      COALESCE($emailExpr, '-') AS user_email,
                      COALESCE(c.total_cambios, 0) AS total_cambios,
                      COALESCE(c.pedidos_tocados, 0) AS pedidos_tocados,
                      c.ultimo_cambio
                    FROM `$usersTable` u
                    LEFT JOIN c ON c.user_id = CAST(u.`$bestJoinCol` AS UNSIGNED)

                    UNION ALL

                    -- sin usuario (id=0) SIEMPRE
                    SELECT
                      0 AS user_id,
                      'Sin usuario (no registrado)' AS user_name,
                      '-' AS user_email,
                      COALESCE(c0.total_cambios, 0) AS total_cambios,
                      COALESCE(c0.pedidos_tocados, 0) AS pedidos_tocados,
                      c0.ultimo_cambio
                    FROM (SELECT 0 AS user_id) d
                    LEFT JOIN c c0 ON c0.user_id = 0

                    UNION ALL

                    -- ids en historial que no existen en users
                    SELECT
                      c2.user_id,
                      CONCAT('Usuario #', c2.user_id) AS user_name,
                      '-' AS user_email,
                      c2.total_cambios,
                      c2.pedidos_tocados,
                      c2.ultimo_cambio
                    FROM c c2
                    LEFT JOIN `$usersTable` u2 ON CAST(u2.`$bestJoinCol` AS UNSIGNED) = c2.user_id
                    WHERE c2.user_id > 0 AND u2.`$bestJoinCol` IS NULL
                ) x
                ORDER BY (x.user_id=0) DESC, x.total_cambios DESC, x.user_name ASC
            ";

            $rows  = $db->query($sql, $binds)->getResultArray();
            $stats = $db->query($cte . " SELECT pedidos_tocados FROM p", $binds)->getRowArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows,
                'stats' => ['pedidos_tocados' => (int)($stats['pedidos_tocados'] ?? 0)],
                'sources' => $sourcesUsed,
                'range' => ['from' => $from, 'to' => $to],
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
     * - NO devuelve source (src)
     * - Devuelve pedidos_tocados del usuario
     * - Devuelve estado_anterior real (LAG)
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

            if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'from inválido (usa YYYY-MM-DD)']);
            }
            if ($to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'to inválido (usa YYYY-MM-DD)']);
            }
            if ($from && $to && $from > $to) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'Rango inválido: from > to']);
            }

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnionDetail($db, $from, $to);

            $usersTable = $this->detectUsersTable($db);
            $userInfo = $this->getUserInfo($db, $usersTable, $userId);

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'user_id' => $userId,
                    'user_name' => $userInfo['name'],
                    'user_email' => $userInfo['email'],
                    'total' => 0,
                    'pedidos_tocados' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'data' => [],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // ✅ CTE con LAG
            $cte = "
                WITH h AS (
                    $unionSQL
                ),
                hx AS (
                    SELECT
                      t.*,
                      LAG(t.estado_nuevo) OVER (
                        PARTITION BY t.entidad, t.entidad_id
                        ORDER BY t.created_at
                      ) AS prev_estado
                    FROM h t
                )
            ";

            $totalSql = $cte . " SELECT COUNT(*) AS total FROM hx WHERE user_id = ?";
            $total = (int)($db->query($totalSql, array_merge($binds, [$userId]))->getRowArray()['total'] ?? 0);

            $ptSql = $cte . "
                SELECT COUNT(DISTINCT CONCAT(entidad,':',entidad_id)) AS pedidos_tocados
                FROM hx
                WHERE user_id = ?
                  AND entidad IN ('pedido','order')
                  AND entidad_id IS NOT NULL
                  AND entidad_id <> 0
            ";
            $pt = (int)($db->query($ptSql, array_merge($binds, [$userId]))->getRowArray()['pedidos_tocados'] ?? 0);

            // ❌ sin source
            $pageSql = $cte . "
                SELECT
                  user_id,
                  created_at,
                  entidad,
                  entidad_id,
                  COALESCE(NULLIF(estado_anterior,''), prev_estado) AS estado_anterior,
                  estado_nuevo
                FROM hx
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT $limit OFFSET $offset
            ";

            $rows = $db->query($pageSql, array_merge($binds, [$userId]))->getResultArray();

            return $this->response->setJSON([
                'ok' => true,
                'user_id' => $userId,
                'user_name' => $userInfo['name'],
                'user_email' => $userInfo['email'],
                'total' => $total,
                'pedidos_tocados' => $pt,
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

        $nameExpr = null;
        if ($db->fieldExists('name', $table)) $nameExpr = "u.name";
        elseif ($db->fieldExists('username', $table)) $nameExpr = "u.username";
        elseif ($db->fieldExists('usuario', $table)) $nameExpr = "u.usuario";
        elseif ($db->fieldExists('nombre', $table)) $nameExpr = "u.nombre";
        elseif ($db->fieldExists('nombre_completo', $table)) $nameExpr = "u.nombre_completo";
        elseif ($db->fieldExists('nombres', $table) && $db->fieldExists('apellidos', $table)) $nameExpr = "CONCAT(u.nombres,' ',u.apellidos)";

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

    private function getUserInfo($db, ?string $usersTable, int $userId): array
    {
        if (!$usersTable) {
            return [
                'name' => $userId === 0 ? 'Sin usuario (no registrado)' : "Usuario #$userId",
                'email' => '-',
            ];
        }

        $meta = $this->detectUsersMeta($db, $usersTable);

        foreach ($meta['idCandidates'] as $idCol) {
            $sql = "SELECT * FROM `$usersTable` WHERE `$idCol` = ? LIMIT 1";
            $row = $db->query($sql, [$userId])->getRowArray();
            if ($row) {
                $name = null;
                foreach (['name','username','usuario','nombre','nombre_completo'] as $k) {
                    if (!empty($row[$k])) { $name = $row[$k]; break; }
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

    private function buildHistoryUnionDetail($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
            'seguimiento_cambios',
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
            'entidad_id','registro_id','record_id','row_id'
        ];

        $oldCandidates = [
            'estado_anterior','status_anterior',
            'from_status','status_from',
            'old_status','previous_status',
            'old_status_id','from_status_id',
            'antes','valor_antes','old_value','old','from_value'
        ];

        $newCandidates = [
            'estado_nuevo','status_nuevo',
            'to_status','status_to',
            'new_status','current_status',
            'new_status_id','to_status_id',
            'status_id','estado','status',
            'despues','valor_despues','new_value','new','to_value'
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
                ? "CAST(COALESCE(NULLIF(TRIM(CAST($userField AS CHAR)), ''), '0') AS UNSIGNED)"
                : "0";

            // entidad / entidad_id
            $entExpr = "'cambio'";
            $idExpr  = "NULL";

            if ($table === 'seguimiento_cambios') {
                $entField = $this->bestExistingField($db, $table, ['entidad','tabla','table_name','entity','modulo']);
                $idField  = $this->bestExistingField($db, $table, ['entidad_id','registro_id','record_id','row_id','pedido_id','order_id','id_registro']);
                $entExpr  = $entField ? "LOWER(CAST($entField AS CHAR))" : "'cambio'";
                $idExpr   = $idField ? "CAST($idField AS UNSIGNED)" : "NULL";
            } else {
                $entityField = $this->bestExistingField($db, $table, $entityCandidates);
                if ($entityField) $idExpr = "CAST($entityField AS UNSIGNED)";

                $entidad = 'pedido';
                if (($entityField && stripos($entityField, 'order') !== false) || stripos($table, 'order_') !== false) {
                    $entidad = 'order';
                }
                $entExpr = "'" . $entidad . "'";
            }

            $oldField = $this->bestExistingField($db, $table, $oldCandidates);
            $newField = $this->bestExistingField($db, $table, $newCandidates);

            $oldExpr = $oldField ? "CAST($oldField AS CHAR)" : "NULL";
            $newExpr = $newField ? "CAST($newField AS CHAR)" : "NULL";

            $part = "
                SELECT
                  $userExpr AS user_id,
                  $dateField AS created_at,
                  $entExpr  AS entidad,
                  $idExpr   AS entidad_id,
                  $oldExpr  AS estado_anterior,
                  $newExpr  AS estado_nuevo,
                  '$table'  AS source
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
