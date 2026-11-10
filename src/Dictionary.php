<?php
/**
 * จัดเก็บโครงสร้าง, นำเข้าคำอธิบาย, ส่งออก Data Dictionary
 */
declare(strict_types=1);

if (!defined('DD_APP')) {
    http_response_code(403);
    exit;
}

class Dictionary
{
    // ------------------------------------------------------------------
    //  สถิติ
    // ------------------------------------------------------------------

    public static function stats(int $projectId): array
    {
        $t = q("SELECT COUNT(*) AS total, COALESCE(SUM(description IS NOT NULL AND description <> ''), 0) AS described
                FROM dd_tables WHERE project_id = ? AND is_dropped = 0", [$projectId])->fetch();
        $c = q("SELECT COUNT(*) AS total, COALESCE(SUM(description IS NOT NULL AND description <> ''), 0) AS described
                FROM dd_columns WHERE project_id = ? AND is_dropped = 0", [$projectId])->fetch();
        return [
            'tables'            => (int)$t['total'],
            'tables_described'  => (int)$t['described'],
            'columns'           => (int)$c['total'],
            'columns_described' => (int)$c['described'],
        ];
    }

    private static function bulk(string $head, int $width, array $rows, string $tail, int $batch): void
    {
        $one = '(' . implode(',', array_fill(0, $width, '?')) . ')';
        foreach (array_chunk($rows, $batch) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                foreach ($r as $v) {
                    $params[] = $v;
                }
            }
            q($head . ' VALUES ' . implode(',', array_fill(0, count($chunk), $one)) . ' ' . $tail, $params);
        }
    }

    private static function log(int $projectId, string $kind, string $actor, string $status, array $n, string $message, string $started): void
    {
        q("INSERT INTO sync_logs (project_id, kind, actor, status, tables_total, tables_new, columns_total, columns_new, columns_dropped, message, started_at, finished_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", [
            $projectId, $kind, $actor, $status,
            $n['tables_total'] ?? 0, $n['tables_new'] ?? 0, $n['columns_total'] ?? 0, $n['columns_new'] ?? 0, $n['columns_dropped'] ?? 0,
            mb_substr($message, 0, 2000), $started, now(),
        ]);
    }

    public static function logError(int $projectId, string $kind, string $actor, string $message, string $started): void
    {
        self::log($projectId, $kind, $actor, 'error', [], $message, $started);
    }

    // ------------------------------------------------------------------
    //  ภาพรวมทุกโปรเจ็ค (หน้าแรก)
    // ------------------------------------------------------------------

    /** จัดกลุ่มที่มาของความหมายจากค่า described_by */
    private static function sourceKind(?string $by): string
    {
        $by = (string)$by;
        if ($by === 'เดาอัตโนมัติ') { // ค่าเดียวกับ Guesser::BY
            return 'guessed';
        }
        if ($by === 'comment ในฐานข้อมูล') {
            return 'comment';
        }
        return stripos($by, 'api') === 0 ? 'api' : 'human';
    }

    public static function dashboard(): array
    {
        $store = class_exists('Guesser') && Guesser::storeReady();
        $projects = [];
        foreach (q('SELECT * FROM projects ORDER BY name') as $p) {
            $projects[(int)$p['id']] = project_public($p) + [
                'tables' => 0, 'tables_described' => 0, 'columns' => 0, 'described' => 0,
                'by' => ['human' => 0, 'api' => 0, 'comment' => 0, 'guessed' => 0], 'pending' => 0, 'sync' => null,
            ];
        }
        foreach (q("SELECT project_id, COUNT(*) AS n, COALESCE(SUM(description IS NOT NULL AND description <> ''), 0) AS d
                    FROM dd_tables WHERE is_dropped = 0 GROUP BY project_id") as $r) {
            if (isset($projects[(int)$r['project_id']])) {
                $projects[(int)$r['project_id']]['tables'] = (int)$r['n'];
                $projects[(int)$r['project_id']]['tables_described'] = (int)$r['d'];
            }
        }
        $writers = [];
        $pend = $store ? 'COALESCE(SUM(suggest_json IS NOT NULL), 0)' : '0';
        foreach (q("SELECT project_id, described_by, COUNT(*) AS n,
                           COALESCE(SUM(description IS NOT NULL AND description <> ''), 0) AS d, $pend AS s
                    FROM dd_columns WHERE is_dropped = 0 GROUP BY project_id, described_by") as $r) {
            $pid = (int)$r['project_id'];
            if (!isset($projects[$pid])) {
                continue;
            }
            $d = (int)$r['d'];
            $kind = self::sourceKind($r['described_by']);
            $projects[$pid]['columns'] += (int)$r['n'];
            $projects[$pid]['described'] += $d;
            $projects[$pid]['by'][$kind] += $d;
            $projects[$pid]['pending'] += (int)$r['s'];
            if ($d > 0 && ($kind === 'human' || $kind === 'api')) {
                $name = trim((string)$r['described_by']) !== '' ? (string)$r['described_by'] : 'ไม่ระบุผู้เขียน';
                $writers[$name] = ['name' => $name, 'kind' => $kind, 'columns' => ($writers[$name]['columns'] ?? 0) + $d];
            }
        }
        // งานซิงค์ล่าสุดของแต่ละโปรเจ็ค
        foreach (q("SELECT l.project_id, l.status, l.message, l.started_at, l.finished_at
                    FROM sync_logs l JOIN (SELECT MAX(id) AS id FROM sync_logs WHERE kind = 'sync' GROUP BY project_id) m ON m.id = l.id") as $r) {
            if (isset($projects[(int)$r['project_id']])) {
                $projects[(int)$r['project_id']]['sync'] = ['status' => $r['status'], 'message' => (string)$r['message'], 'at' => $r['finished_at'] ?: $r['started_at']];
            }
        }

        $totals = ['projects' => count($projects), 'tables' => 0, 'tables_described' => 0, 'columns' => 0, 'described' => 0,
            'human' => 0, 'api' => 0, 'comment' => 0, 'guessed' => 0, 'pending' => 0];
        foreach ($projects as $p) {
            foreach (['tables', 'tables_described', 'columns', 'described', 'pending'] as $k) {
                $totals[$k] += $p[$k];
            }
            foreach ($p['by'] as $k => $v) {
                $totals[$k] += $v;
            }
        }
        usort($writers, function ($a, $b) {
            return $b['columns'] <=> $a['columns'];
        });
        if (count($writers) > 8) { // เกิน 8 คน รวมท้ายแถวเป็น "อื่น ๆ"
            $rest = array_sum(array_column(array_slice($writers, 7), 'columns'));
            $writers = array_merge(array_slice($writers, 0, 7), [['name' => 'อื่น ๆ', 'kind' => 'other', 'columns' => $rest]]);
        }

        $pending = [];
        if ($store && $totals['pending'] > 0) {
            $pending = q("SELECT t.table_name, p.code AS project, COUNT(*) AS n, MAX(c.sampled_at) AS at
                          FROM dd_columns c JOIN dd_tables t ON t.id = c.table_id JOIN projects p ON p.id = c.project_id
                          WHERE c.is_dropped = 0 AND t.is_dropped = 0 AND c.suggest_json IS NOT NULL
                          GROUP BY t.id, t.table_name, p.code ORDER BY n DESC, t.table_name LIMIT 8")->fetchAll();
        }
        $activity = q("SELECT l.id, l.kind, l.actor, l.status, l.message, l.started_at, l.tables_total, l.columns_total, l.columns_new, l.columns_dropped,
                              p.code AS project, p.name AS project_name
                       FROM sync_logs l JOIN projects p ON p.id = l.project_id ORDER BY l.id DESC LIMIT 10")->fetchAll();
        return ['totals' => $totals, 'projects' => array_values($projects), 'writers' => $writers, 'pending_tables' => $pending,
            'activity' => $activity, 'generated_at' => now()];
    }

    // ------------------------------------------------------------------
    //  โคลนโปรเจ็ค
    //  คัดลอกตาราง คอลัมน์ index พร้อมความหมายทั้งหมดไปเป็นโปรเจ็คใหม่
    //  ไม่คัดลอก API key, ประวัติ, ตัวอย่างข้อมูลและข้อเสนอรอพิจารณา (ผูกกับฐานข้อมูลต้นทางเดิม)
    // ------------------------------------------------------------------

    public static function cloneProject(array $src, string $code, string $name, bool $copyConnection, string $actor): int
    {
        @set_time_limit(600);
        $started = now();
        $from = (int)$src['id'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $f = ['code' => $code, 'name' => $name, 'description' => $src['description']];
            if ($copyConnection) {
                foreach (['conn_driver', 'conn_host', 'conn_port', 'conn_database', 'conn_schema', 'conn_username', 'conn_password_enc', 'conn_charset', 'last_sync_at'] as $k) {
                    $f[$k] = $src[$k];
                }
            }
            q('INSERT INTO projects (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
            $to = (int)$pdo->lastInsertId();

            $tcols = 'table_name, table_type, engine, table_collation, row_estimate, db_comment, description, notes, module, source, is_dropped, described_by, described_at, last_synced_at';
            $nTables = q("INSERT INTO dd_tables (project_id, $tcols) SELECT ?, $tcols FROM dd_tables WHERE project_id = ?", [$to, $from])->rowCount();

            // ชื่อตารางไม่ซ้ำภายในโปรเจ็ค จึงจับคู่ตารางเดิมกับตารางใหม่ด้วยชื่อ
            $ccols = 'column_name, ordinal_position, data_type, column_type, is_nullable, column_default, column_key, extra, db_comment,
                      description, allowed_values, ref_table, ref_column, notes, source, is_dropped, described_by, described_at, last_synced_at';
            $nCols = q("INSERT INTO dd_columns (table_id, project_id, $ccols)
                        SELECT nt.id, ?, " . preg_replace('/(\w+)/', 'c.$1', $ccols) . "
                        FROM dd_columns c
                        JOIN dd_tables ot ON ot.id = c.table_id
                        JOIN dd_tables nt ON nt.project_id = ? AND nt.table_name = ot.table_name
                        WHERE c.project_id = ?", [$to, $to, $from])->rowCount();

            q("INSERT INTO dd_indexes (table_id, index_name, is_unique, column_list)
               SELECT nt.id, i.index_name, i.is_unique, i.column_list
               FROM dd_indexes i
               JOIN dd_tables ot ON ot.id = i.table_id
               JOIN dd_tables nt ON nt.project_id = ? AND nt.table_name = ot.table_name
               WHERE ot.project_id = ?", [$to, $from]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        q("INSERT INTO sync_logs (project_id, kind, actor, status, tables_total, tables_new, columns_total, columns_new, message, started_at, finished_at)
           VALUES (?, 'clone', ?, 'ok', ?, ?, ?, ?, ?, ?, ?)",
            [$to, $actor, $nTables, $nTables, $nCols, $nCols, 'โคลนจากโปรเจ็ค ' . $src['code'] . ' (' . $src['name'] . ')', $started, now()]);
        return $to;
    }

    // ------------------------------------------------------------------
    //  งานซิงค์เบื้องหลัง
    //  สถานะงานเก็บใน sync_logs: status = running | ok | error
    //  ระหว่าง running ช่อง message คือขั้นตอนปัจจุบัน และ finished_at คือเวลาที่รายงานล่าสุด
    // ------------------------------------------------------------------

    const QUEUED = 'รอเริ่มทำงาน';

    /** ปิดงานที่ค้างสถานะ running ทั้งที่ process หยุดไปแล้ว */
    public static function reap(int $pid): void
    {
        $now = time();
        foreach (q("SELECT id, message, started_at, finished_at FROM sync_logs WHERE project_id = ? AND status = 'running'", [$pid])->fetchAll() as $r) {
            $idle = $now - strtotime($r['finished_at'] ?: $r['started_at']);
            $why = null;
            if ($r['message'] === self::QUEUED && $idle > 45) {
                $why = 'process เบื้องหลังไม่เริ่มทำงาน ตรวจค่า php_cli ใน config/config.php หรือลบค่านั้นออกเพื่อใช้วิธีสำรอง';
            } elseif ($idle > 7200) {
                $why = 'ไม่มีความคืบหน้าเกิน 2 ชั่วโมง ถือว่างานหยุดไปแล้ว';
            }
            if ($why !== null) {
                q("UPDATE sync_logs SET status = 'error', message = ?, finished_at = ? WHERE id = ? AND status = 'running'", [$why, now(), $r['id']]);
            }
        }
    }

    public static function latestSync(int $pid): ?array
    {
        self::reap($pid);
        $r = q("SELECT * FROM sync_logs WHERE project_id = ? AND kind = 'sync' ORDER BY id DESC LIMIT 1", [$pid])->fetch();
        return $r ?: null;
    }

    public static function startJob(int $pid, string $actor): int
    {
        $t = now();
        q("INSERT INTO sync_logs (project_id, kind, actor, status, message, started_at, finished_at) VALUES (?, 'sync', ?, 'running', ?, ?, ?)",
            [$pid, $actor, self::QUEUED, $t, $t]);
        return (int)db()->lastInsertId();
    }

    /** รายงานความคืบหน้า และหยุดงานถ้าถูกยกเลิกจากหน้าเว็บ */
    public static function beat(int $logId, string $message): void
    {
        $st = db2()->prepare("UPDATE sync_logs SET message = ?, finished_at = ? WHERE id = ? AND status = 'running'");
        $st->execute([$message, now(), $logId]);
        if ($st->rowCount() === 0) {
            $chk = db2()->prepare("SELECT status FROM sync_logs WHERE id = ?");
            $chk->execute([$logId]);
            if ($chk->fetchColumn() !== 'running') {
                throw new ApiError('งานซิงค์ถูกยกเลิก');
            }
        }
    }

    private static function finish(int $logId, string $status, array $n, string $message): void
    {
        $st = db2()->prepare("UPDATE sync_logs SET status = ?, tables_total = ?, tables_new = ?, columns_total = ?, columns_new = ?,
                                     columns_dropped = ?, message = ?, finished_at = ? WHERE id = ? AND status = 'running'");
        $st->execute([$status, $n['tables_total'] ?? 0, $n['tables_new'] ?? 0, $n['columns_total'] ?? 0, $n['columns_new'] ?? 0,
            $n['columns_dropped'] ?? 0, mb_substr($message, 0, 2000), now(), $logId]);
    }

    /** ตัวทำงานของงานซิงค์ เรียกจาก cli/sync.php หรือจาก api.php หลังตอบกลับเบราว์เซอร์แล้ว */
    public static function runSync(int $logId): string
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        @ini_set('memory_limit', '768M');
        $log = q("SELECT * FROM sync_logs WHERE id = ? AND kind = 'sync' AND status = 'running'", [$logId])->fetch();
        if (!$log) {
            return 'ไม่พบงานซิงค์หมายเลข ' . $logId . ' ที่รอทำงาน';
        }
        $done = false;
        register_shutdown_function(function () use ($logId, &$done) {
            if (!$done) { // สคริปต์ตายกลางทาง เช่น หน่วยความจำไม่พอ หรือถูกเว็บเซิร์ฟเวอร์ตัดเวลา
                $e = error_get_last();
                try {
                    self::finish($logId, 'error', [], 'งานหยุดกลางทาง' . ($e ? ': ' . $e['message'] : ''));
                } catch (Throwable $ignore) {
                }
            }
        });
        try {
            $project = q("SELECT * FROM projects WHERE id = ?", [$log['project_id']])->fetch();
            $struct = Introspector::read($project, function ($m) use ($logId) {
                self::beat($logId, $m);
            });
            $n = self::storeStructure($project, $struct, $logId);
            self::finish($logId, 'ok', $n, '');
            $msg = sprintf('สำเร็จ: %d ตาราง %d คอลัมน์ (คอลัมน์ใหม่ %d, หายไป %d)', $n['tables_total'], $n['columns_total'], $n['columns_new'], $n['columns_dropped']);
        } catch (Throwable $e) {
            self::finish($logId, 'error', [], $e->getMessage());
            $msg = 'ผิดพลาด: ' . $e->getMessage();
        }
        $done = true;
        return $msg;
    }

    /** เหมือน bulk() แต่รับข้อมูลทีละแถวจาก generator และรายงานจำนวนที่บันทึกแล้ว */
    private static function bulkStream(string $head, int $width, iterable $rows, string $tail, int $batch, callable $tick): int
    {
        $buf = [];
        $n = 0;
        foreach ($rows as $r) {
            $buf[] = $r;
            if (count($buf) >= $batch) {
                self::bulk($head, $width, $buf, $tail, $batch);
                $n += count($buf);
                $buf = [];
                $tick($n);
            }
        }
        if ($buf) {
            self::bulk($head, $width, $buf, $tail, $batch);
            $n += count($buf);
        }
        return $n;
    }

    // ------------------------------------------------------------------
    //  บันทึกโครงสร้างที่อ่านจากฐานข้อมูลต้นทาง
    //  เขียนทับเฉพาะข้อมูลโครงสร้าง ไม่แตะ description / allowed_values / notes
    // ------------------------------------------------------------------

    public static function storeStructure(array $project, array $struct, int $logId): array
    {
        $pid = (int)$project['id'];
        $started = now();
        $pdo = db();
        $rowCount = function () use ($pid) {
            return [
                (int)q("SELECT COUNT(*) FROM dd_tables WHERE project_id = ?", [$pid])->fetchColumn(),
                (int)q("SELECT COUNT(*) FROM dd_columns WHERE project_id = ?", [$pid])->fetchColumn(),
            ];
        };
        $before = $rowCount();
        $nTables = count($struct['tables']);
        $pdo->beginTransaction();
        try {
            // 1) ตาราง
            self::beat($logId, 'กำลังบันทึก ' . number_format($nTables) . ' ตาราง');
            $rows = [];
            foreach ($struct['tables'] as $t) {
                $rows[] = [
                    $pid, mb_substr((string)$t['name'], 0, 128), $t['type'], $t['engine'], $t['collation'],
                    $t['row_estimate'], (string)$t['comment'], $started,
                ];
            }
            self::bulk(
                "INSERT INTO dd_tables (project_id, table_name, table_type, engine, table_collation, row_estimate, db_comment, last_synced_at)",
                8, $rows,
                "ON DUPLICATE KEY UPDATE table_type = VALUES(table_type), engine = VALUES(engine),
                   table_collation = VALUES(table_collation), row_estimate = COALESCE(VALUES(row_estimate), row_estimate),
                   db_comment = VALUES(db_comment), source = 'sync', is_dropped = 0, last_synced_at = VALUES(last_synced_at)",
                500
            );
            q("UPDATE dd_tables SET is_dropped = 1
               WHERE project_id = ? AND source = 'sync' AND is_dropped = 0 AND (last_synced_at IS NULL OR last_synced_at <> ?)", [$pid, $started]);

            $ids = [];
            foreach (q("SELECT id, table_name FROM dd_tables WHERE project_id = ?", [$pid]) as $r) {
                $ids[mb_strtolower($r['table_name'])] = (int)$r['id'];
            }
            unset($rows);

            // 2) คอลัมน์ (อ่านจากต้นทางทีละแถว บันทึกทีละ 1,000)
            self::beat($logId, 'กำลังอ่านคอลัมน์ของ ' . number_format($nTables) . ' ตาราง จากฐานข้อมูลต้นทาง (ขั้นนี้ใช้เวลานานที่สุด)');
            $colRows = (function () use ($struct, $ids, $pid, $started) {
                foreach ($struct['columns'] as $c) {
                    $tid = $ids[mb_strtolower((string)$c['table_name'])] ?? null;
                    if ($tid === null) {
                        continue;
                    }
                    yield [
                        $tid, $pid, mb_substr((string)$c['name'], 0, 128), (int)$c['position'],
                        mb_substr((string)$c['data_type'], 0, 64), (string)$c['column_type'], (int)$c['nullable'],
                        $c['default_value'], mb_substr((string)$c['column_key'], 0, 10), mb_substr((string)$c['extra'], 0, 255),
                        (string)$c['comment'], $c['ref_table'], $c['ref_column'], $started,
                    ];
                }
            })();
            self::bulkStream(
                "INSERT INTO dd_columns (table_id, project_id, column_name, ordinal_position, data_type, column_type, is_nullable,
                                         column_default, column_key, extra, db_comment, ref_table, ref_column, last_synced_at)",
                14, $colRows,
                "ON DUPLICATE KEY UPDATE ordinal_position = VALUES(ordinal_position), data_type = VALUES(data_type),
                   column_type = VALUES(column_type), is_nullable = VALUES(is_nullable), column_default = VALUES(column_default),
                   column_key = VALUES(column_key), extra = VALUES(extra), db_comment = VALUES(db_comment),
                   ref_column = IF(VALUES(ref_table) IS NOT NULL AND (ref_table IS NULL OR ref_table = ''), VALUES(ref_column), ref_column),
                   ref_table  = IF(VALUES(ref_table) IS NOT NULL AND (ref_table IS NULL OR ref_table = ''), VALUES(ref_table), ref_table),
                   source = 'sync', is_dropped = 0, last_synced_at = VALUES(last_synced_at)",
                1000,
                function ($n) use ($logId) {
                    self::beat($logId, 'บันทึกคอลัมน์แล้ว ' . number_format($n) . ' คอลัมน์');
                }
            );
            self::beat($logId, 'กำลังตรวจตารางและคอลัมน์ที่ถูกลบ');
            // คอลัมน์ที่หายไปจากตารางที่ซิงค์รอบนี้
            $dropped = q("UPDATE dd_columns c JOIN dd_tables t ON t.id = c.table_id
                          SET c.is_dropped = 1
                          WHERE t.project_id = ? AND t.last_synced_at = ? AND c.is_dropped = 0
                            AND (c.last_synced_at IS NULL OR c.last_synced_at <> ?)", [$pid, $started, $started])->rowCount();
            // คอลัมน์ของตารางที่ถูกลบ
            $dropped += q("UPDATE dd_columns c JOIN dd_tables t ON t.id = c.table_id SET c.is_dropped = 1
               WHERE t.project_id = ? AND t.is_dropped = 1 AND c.is_dropped = 0", [$pid])->rowCount();

            // 3) index
            self::beat($logId, 'กำลังบันทึก index');
            q("DELETE i FROM dd_indexes i JOIN dd_tables t ON t.id = i.table_id WHERE t.project_id = ? AND t.last_synced_at = ?", [$pid, $started]);
            $rows = [];
            foreach ($struct['indexes'] as $ix) {
                $tid = $ids[mb_strtolower((string)$ix['table_name'])] ?? null;
                if ($tid !== null) {
                    $rows[] = [$tid, mb_substr((string)$ix['name'], 0, 128), (int)$ix['is_unique'], (string)$ix['column_list']];
                }
            }
            self::bulk("INSERT INTO dd_indexes (table_id, index_name, is_unique, column_list)", 4, $rows, '', 1000);

            // 4) ใช้ comment ในฐานข้อมูลต้นทางเป็นความหมาย
            //    comment คือสิ่งที่ผู้พัฒนาเขียนกำกับไว้เอง จึงน่าเชื่อกว่าที่ระบบเดา แต่ไม่ทับสิ่งที่คนหรือ Claude เขียนในระบบนี้
            //    เขียนเมื่อ: ช่องยังว่าง / ค่าเดิมมาจากการเดาอัตโนมัติ / ค่าเดิมมาจาก comment รุ่นก่อนและ comment ถูกแก้
            $fromComment = "db_comment IS NOT NULL AND db_comment <> '' AND (
                                description IS NULL OR description = ''
                                OR described_by = 'เดาอัตโนมัติ'
                                OR (described_by = 'comment ในฐานข้อมูล' AND description <> db_comment))";
            q("UPDATE dd_tables SET description = db_comment, described_by = 'comment ในฐานข้อมูล', described_at = ?
               WHERE project_id = ? AND is_dropped = 0 AND $fromComment", [$started, $pid]);
            $adopted = q("UPDATE dd_columns SET description = db_comment, described_by = 'comment ในฐานข้อมูล', described_at = ?
               WHERE project_id = ? AND is_dropped = 0 AND $fromComment", [$started, $pid])->rowCount();
            self::beat($logId, 'ใช้ comment จากฐานข้อมูลเป็นความหมาย ' . number_format($adopted) . ' คอลัมน์');

            q("UPDATE projects SET last_sync_at = ? WHERE id = ?", [$started, $pid]);
            self::beat($logId, 'กำลังยืนยันการบันทึก'); // ถ้าถูกยกเลิกจะหยุดตรงนี้และย้อนกลับทั้งหมด
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $after = self::stats($pid);
        $rowsAfter = $rowCount();
        return [
            'tables_total'    => $after['tables'],
            'tables_new'      => $rowsAfter[0] - $before[0],
            'columns_total'   => $after['columns'],
            'columns_new'     => $rowsAfter[1] - $before[1],
            'columns_dropped' => $dropped,
        ];
    }

    // ------------------------------------------------------------------
    //  นำเข้าจาก JSON (เช่น Claude ที่เขียนโปรเจ็คส่งเข้ามา)
    // ------------------------------------------------------------------

    private static function filled($v): bool
    {
        return $v !== null && !is_array($v) && trim((string)$v) !== '';
    }

    /** เลือกค่าคำอธิบายที่จะเขียน ตามนโยบาย overwrite */
    private static function pickText(array $src, array $fields, array $current, bool $overwrite): array
    {
        $set = [];
        foreach ($fields as $inKey => $col) {
            if (!array_key_exists($inKey, $src) || !self::filled($src[$inKey])) {
                continue;
            }
            $new = trim((string)$src[$inKey]);
            $old = (string)($current[$col] ?? '');
            if ($new !== $old && ($overwrite || $old === '')) {
                $set[$col] = $new;
            }
        }
        return $set;
    }

    private static function applyUpdate(string $table, int $id, array $set): void
    {
        if (!$set) {
            return;
        }
        $sql = [];
        foreach ($set as $col => $_) {
            $sql[] = "$col = ?";
        }
        $params = array_values($set);
        $params[] = $id;
        q("UPDATE $table SET " . implode(', ', $sql) . " WHERE id = ?", $params);
    }

    private static function splitRef(array $c): array
    {
        if (self::filled($c['ref'] ?? null) && strpos((string)$c['ref'], '.') !== false) {
            list($rt, $rc) = explode('.', trim((string)$c['ref']), 2);
            $c['ref_table'] = $rt;
            $c['ref_column'] = $rc;
        }
        return $c;
    }

    public static function import(array $project, array $payload, string $actor): array
    {
        $pid = (int)$project['id'];
        $started = now();
        $tables = $payload['tables'] ?? null;
        if (!is_array($tables) || !$tables) {
            throw new ApiError('ต้องส่ง "tables" เป็นรายการอย่างน้อย 1 ตาราง');
        }
        $overwrite = !empty($payload['overwrite']);
        $create = array_key_exists('create_missing', $payload) ? !empty($payload['create_missing']) : $project['last_sync_at'] === null;

        $res = ['tables_created' => 0, 'tables_updated' => 0, 'columns_created' => 0, 'columns_updated' => 0, 'unchanged' => 0, 'not_found' => []];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($tables as $t) {
                if (!is_array($t) || !self::filled($t['name'] ?? null)) {
                    throw new ApiError('ทุกตารางต้องมี "name"');
                }
                $name = mb_substr(trim((string)$t['name']), 0, 128);
                $row = q("SELECT * FROM dd_tables WHERE project_id = ? AND table_name = ?", [$pid, $name])->fetch();
                if (!$row) {
                    if (!$create) {
                        $res['not_found'][] = $name;
                        continue;
                    }
                    q("INSERT INTO dd_tables (project_id, table_name, table_type, source) VALUES (?,?,?, 'import')",
                        [$pid, $name, stripos((string)($t['type'] ?? ''), 'view') !== false ? 'VIEW' : 'BASE TABLE']);
                    $row = q("SELECT * FROM dd_tables WHERE id = ?", [(int)$pdo->lastInsertId()])->fetch();
                    $res['tables_created']++;
                }
                $tid = (int)$row['id'];
                $set = self::pickText($t, ['description' => 'description', 'notes' => 'notes', 'module' => 'module'], $row, $overwrite);
                if (isset($set['module'])) {
                    $set['module'] = mb_substr($set['module'], 0, 100);
                }
                if ($row['is_dropped'] && $row['source'] !== 'sync') {
                    $set['is_dropped'] = 0;
                }
                if (isset($set['description'])) {
                    $set['described_by'] = $actor;
                    $set['described_at'] = $started;
                }
                if ($set) {
                    self::applyUpdate('dd_tables', $tid, $set);
                    $res['tables_updated']++;
                }

                $cols = isset($t['columns']) && is_array($t['columns']) ? $t['columns'] : [];
                if (!$cols) {
                    continue;
                }
                $existing = [];
                $maxPos = 0;
                foreach (q("SELECT * FROM dd_columns WHERE table_id = ?", [$tid]) as $c) {
                    $existing[mb_strtolower($c['column_name'])] = $c;
                    $maxPos = max($maxPos, (int)$c['ordinal_position']);
                }
                foreach ($cols as $c) {
                    if (!is_array($c) || !self::filled($c['name'] ?? null)) {
                        throw new ApiError("คอลัมน์ในตาราง $name ต้องมี \"name\"");
                    }
                    $c = self::splitRef($c);
                    $cname = mb_substr(trim((string)$c['name']), 0, 128);
                    $cur = $existing[mb_strtolower($cname)] ?? null;
                    $isNew = false;
                    if (!$cur) {
                        if (!$create) {
                            $res['not_found'][] = "$name.$cname";
                            continue;
                        }
                        q("INSERT INTO dd_columns (table_id, project_id, column_name, ordinal_position, source) VALUES (?,?,?,?, 'import')",
                            [$tid, $pid, $cname, ++$maxPos]);
                        $cur = q("SELECT * FROM dd_columns WHERE id = ?", [(int)$pdo->lastInsertId()])->fetch();
                        $existing[mb_strtolower($cname)] = $cur;
                        $res['columns_created']++;
                        $isNew = true;
                    }
                    $set = self::pickText($c, [
                        'description' => 'description', 'allowed_values' => 'allowed_values', 'notes' => 'notes',
                        'ref_table' => 'ref_table', 'ref_column' => 'ref_column',
                    ], $cur, $overwrite);
                    if (isset($set['description'])) {
                        $set['described_by'] = $actor;
                        $set['described_at'] = $started;
                    }
                    // โครงสร้าง: รับเฉพาะคอลัมน์ที่ไม่ได้มาจากการซิงค์ฐานข้อมูลจริง
                    if ($cur['source'] !== 'sync') {
                        if (self::filled($c['type'] ?? null)) {
                            $type = trim((string)$c['type']);
                            $set['column_type'] = $type;
                            $set['data_type'] = mb_substr(strtolower((string)preg_replace('/[\s(].*$/s', '', $type)), 0, 64);
                        }
                        if (array_key_exists('nullable', $c) && $c['nullable'] !== null) {
                            $set['is_nullable'] = $c['nullable'] ? 1 : 0;
                        }
                        if (array_key_exists('default', $c) && $c['default'] !== null && !is_array($c['default'])) {
                            $set['column_default'] = (string)$c['default'];
                        }
                        if (self::filled($c['key'] ?? null)) {
                            $set['column_key'] = mb_substr(strtoupper(trim((string)$c['key'])), 0, 10);
                        }
                        if ($cur['is_dropped']) {
                            $set['is_dropped'] = 0;
                        }
                        foreach ($set as $k => $v) {
                            if (array_key_exists($k, $cur) && (string)$cur[$k] === (string)$v) {
                                unset($set[$k]);
                            }
                        }
                    }
                    if ($set) {
                        self::applyUpdate('dd_columns', (int)$cur['id'], $set);
                        if (!$isNew) {
                            $res['columns_updated']++;
                        }
                    } elseif (!$isNew) {
                        $res['unchanged']++;
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            self::logError($pid, 'import', $actor, $e->getMessage(), $started);
            throw $e;
        }

        $stats = self::stats($pid);
        $missing = count($res['not_found']);
        $res['not_found_count'] = $missing;
        $res['not_found'] = array_slice($res['not_found'], 0, 200);
        $msg = sprintf('ตารางใหม่ %d, ตารางที่แก้ %d, คอลัมน์ใหม่ %d, คอลัมน์ที่แก้ %d, ไม่เปลี่ยน %d, ไม่พบ %d',
            $res['tables_created'], $res['tables_updated'], $res['columns_created'], $res['columns_updated'], $res['unchanged'], $missing);
        self::log($pid, 'import', $actor, 'ok', [
            'tables_total' => $stats['tables'], 'tables_new' => $res['tables_created'],
            'columns_total' => $stats['columns'], 'columns_new' => $res['columns_created'],
        ], $msg, $started);
        $res['overwrite'] = $overwrite;
        $res['create_missing'] = $create;
        $res['stats'] = $stats;
        return $res;
    }

    // ------------------------------------------------------------------
    //  อ่านข้อมูล
    // ------------------------------------------------------------------

    /** $withSamples = แนบตัวอย่างข้อมูลและข้อเสนอรอพิจารณา (เฉพาะหน้าเว็บ ไม่ส่งออกทาง API key หรือไฟล์ส่งออก) */
    public static function columnOut(array $c, bool $withSamples = false): array
    {
        $extra = [];
        if ($withSamples) {
            $extra['sample'] = isset($c['sample_json']) ? json_decode((string)$c['sample_json'], true) : null;
            $extra['suggestion'] = isset($c['suggest_json']) ? json_decode((string)$c['suggest_json'], true) : null;
        }
        return $extra + [
            'id'             => (int)$c['id'],
            'name'           => $c['column_name'],
            'position'       => (int)$c['ordinal_position'],
            'type'           => $c['column_type'],
            'nullable'       => (bool)$c['is_nullable'],
            'default'        => $c['column_default'],
            'key'            => (string)$c['column_key'],
            'extra'          => (string)$c['extra'],
            'description'    => (string)$c['description'],
            'allowed_values' => (string)$c['allowed_values'],
            'ref'            => ($c['ref_table'] ?? '') !== '' ? $c['ref_table'] . (($c['ref_column'] ?? '') !== '' ? '.' . $c['ref_column'] : '') : '',
            'notes'          => (string)$c['notes'],
            'db_comment'     => (string)$c['db_comment'],
            'dropped'        => (bool)$c['is_dropped'],
            'described_by'   => $c['described_by'],
            'guessed'        => ($c['described_by'] ?? '') === 'เดาอัตโนมัติ', // ค่าเดียวกับ Guesser::BY
        ];
    }

    public static function tableOut(array $t): array
    {
        return [
            'id'           => (int)$t['id'],
            'name'         => $t['table_name'],
            'type'         => $t['table_type'],
            'module'       => (string)$t['module'],
            'description'  => (string)$t['description'],
            'notes'        => (string)$t['notes'],
            'db_comment'   => (string)$t['db_comment'],
            'engine'       => $t['engine'],
            'row_estimate' => $t['row_estimate'] !== null ? (int)$t['row_estimate'] : null,
            'source'       => $t['source'],
            'dropped'      => (bool)$t['is_dropped'],
        ];
    }

    /** ไล่ทีละตารางพร้อมคอลัมน์ โดยไม่โหลดทั้งหมดเข้าหน่วยความจำ */
    public static function iterate(int $projectId, array $only = []): Generator
    {
        $where = "t.project_id = ? AND t.is_dropped = 0";
        $params = [$projectId];
        if ($only) {
            $where .= " AND t.table_name IN (" . implode(',', array_fill(0, count($only), '?')) . ")";
            $params = array_merge($params, $only);
        }
        $tables = q("SELECT t.* FROM dd_tables t WHERE $where ORDER BY t.table_name, t.id", $params)->fetchAll();

        $pdo = db();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $st = $pdo->prepare("SELECT c.* FROM dd_columns c JOIN dd_tables t ON t.id = c.table_id
                                 WHERE $where AND c.is_dropped = 0
                                 ORDER BY t.table_name, t.id, c.ordinal_position, c.id");
            $st->execute($params);
            $col = $st->fetch();
            foreach ($tables as $t) {
                $out = self::tableOut($t);
                $out['columns'] = [];
                while ($col && (int)$col['table_id'] === (int)$t['id']) {
                    $out['columns'][] = self::columnOut($col);
                    $col = $st->fetch();
                }
                yield $out;
            }
            $st->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    // ------------------------------------------------------------------
    //  ส่งออก
    // ------------------------------------------------------------------

    private static function mdCell($v): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', '<br>', '<br>', '<br>'], (string)$v);
    }

    public static function export(array $project, string $format, array $only): void
    {
        $file = 'data_dictionary_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $project['code']) . '_' . date('Ymd');
        header('Cache-Control: no-store');
        $rows = self::iterate((int)$project['id'], $only);

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$file.csv\"");
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['table', 'table_description', 'column', 'type', 'nullable', 'key', 'default', 'description', 'allowed_values', 'ref', 'notes']);
            foreach ($rows as $t) {
                foreach ($t['columns'] as $c) {
                    fputcsv($out, [$t['name'], $t['description'], $c['name'], $c['type'], $c['nullable'] ? 'YES' : 'NO', $c['key'],
                        $c['default'], $c['description'], $c['allowed_values'], $c['ref'], $c['notes']]);
                }
            }
            fclose($out);
            return;
        }

        if ($format === 'md') {
            header('Content-Type: text/markdown; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$file.md\"");
            echo "# Data Dictionary: {$project['name']} ({$project['code']})\n\n";
            echo "ส่งออกเมื่อ " . now() . "\n";
            foreach ($rows as $t) {
                echo "\n## {$t['name']}" . ($t['type'] === 'VIEW' ? ' (view)' : '') . "\n\n";
                if ($t['description'] !== '') {
                    echo $t['description'] . "\n\n";
                }
                if ($t['notes'] !== '') {
                    echo "หมายเหตุ: " . $t['notes'] . "\n\n";
                }
                echo "| คอลัมน์ | ชนิด | คีย์ | Null | ความหมาย | ค่าที่เป็นไปได้ | อ้างอิง |\n|---|---|---|---|---|---|---|\n";
                foreach ($t['columns'] as $c) {
                    echo '| ' . implode(' | ', array_map([self::class, 'mdCell'], [
                        $c['name'], $c['type'], $c['key'], $c['nullable'] ? 'YES' : 'NO', $c['description'], $c['allowed_values'], $c['ref'],
                    ])) . " |\n";
                }
            }
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        if (!empty($_GET['download'])) {
            header("Content-Disposition: attachment; filename=\"$file.json\"");
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        echo '{"ok":true,"project":' . json_encode(['code' => $project['code'], 'name' => $project['name']], $flags)
            . ',"exported_at":' . json_encode(now()) . ',"tables":[';
        $first = true;
        foreach ($rows as $t) {
            echo ($first ? '' : ',') . "\n" . json_encode($t, $flags);
            $first = false;
        }
        echo "\n]}";
    }
}
