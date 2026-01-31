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
     * Devuelve TODOS los users (incluye 0 cambios) y agrega:
     * - total_cambios
     * - pedidos_tocados (distinct entidad_id)
     * - confirmados (según regla especial)
     * - disenos (pedido/order -> estado_nuevo = disenad%)
     * - ultimo_cambio
     * - stats.pedidos_modificados (general)
     */
    public function resumen()
    {
        try {
            $db = db_connect();

            $from = $this->request->getGet('from');
            $to   = $this->request->getGet('to');

            if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'from inválido (usa YYYY-MM-DD)']);
            }
            if ($to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'message'=>'to inválido (usa YYYY-MM-DD)']);
            }

            [$unionSQL, $binds, $sourcesUsed] = $this->buildHistoryUnionDetail($db, $from, $to);
            $usersTable = $this->detectUsersTable($db);

            if (!$unionSQL) {
                // sin historial -> users con 0
                if (!$usersTable) {
                    return $this->response->setJSON([
                        'ok' => true,
                        'data' => [],
                        'stats' => ['pedidos_modificados' => 0],
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
                        0 AS confirmados,
                        0 AS disenos,
                        NULL AS ultimo_cambio
                    FROM `$usersTable` u
                    ORDER BY user_name ASC
                ";

                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $db->query($sql)->getResultArray(),
                    'stats' => ['pedidos_modificados' => 0],
                    'sources' => [],
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            // Normalizadores (IMPORTANTE: con % para soportar Confirmados/Confirmado etc)
            $normNewH = $this->normSql("h.estado_nuevo");
            $normNewX = $this->normSql("x.estado_nuevo");
            $normPrevX = $this->normSql("x.estado_prev");

            // condición de estado anterior para contar "confirmados" (3 casos)
            $prevCond = $this->confirmadoPrevConditionSql($normPrevX);

            /**
             * ✅ Lógica Confirmados "especial":
             * - Tomamos historial completo (h)
             * - Construimos x con estado_prev real:
             *   COALESCE(estado_anterior, LAG(estado_nuevo))
             * - Filtramos eventos donde:
             *   new = confirmad%
             *   prev ∈ (vacío / faltan archivos / por preparar)
             * - Por pedido, solo cuenta el PRIMER evento que cumpla (rn=1)
             * - Se lo atribuimos al user_id de ese evento
             */
            $cte = "
                WITH h AS (
                    $unionSQL
                ),
                x AS (
                    SELECT
                        t.user_id,
                        t.created_at,
                        t.entidad,
                        t.entidad_id,
                        COALESCE(
                            NULLIF(TRIM(CAST(t.estado_anterior AS CHAR)), ''),
                            LAG(t.estado_nuevo) OVER (
                                PARTITION BY t.entidad, t.entidad_id
                                ORDER BY t.created_at
                            )
                        ) AS estado_prev,
                        t.estado_nuevo
                    FROM h t
                ),
                conf_evt AS (
                    SELECT
                        x.user_id,
                        x.entidad,
                        x.entidad_id,
                        x.created_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY x.entidad, x.entidad_id
                            ORDER BY x.created_at
                        ) AS rn
                    FROM x
                    WHERE x.entidad IN ('pedido','order')
                      AND x.entidad_id IS NOT NULL AND x.entidad_id <> 0
                      AND $normNewX LIKE 'confirmad%'
                      AND $prevCond
                ),
                conf AS (
                    SELECT user_id, COUNT(DISTINCT entidad_id) AS confirmados
                    FROM conf_evt
                    WHERE rn = 1
                    GROUP BY user_id
                ),
                c AS (
                    SELECT
                        h.user_id,
                        COUNT(*) AS total_cambios,
                        MAX(h.created_at) AS ultimo_cambio,

                        COUNT(DISTINCT CASE
                            WHEN h.entidad IN ('pedido','order')
                              AND h.entidad_id IS NOT NULL AND h.entidad_id <> 0
                            THEN h.entidad_id END
                        ) AS pedidos_tocados,

                        COUNT(DISTINCT CASE
                            WHEN h.entidad IN ('pedido','order')
                              AND h.entidad_id IS NOT NULL AND h.entidad_id <> 0
                              AND $normNewH LIKE 'disenad%'
                            THEN h.entidad_id END
                        ) AS disenos

                    FROM h
                    GROUP BY h.user_id
                ),
                p AS (
                    SELECT COUNT(DISTINCT CONCAT(entidad,':',entidad_id)) AS pedidos_modificados
                    FROM h
                    WHERE entidad IN ('pedido','order')
                      AND entidad_id IS NOT NULL AND entidad_id <> 0
                )
            ";

            if (!$usersTable) {
                $sql = $cte . "
                    SELECT
                        c.user_id,
                        CASE WHEN c.user_id = 0 THEN 'Sin usuario (no registrado)'
                             ELSE CONCAT('Usuario #', c.user_id) END AS user_name,
                        '-' AS user_email,
                        c.total_cambios,
                        c.pedidos_tocados,
                        COALESCE(conf.confirmados, 0) AS confirmados,
                        c.disenos,
                        c.ultimo_cambio
                    FROM c
                    LEFT JOIN conf ON conf.user_id = c.user_id
                    ORDER BY (c.user_id=0) ASC, c.total_cambios DESC, user_name ASC
                ";

                $rows = $db->query($sql, $binds)->getResultArray();
                $stats = $db->query($cte . " SELECT pedidos_modificados FROM p", $binds)->getRowArray();

                return $this->response->setJSON([
                    'ok' => true,
                    'data' => $rows,
                    'stats' => ['pedidos_modificados' => (int)($stats['pedidos_modificados'] ?? 0)],
                    'sources' => $sourcesUsed,
                    'range' => ['from' => $from, 'to' => $to],
                ]);
            }

            $meta = $this->detectUsersMeta($db, $usersTable);
            $joinCol = $meta['idCandidates'][0] ?? 'id';
            $nameExpr  = $meta['nameExpr']  ?: "CONCAT('Usuario #', u.`$joinCol`)";
            $emailExpr = $meta['emailExpr'] ?: "NULL";

            $sql = $cte . "
                SELECT * FROM (
                    SELECT
                        CAST(u.`$joinCol` AS UNSIGNED) AS user_id,
                        COALESCE($nameExpr, CONCAT('Usuario #', u.`$joinCol`)) AS user_name,
                        COALESCE($emailExpr, '-') AS user_email,
                        COALESCE(c.total_cambios, 0) AS total_cambios,
                        COALESCE(c.pedidos_tocados, 0) AS pedidos_tocados,
                        COALESCE(conf.confirmados, 0) AS confirmados,
                        COALESCE(c.disenos, 0) AS disenos,
                        c.ultimo_cambio
                    FROM `$usersTable` u
                    LEFT JOIN c ON c.user_id = CAST(u.`$joinCol` AS UNSIGNED)
                    LEFT JOIN conf ON conf.user_id = CAST(u.`$joinCol` AS UNSIGNED)

                    UNION ALL

                    SELECT
                        0 AS user_id,
                        'Sin usuario (no registrado)' AS user_name,
                        '-' AS user_email,
                        COALESCE(c0.total_cambios, 0) AS total_cambios,
                        COALESCE(c0.pedidos_tocados, 0) AS pedidos_tocados,
                        COALESCE(conf0.confirmados, 0) AS confirmados,
                        COALESCE(c0.disenos, 0) AS disenos,
                        c0.ultimo_cambio
                    FROM (SELECT 0 AS user_id) d
                    LEFT JOIN c c0 ON c0.user_id = 0
                    LEFT JOIN conf conf0 ON conf0.user_id = 0

                    UNION ALL

                    SELECT
                        c2.user_id,
                        CONCAT('Usuario #', c2.user_id) AS user_name,
                        '-' AS user_email,
                        c2.total_cambios,
                        c2.pedidos_tocados,
                        COALESCE(conf2.confirmados, 0) AS confirmados,
                        c2.disenos,
                        c2.ultimo_cambio
                    FROM c c2
                    LEFT JOIN conf conf2 ON conf2.user_id = c2.user_id
                    LEFT JOIN `$usersTable` u2 ON CAST(u2.`$joinCol` AS UNSIGNED) = c2.user_id
                    WHERE c2.user_id > 0 AND u2.`$joinCol` IS NULL
                ) x
                ORDER BY (x.user_id=0) ASC, x.total_cambios DESC, x.user_name ASC
            ";

            $rows = $db->query($sql, $binds)->getResultArray();
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
            return $this->response->setStatusCode(500)->setJSON(['ok'=>false,'message'=>$e->getMessage()]);
        }
    }

    /**
     * GET /seguimiento/detalle/{userId}?from=...&to=...&limit=50&offset=0
     * - Devuelve detalle con estado_anterior REAL calculado (COALESCE + LAG)
     * - KPIs: confirmados (regla especial) y disenos
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
            $userInfo = $this->getUserInfo($db, $usersTable, $userId);

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
                    'pedidos' => [],
                    'kpis' => ['confirmados'=>0,'disenos'=>0],
                    'range' => ['from'=>$from,'to'=>$to],
                ]);
            }

            // total cambios del usuario
            $totalSql = "SELECT COUNT(*) as total FROM ($unionSQL) t WHERE t.user_id = ?";
            $total = (int)($db->query($totalSql, array_merge($binds, [$userId]))->getRowArray()['total'] ?? 0);

            // pagina con LAG para el "antes" real
            $pageSql = "
                SELECT
                    x.user_id,
                    x.created_at,
                    x.entidad,
                    x.entidad_id,
                    x.estado_prev AS estado_anterior,
                    x.estado_nuevo
                FROM (
                    SELECT
                        t.*,
                        COALESCE(
                            NULLIF(TRIM(CAST(t.estado_anterior AS CHAR)), ''),
                            LAG(t.estado_nuevo) OVER (
                                PARTITION BY t.entidad, t.entidad_id
                                ORDER BY t.created_at
                            )
                        ) AS estado_prev
                    FROM ($unionSQL) t
                ) x
                WHERE x.user_id = ?
                ORDER BY x.created_at DESC
                LIMIT ? OFFSET ?
            ";
            $rows = $db->query($pageSql, array_merge($binds, [$userId, $limit, $offset]))->getResultArray();

            // chips de pedidos tocados
            $pedidosSql = "
                SELECT
                    t.entidad,
                    t.entidad_id,
                    COUNT(*) AS cambios,
                    MAX(t.created_at) AS ultimo
                FROM ($unionSQL) t
                WHERE t.user_id = ?
                  AND t.entidad IN ('pedido','order')
                  AND t.entidad_id IS NOT NULL
                  AND t.entidad_id <> 0
                GROUP BY t.entidad, t.entidad_id
                ORDER BY ultimo DESC
                LIMIT 300
            ";
            $pedidos = $db->query($pedidosSql, array_merge($binds, [$userId]))->getResultArray();

            // ✅ KPIs: confirmados con REGLA ESPECIAL + disenos normal
            $normNewX = $this->normSql("x.estado_nuevo");
            $normPrevX = $this->normSql("x.estado_prev");
            $prevCond = $this->confirmadoPrevConditionSql($normPrevX);

            $kpiSql = "
                WITH h AS (
                    $unionSQL
                ),
                x AS (
                    SELECT
                        t.user_id,
                        t.entidad,
                        t.entidad_id,
                        t.created_at,
                        COALESCE(
                            NULLIF(TRIM(CAST(t.estado_anterior AS CHAR)), ''),
                            LAG(t.estado_nuevo) OVER (
                                PARTITION BY t.entidad, t.entidad_id
                                ORDER BY t.created_at
                            )
                        ) AS estado_prev,
                        t.estado_nuevo
                    FROM h t
                ),
                conf_evt AS (
                    SELECT
                        x.user_id,
                        x.entidad_id,
                        ROW_NUMBER() OVER (
                            PARTITION BY x.entidad, x.entidad_id
                            ORDER BY x.created_at
                        ) AS rn
                    FROM x
                    WHERE x.entidad IN ('pedido','order')
                      AND x.entidad_id IS NOT NULL AND x.entidad_id <> 0
                      AND $normNewX LIKE 'confirmad%'
                      AND $prevCond
                )
                SELECT
                    COUNT(DISTINCT CASE
                        WHEN conf_evt.user_id = ?
                         AND conf_evt.rn = 1
                        THEN conf_evt.entidad_id END
                    ) AS confirmados,
                    (
                        SELECT COUNT(DISTINCT CASE
                            WHEN t.user_id = ?
                              AND t.entidad IN ('pedido','order')
                              AND t.entidad_id IS NOT NULL AND t.entidad_id <> 0
                              AND " . $this->normSql("t.estado_nuevo") . " LIKE 'disenad%'
                            THEN t.entidad_id END
                        )
                        FROM h t
                    ) AS disenos
                FROM conf_evt
            ";

            $kpiRow = $db->query($kpiSql, array_merge($binds, [$userId, $userId]))->getRowArray();

            return $this->response->setJSON([
                'ok' => true,
                'user_id' => $userId,
                'user_name' => $userInfo['name'],
                'user_email' => $userInfo['email'],
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'data' => $rows,
                'pedidos' => $pedidos,
                'kpis' => [
                    'confirmados' => (int)($kpiRow['confirmados'] ?? 0),
                    'disenos' => (int)($kpiRow['disenos'] ?? 0),
                ],
                'sources' => $sourcesUsed,
                'range' => ['from'=>$from,'to'=>$to],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Seguimiento/detalle ERROR: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['ok'=>false,'message'=>$e->getMessage()]);
        }
    }

    // =========================================================
    // ✅ Regla de prev para "confirmados especiales"
    // =========================================================
    private function confirmadoPrevConditionSql(string $prevNormExpr): string
    {
        // prev vacío OR faltan archivos OR por preparar
        return "(
            $prevNormExpr IS NULL
            OR $prevNormExpr = ''
            OR $prevNormExpr = '-'
            OR $prevNormExpr = '0'
            OR $prevNormExpr LIKE 'faltan%archiv%'
            OR $prevNormExpr LIKE 'por%prepar%'
        )";
    }

    // ✅ Normalización robusta (acentos + ñ)
    private function normSql(string $expr): string
    {
        return "LOWER(TRIM(
            REPLACE(REPLACE(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            REPLACE(REPLACE(
                $expr,
                'Á','a'),'á','a'),
                'É','e'),'é','e'),
                'Í','i'),'í','i'),
                'Ó','o'),'ó','o'),
                'Ú','u'),'ú','u'),
                'Ñ','n'),'ñ','n')
        ))";
    }

    // ---------------- USERS ----------------

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
                $name = $row['name'] ?? ($row['username'] ?? ($row['usuario'] ?? ($row['nombre'] ?? ($row['nombre_completo'] ?? null))));
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

    // ---------------- HISTORIAL ----------------

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

            // Tabla especial: seguimiento_cambios
            if ($table === 'seguimiento_cambios') {
                $entField = $this->bestExistingField($db, $table, ['entidad','tabla','table_name','entity','modulo']);
                $entExpr  = $entField ? "LOWER(CAST($entField AS CHAR))" : "'cambio'";

                $idField = $this->bestExistingField($db, $table, ['entidad_id','registro_id','record_id','row_id','pedido_id','order_id','id_registro']);
                $idExpr  = $idField ? "CAST($idField AS UNSIGNED)" : "NULL";

                $oldField = $this->bestExistingField($db, $table, ['antes','valor_antes','old_value','old','from_value']);
                $newField = $this->bestExistingField($db, $table, ['despues','valor_despues','new_value','new','to_value']);

                $oldExpr = $oldField ? "CAST($oldField AS CHAR)" : "NULL";
                $newExpr = $newField ? "CAST($newField AS CHAR)" : "NULL";

                $part = "
                    SELECT
                        $userExpr AS user_id,
                        $dateField AS created_at,
                        $entExpr AS entidad,
                        $idExpr AS entidad_id,
                        $oldExpr AS estado_anterior,
                        $newExpr AS estado_nuevo
                    FROM `$table`
                    WHERE 1=1
                ";

                if ($from) { $part .= " AND $dateField >= ?"; $binds[] = $from . " 00:00:00"; }
                if ($to)   { $part .= " AND $dateField <= ?"; $binds[] = $to . " 23:59:59"; }

                $parts[] = $part;
                $sources[] = $userField ? "$table($userField,$dateField)" : "$table(NO_USER,$dateField)";
                continue;
            }

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
                    $userExpr AS user_id,
                    $dateField AS created_at,
                    '$entidad' AS entidad,
                    $entityExpr AS entidad_id,
                    $oldExpr AS estado_anterior,
                    $newExpr AS estado_nuevo
                FROM `$table`
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
                    FROM `$table`
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
