<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class Seguimiento extends BaseController
{
    public function index()
    {
        // Cambia la ruta de la vista si tu archivo se llama distinto
        return view('seguimiento');
    }

    /**
     * GET /seguimiento/resumen?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Devuelve:
     *  - data: cambios por usuario
     *  - stats: pedidos_modificados (general)
     *  - range, sources
     */
    public function resumen()
    {
        try {
            $db = db_connect();

            $from = $this->request->getGet('from'); // YYYY-MM-DD
            $to   = $this->request->getGet('to');   // YYYY-MM-DD

            // Validación simple
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

            // Si no hay union (no existen tablas), devolvemos vacío
            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'data' => [],
                    'stats' => ['pedidos_modificados' => 0],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // CTE con el UNION UNA sola vez (evita 500 por binds duplicados)
            $cte = "
                WITH h AS (
                    $unionSQL
                ),
                c AS (
                    SELECT user_id, COUNT(*) AS total_cambios, MAX(created_at) AS ultimo_cambio
                    FROM h
                    GROUP BY user_id
                ),
                p AS (
                    SELECT COUNT(DISTINCT CONCAT(entidad,':',entidad_id)) AS pedidos_modificados
                    FROM h
                    WHERE entidad IN ('pedido','order')
                      AND entidad_id IS NOT NULL
                      AND entidad_id <> 0
                )
            ";

            // Si no existe tabla users, devolvemos usuarios por id
            if (!$usersTable) {
                $sql = $cte . "
                    SELECT
                      c.user_id,
                      CASE WHEN c.user_id = 0 THEN 'Sin usuario (no registrado)'
                           ELSE CONCAT('Usuario #', c.user_id) END AS user_name,
                      '-' AS user_email,
                      c.total_cambios,
                      c.ultimo_cambio
                    FROM c
                    ORDER BY (c.user_id=0) ASC, c.total_cambios DESC, user_name ASC
                ";

                $rows  = $db->query($sql, $binds)->getResultArray();
                $stats = $db->query($cte . " SELECT pedidos_modificados FROM p", $binds)->getRowArray();

                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $rows,
                    'stats' => ['pedidos_modificados' => (int)($stats['pedidos_modificados'] ?? 0)],
                    'sources' => $sourcesUsed,
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // users existe: resolvemos nombre/email
            $meta = $this->detectUsersMeta($db, $usersTable);
            $idCol = $meta['idCandidates'][0] ?? 'id';
            $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.`$idCol`)";
            $emailExpr = $meta['emailExpr'] ?: "NULL";

            // Traemos:
            // 1) todos los users con LEFT JOIN a c (0 cambios incluidos)
            // 2) fila sin usuario (0)
            // 3) ids del historial que NO existen en users
            $sql = $cte . "
                SELECT * FROM (
                    SELECT
                      CAST(u.`$idCol` AS UNSIGNED) AS user_id,
                      COALESCE($nameExpr, CONCAT('Usuario #', u.`$idCol`)) AS user_name,
                      COALESCE($emailExpr, '-') AS user_email,
                      COALESCE(c.total_cambios, 0) AS total_cambios,
                      c.ultimo_cambio
                    FROM `$usersTable` u
                    LEFT JOIN c ON c.user_id = CAST(u.`$idCol` AS UNSIGNED)

                    UNION ALL

                    SELECT
                      0 AS user_id,
                      'Sin usuario (no registrado)' AS user_name,
                      '-' AS user_email,
                      COALESCE(c0.total_cambios, 0) AS total_cambios,
                      c0.ultimo_cambio
                    FROM (SELECT 0 AS user_id) d
                    LEFT JOIN c c0 ON c0.user_id = 0

                    UNION ALL

                    SELECT
                      c2.user_id,
                      CONCAT('Usuario #', c2.user_id) AS user_name,
                      '-' AS user_email,
                      c2.total_cambios,
                      c2.ultimo_cambio
                    FROM c c2
                    LEFT JOIN `$usersTable` u2
                      ON CAST(u2.`$idCol` AS UNSIGNED) = c2.user_id
                    WHERE c2.user_id > 0 AND u2.`$idCol` IS NULL
                ) x
                ORDER BY (x.user_id=0) ASC, x.total_cambios DESC, x.user_name ASC
            ";

            $rows  = $db->query($sql, $binds)->getResultArray();
            $stats = $db->query($cte . " SELECT pedidos_modificados FROM p", $binds)->getRowArray();

            return $this->response->setJSON([
                'ok' => true,
                'data' => $rows,
                'stats' => ['pedidos_modificados' => (int)($stats['pedidos_modificados'] ?? 0)],
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
     * GET /seguimiento/detalle/{userId}?from=YYYY-MM-DD&to=YYYY-MM-DD&offset=0&limit=50
     * Devuelve:
     *  - user_name/user_email si existe users
     *  - data: lista paginada con estado_anterior calculado
     */
    public function detalle($userId = 0)
    {
        try {
            $db = db_connect();

            $uid = (int)$userId;

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            $offset = (int)($this->request->getGet('offset') ?? 0);
            $limit  = (int)($this->request->getGet('limit') ?? 50);

            $offset = max(0, $offset);
            $limit  = min(max(1, $limit), 200);

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

            if (!$unionSQL) {
                return $this->response->setJSON([
                    'ok' => true,
                    'user_id' => $uid,
                    'user_name' => $uid === 0 ? 'Sin usuario (no registrado)' : "Usuario #$uid",
                    'user_email' => '-',
                    'total' => 0,
                    'data' => [],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // CTE: h base
            // Luego:
            // - filtramos por user_id
            // - calculamos estado_anterior si está vacío usando LAG(estado_nuevo)
            //   particionando por entidad, entidad_id
            // (requiere MySQL 8+)
            $cte = "
                WITH h AS (
                    $unionSQL
                ),
                hx AS (
                    SELECT
                      user_id,
                      created_at,
                      entidad,
                      entidad_id,
                      estado_anterior,
                      estado_nuevo,
                      source,
                      COALESCE(
                        NULLIF(estado_anterior, ''),
                        LAG(estado_nuevo) OVER (PARTITION BY entidad, entidad_id ORDER BY created_at)
                      ) AS estado_anterior_fix
                    FROM h
                    WHERE user_id = ?
                )
            ";

            $bindsDetalle = array_merge($binds, [$uid]);

            $totalRow = $db->query($cte . " SELECT COUNT(*) AS total FROM hx", $bindsDetalle)->getRowArray();
            $total = (int)($totalRow['total'] ?? 0);

            $sqlData = $cte . "
                SELECT
                  created_at,
                  entidad,
                  entidad_id,
                  estado_anterior_fix AS estado_anterior,
                  estado_nuevo,
                  source
                FROM hx
                ORDER BY created_at DESC
                LIMIT $limit OFFSET $offset
            ";

            $rows = $db->query($sqlData, $bindsDetalle)->getResultArray();

            // user name/email
            $usersTable = $this->detectUsersTable($db);
            $userName = $uid === 0 ? 'Sin usuario (no registrado)' : "Usuario #$uid";
            $userEmail = '-';

            if ($usersTable && $uid > 0) {
                $meta = $this->detectUsersMeta($db, $usersTable);
                $idCol = $meta['idCandidates'][0] ?? 'id';
                $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.`$idCol`)";
                $emailExpr = $meta['emailExpr'] ?: "NULL";

                $uSql = "
                    SELECT
                      COALESCE($nameExpr, CONCAT('Usuario #', u.`$idCol`)) AS user_name,
                      COALESCE($emailExpr, '-') AS user_email
                    FROM `$usersTable` u
                    WHERE CAST(u.`$idCol` AS UNSIGNED) = ?
                    LIMIT 1
                ";
                $u = $db->query($uSql, [$uid])->getRowArray();
                if ($u) {
                    $userName = $u['user_name'] ?? $userName;
                    $userEmail = $u['user_email'] ?? $userEmail;
                }
            }

            return $this->response->setJSON([
                'ok' => true,
                'user_id' => $uid,
                'user_name' => $userName,
                'user_email' => $userEmail,
                'total' => $total,
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

    // ======================================================
    // ================== HELPERS PRIVADOS ==================
    // ======================================================

    private function buildHistoryUnionDetail($db, ?string $from, ?string $to): array
    {
        $tables = [
            'pedidos_estado_historial',
            'pedido_estado_historial',
            'order_status_history',
            'seguimiento_cambios',
        ];

        $dateCandidates = ['created_at','fecha','date','created','updated_at','timestamp','createdOn'];
        $userCandidates = ['user_id','usuario_id','id_usuario','idUser','user','usuario','responsable_id','created_by'];

        $parts = [];
        $binds = [];
        $sources = [];

        foreach ($tables as $table) {
            if (!$this->tableExists($db, $table)) {
                continue;
            }

            $dateField = $this->bestExistingField($db, $table, $dateCandidates);
            if (!$dateField) continue;

            $userField = $this->pickUserFieldWithData($db, $table, $userCandidates);

            $userExpr = $userField
                ? "CAST(COALESCE(NULLIF(TRIM(CAST($userField AS CHAR)), ''), '0') AS UNSIGNED)"
                : "0";

            // entidad / entidad_id
            $entField = $this->bestExistingField($db, $table, ['entidad','entity','tabla','table_name','modulo','tipo']);
            $idField  = $this->bestExistingField($db, $table, ['entidad_id','entity_id','registro_id','record_id','row_id','pedido_id','order_id','id_pedido','id_order']);

            $entExpr = "LOWER('$table')";
            $idExpr  = "NULL";

            // Si tiene entidad/id propios
            if ($entField) $entExpr = "LOWER(CAST($entField AS CHAR))";

            if ($idField) {
                $idExpr = "CAST($idField AS UNSIGNED)";
                // Si no hay entidad, inferimos por el idField
                if (!$entField) {
                    if (stripos($idField, 'order') !== false) $entExpr = "'order'";
                    if (stripos($idField, 'pedido') !== false) $entExpr = "'pedido'";
                }
            }

            // estado antes/después
            $oldField = $this->bestExistingField($db, $table, ['estado_anterior','antes','old_status','from_status','valor_antes','old_value']);
            $newField = $this->bestExistingField($db, $table, ['estado_nuevo','despues','status','estado','new_status','to_status','valor_despues','new_value']);

            $oldExpr = $oldField ? "CAST($oldField AS CHAR)" : "NULL";
            $newExpr = $newField ? "CAST($newField AS CHAR)" : "NULL";

            $part = "
                SELECT
                  $userExpr AS user_id,
                  $dateField AS created_at,
                  $entExpr AS entidad,
                  $idExpr AS entidad_id,
                  $oldExpr AS estado_anterior,
                  $newExpr AS estado_nuevo,
                  '$table' AS source
                FROM $table
                WHERE 1=1
            ";

            if ($from) {
                $part .= " AND $dateField >= ?";
                $binds[] = $from . " 00:00:00";
            }
            if ($to) {
                $part .= " AND $dateField <= ?";
                $binds[] = $to . " 23:59:59";
            }

            $parts[] = $part;
            $sources[] = $userField ? "$table($userField,$dateField)" : "$table(NO_USER,$dateField)";
        }

        if (!$parts) {
            return [null, [], []];
        }

        $unionSQL = implode("\nUNION ALL\n", $parts);
        return [$unionSQL, $binds, $sources];
    }

    private function detectUsersTable($db): ?string
    {
        // Preferimos "users" (tú dijiste que se llama users)
        $candidates = ['users','Usuarios','usuario','usuarios','user','tbl_users'];

        foreach ($candidates as $t) {
            if ($this->tableExists($db, $t)) return $t;
        }
        return null;
    }

    private function detectUsersMeta($db, string $usersTable): array
    {
        $cols = $this->getColumns($db, $usersTable);

        $idCandidates = array_values(array_filter([
            in_array('id', $cols) ? 'id' : null,
            in_array('user_id', $cols) ? 'user_id' : null,
            in_array('id_usuario', $cols) ? 'id_usuario' : null,
            in_array('usuario_id', $cols) ? 'usuario_id' : null,
        ]));

        if (!$idCandidates) $idCandidates = ['id'];

        // Nombre: intentamos nombre+apellido, sino name, username, etc.
        $nameExpr = null;
        if (in_array('nombre', $cols) && in_array('apellido', $cols)) {
            $nameExpr = "CONCAT_WS(' ', u.nombre, u.apellido)";
        } elseif (in_array('name', $cols) && in_array('last_name', $cols)) {
            $nameExpr = "CONCAT_WS(' ', u.name, u.last_name)";
        } elseif (in_array('nombre', $cols)) {
            $nameExpr = "u.nombre";
        } elseif (in_array('name', $cols)) {
            $nameExpr = "u.name";
        } elseif (in_array('username', $cols)) {
            $nameExpr = "u.username";
        }

        $emailExpr = null;
        if (in_array('email', $cols)) $emailExpr = "u.email";
        elseif (in_array('correo', $cols)) $emailExpr = "u.correo";

        return [
            'idCandidates' => $idCandidates,
            'nameExpr' => $nameExpr,
            'emailExpr' => $emailExpr,
        ];
    }

    private function tableExists($db, string $table): bool
    {
        try {
            $db->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getColumns($db, string $table): array
    {
        try {
            $rows = $db->query("SHOW COLUMNS FROM `$table`")->getResultArray();
            return array_map(fn($r) => $r['Field'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function bestExistingField($db, string $table, array $candidates): ?string
    {
        $cols = $this->getColumns($db, $table);
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) return $c;
        }
        return null;
    }

    private function pickUserFieldWithData($db, string $table, array $candidates): ?string
    {
        $cols = $this->getColumns($db, $table);
        $existing = array_values(array_filter($candidates, fn($c) => in_array($c, $cols, true)));
        if (!$existing) return null;

        // elegimos el que tenga más valores no nulos (muestra pequeña para no cargar DB)
        $best = $existing[0];
        $bestCount = -1;

        foreach ($existing as $col) {
            try {
                $sql = "SELECT SUM(CASE WHEN `$col` IS NOT NULL AND TRIM(CAST(`$col` AS CHAR)) <> '' THEN 1 ELSE 0 END) AS c
                        FROM `$table`";
                $row = $db->query($sql)->getRowArray();
                $c = (int)($row['c'] ?? 0);
                if ($c > $bestCount) {
                    $bestCount = $c;
                    $best = $col;
                }
            } catch (\Throwable $e) {
                // ignorar
            }
        }

        return $best;
    }
}
