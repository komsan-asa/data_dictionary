<?php
/**
 * อ่านโครงสร้างจากฐานข้อมูลต้นทาง (MySQL/MariaDB หรือ PostgreSQL)
 * ใช้เฉพาะคำสั่ง SELECT กับ catalog ของระบบ ไม่แตะข้อมูลในตาราง
 */
declare(strict_types=1);

if (!defined('DD_APP')) {
    http_response_code(403);
    exit;
}

class Introspector
{
    public static function connect(array $p): PDO
    {
        $driver = (string)$p['conn_driver'];
        $host = (string)$p['conn_host'];
        $dbname = (string)$p['conn_database'];
        if ($host === '' || $dbname === '' || !in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new ApiError('โปรเจ็คนี้ยังตั้งค่า connection ไม่ครบ (ชนิดฐานข้อมูล, host, ชื่อฐานข้อมูล)');
        }
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new ApiError("PHP บนเครื่องแม่ข่ายยังไม่ได้เปิด extension pdo_$driver", 500);
        }
        if ($driver === 'mysql') {
            $charset = preg_match('/^[a-z0-9_]+$/i', (string)$p['conn_charset']) ? $p['conn_charset'] : 'utf8mb4';
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, (int)($p['conn_port'] ?: 3306), $dbname, $charset);
        } else {
            $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;options='--client_encoding=UTF8'", $host, (int)($p['conn_port'] ?: 5432), $dbname);
        }
        try {
            return new PDO($dsn, (string)$p['conn_username'], decrypt_secret($p['conn_password_enc']), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
        } catch (PDOException $e) {
            throw new ApiError('เชื่อมต่อฐานข้อมูลต้นทางไม่ได้: ' . $e->getMessage());
        }
    }

    /** ทดสอบการเชื่อมต่อ คืนรุ่นของเซิร์ฟเวอร์และจำนวนตาราง */
    public static function test(array $p): array
    {
        $pdo = self::connect($p);
        if ($p['conn_driver'] === 'mysql') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
            $st->execute([$p['conn_database']]);
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                                 WHERE n.nspname = ? AND c.relkind IN ('r','p','v','m')");
            $st->execute([self::pgSchema($p)]);
        }
        return [
            'server_version' => (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'table_count'    => (int)$st->fetchColumn(),
        ];
    }

    /**
     * คืนโครงสร้างทั้งฐานข้อมูลในรูปแบบกลาง:
     * ['tables' => array, 'indexes' => array, 'columns' => Generator]
     * columns เป็น generator อ่านทีละแถว เพื่อไม่ให้ฐานข้อมูลขนาดใหญ่กินหน่วยความจำ
     * $progress คือฟังก์ชันรับข้อความความคืบหน้า
     */
    public static function read(array $p, ?callable $progress = null): array
    {
        $say = $progress ?: function ($m) {
        };
        $say('กำลังเชื่อมต่อฐานข้อมูลต้นทาง');
        $pdo = self::connect($p);
        return $p['conn_driver'] === 'mysql'
            ? self::readMysql($pdo, (string)$p['conn_database'], $say)
            : self::readPgsql($pdo, self::pgSchema($p), $say);
    }

    private static function pgSchema(array $p): string
    {
        return ($p['conn_schema'] ?? '') !== '' ? (string)$p['conn_schema'] : 'public';
    }

    private static function rows(PDO $pdo, string $sql, array $params): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private static function readMysql(PDO $pdo, string $db, callable $say): array
    {
        $say('กำลังอ่านรายชื่อตาราง');
        // TABLE_ROWS ทำให้ MySQL ต้องเปิดทุกตารางเต็มรูปแบบและคำนวณสถิติใหม่ ซึ่งหนักมากบนฐานข้อมูลใหญ่
        // จึงไม่อ่านเป็นค่าเริ่มต้น เปิดได้ด้วย 'read_row_estimate' => true ใน config
        $rowsExpr = !empty(config()['read_row_estimate']) ? 'TABLE_ROWS' : 'NULL';
        $tables = self::rows($pdo,
            "SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_COLLATION AS collation,
                    $rowsExpr AS row_estimate, TABLE_COMMENT AS comment
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$db]);
        foreach ($tables as &$t) {
            $t['type'] = stripos((string)$t['type'], 'VIEW') !== false ? 'VIEW' : 'BASE TABLE';
            if ($t['type'] === 'VIEW' && strtoupper((string)$t['comment']) === 'VIEW') {
                $t['comment'] = '';
            }
        }
        unset($t);

        $fk = [];
        foreach (self::rows($pdo,
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$db]) as $r) {
            $fk[strtolower($r['t'] . '.' . $r['c'])] = [$r['rt'], $r['rc']];
        }

        $say('พบ ' . number_format(count($tables)) . ' ตาราง กำลังอ่าน index');
        $indexes = self::rows($pdo,
            "SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, (1 - MIN(NON_UNIQUE)) AS is_unique,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS column_list
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
             GROUP BY TABLE_NAME, INDEX_NAME", [$db]);

        return ['tables' => $tables, 'indexes' => $indexes, 'columns' => self::mysqlColumns($pdo, $db, $fk)];
    }

    private static function mysqlColumns(PDO $pdo, string $db, array $fk): Generator
    {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $pdo->prepare(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, ORDINAL_POSITION AS position,
                    DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable,
                    COLUMN_DEFAULT AS default_value, COLUMN_KEY AS column_key, EXTRA AS extra, COLUMN_COMMENT AS comment
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?");
        $st->execute([$db]);
        while ($c = $st->fetch()) {
            $c['nullable'] = strtoupper((string)$c['nullable']) === 'YES' ? 1 : 0;
            // MariaDB รายงานค่าเริ่มต้นของคอลัมน์ที่ไม่มี default เป็นข้อความ 'NULL'
            if ($c['nullable'] && strtoupper((string)$c['default_value']) === 'NULL') {
                $c['default_value'] = null;
            }
            $ref = $fk[strtolower($c['table_name'] . '.' . $c['name'])] ?? [null, null];
            $c['ref_table'] = $ref[0];
            $c['ref_column'] = $ref[1];
            yield $c;
        }
    }

    private static function readPgsql(PDO $pdo, string $schema, callable $say): array
    {
        $say('กำลังอ่านรายชื่อตาราง');
        $tables = self::rows($pdo,
            "SELECT c.relname AS name,
                    CASE WHEN c.relkind IN ('v','m') THEN 'VIEW' ELSE 'BASE TABLE' END AS type,
                    NULL AS engine, NULL AS collation,
                    CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END AS row_estimate,
                    obj_description(c.oid, 'pg_class') AS comment
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind IN ('r','p','v','m') ORDER BY c.relname", [$schema]);

        $indexes = self::rows($pdo,
            "SELECT t.relname AS table_name, i.relname AS name,
                    CASE WHEN ix.indisunique THEN 1 ELSE 0 END AS is_unique,
                    CASE WHEN ix.indisprimary THEN 1 ELSE 0 END AS is_primary,
                    array_to_string(ARRAY(
                        SELECT a.attname FROM unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)
                        JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
                        ORDER BY k.ord), ',') AS column_list
             FROM pg_index ix
             JOIN pg_class t ON t.oid = ix.indrelid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = ?", [$schema]);

        // สร้างค่า column_key แบบเดียวกับ MySQL จาก index
        $keys = [];
        foreach ($indexes as $ix) {
            $cols = $ix['column_list'] === '' ? [] : explode(',', $ix['column_list']);
            foreach ($cols as $n => $col) {
                $k = strtolower($ix['table_name'] . '.' . $col);
                if ($ix['is_primary']) {
                    $keys[$k] = 'PRI';
                } elseif ($ix['is_unique'] && count($cols) === 1 && ($keys[$k] ?? '') !== 'PRI') {
                    $keys[$k] = 'UNI';
                } elseif ($n === 0 && !isset($keys[$k])) {
                    $keys[$k] = 'MUL';
                }
            }
        }

        $fk = [];
        foreach (self::rows($pdo,
            "SELECT t.relname AS t, a.attname AS c, rt.relname AS rt, ra.attname AS rc
             FROM pg_constraint con
             JOIN pg_class t ON t.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_class rt ON rt.oid = con.confrelid
             JOIN LATERAL unnest(con.conkey, con.confkey) AS k(a, ra) ON true
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.a
             JOIN pg_attribute ra ON ra.attrelid = rt.oid AND ra.attnum = k.ra
             WHERE con.contype = 'f' AND n.nspname = ?", [$schema]) as $r) {
            $fk[strtolower($r['t'] . '.' . $r['c'])] = [$r['rt'], $r['rc']];
        }

        return ['tables' => $tables, 'indexes' => $indexes, 'columns' => self::pgsqlColumns($pdo, $schema, $keys, $fk)];
    }

    private static function pgsqlColumns(PDO $pdo, string $schema, array $keys, array $fk): Generator
    {
        $st = $pdo->prepare(
            "SELECT c.relname AS table_name, a.attname AS name, a.attnum AS position,
                    ty.typname AS data_type, format_type(a.atttypid, a.atttypmod) AS column_type,
                    CASE WHEN a.attnotnull THEN 0 ELSE 1 END AS nullable,
                    pg_get_expr(d.adbin, d.adrelid) AS default_value,
                    col_description(c.oid, a.attnum) AS comment
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_type ty ON ty.oid = a.atttypid
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE n.nspname = ? AND c.relkind IN ('r','p','v','m') AND a.attnum > 0 AND NOT a.attisdropped");
        $st->execute([$schema]);
        while ($c = $st->fetch()) {
            $k = strtolower($c['table_name'] . '.' . $c['name']);
            $c['column_key'] = $keys[$k] ?? '';
            $c['extra'] = strpos((string)$c['default_value'], 'nextval(') === 0 ? 'auto_increment' : '';
            $ref = $fk[$k] ?? [null, null];
            $c['ref_table'] = $ref[0];
            $c['ref_column'] = $ref[1];
            yield $c;
        }
    }
}
