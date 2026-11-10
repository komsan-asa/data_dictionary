<?php
/**
 * Data Dictionary web service
 * เรียกใช้:  api.php?action=<คำสั่ง>   (POST ส่งข้อมูลเป็น JSON)
 * ดูรายการคำสั่งทั้งหมดได้ที่ README.md และ CLAUDE_GUIDE.md
 */
declare(strict_types=1);

define('DD_APP', true);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/Introspector.php';
require __DIR__ . '/src/Dictionary.php';
require __DIR__ . '/src/Guesser.php';

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiError) {
        respond(['ok' => false, 'error' => $e->getMessage()], $e->status);
    }
    error_log('[data_dictionary] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(['ok' => false, 'error' => 'เกิดข้อผิดพลาดในระบบ: ' . $e->getMessage()], 500);
});

config(); // โหลดค่าตั้งและเขตเวลาก่อนทำอย่างอื่น เพื่อให้เวลาที่บันทึกตรงกันทุกจุด
$in = read_input();
$action = (string)($in['action'] ?? '');

function has_users(): bool
{
    return (int)q('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function login_session(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'display_name' => $user['display_name']];
}

/** ผู้ใช้ถือว่าออนไลน์เมื่อหน้าเว็บรายงานตัวภายในเวลานี้ (เบราว์เซอร์หน่วงแท็บที่อยู่เบื้องหลังได้ถึงราว 1 นาที) */
const ONLINE_WINDOW = 150;

/**
 * บันทึกเวลาที่เห็นผู้ใช้ล่าสุด แล้วคืนรายชื่อผู้ที่ออนไลน์
 * คืน null เมื่อฐานข้อมูลยังไม่มีคอลัมน์และเพิ่มเองไม่ได้ (ต้องรัน database/migrate_20261110_online.sql)
 */
function presence(int $uid, string $viewing): ?array
{
    $run = function () use ($uid, $viewing) {
        q('UPDATE users SET last_seen_at = ?, last_seen_project = ? WHERE id = ?', [now(), $viewing !== '' ? $viewing : null, $uid]);
        $out = [];
        foreach (q('SELECT id, username, display_name, last_seen_at, last_seen_project FROM users WHERE last_seen_at >= ? ORDER BY username',
            [date('Y-m-d H:i:s', time() - ONLINE_WINDOW)]) as $r) {
            $out[] = [
                'username' => $r['username'],
                'name'     => $r['display_name'] !== null && $r['display_name'] !== '' ? $r['display_name'] : $r['username'],
                'viewing'  => $r['last_seen_project'],
                'me'       => (int)$r['id'] === $uid,
                'idle'     => max(0, time() - strtotime($r['last_seen_at'])),
            ];
        }
        return $out;
    };
    try {
        return $run();
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S22') { // 42S22 = ไม่รู้จักคอลัมน์
            throw $e;
        }
    }
    try {
        db()->exec("ALTER TABLE users
            ADD COLUMN last_seen_at DATETIME NULL COMMENT 'เวลาที่หน้าเว็บรายงานตัวล่าสุด ใช้แสดงผู้ใช้ออนไลน์',
            ADD COLUMN last_seen_project VARCHAR(50) NULL COMMENT 'รหัสโปรเจ็คที่กำลังเปิดอยู่'");
    } catch (PDOException $e) {
        return null;
    }
    return $run();
}

function like(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

const EMPTY_DESC = "(%s.description IS NULL OR %s.description = '')";

switch ($action) {

    // ------------------------------------------------------------ ผู้ใช้
    case 'status':
        $u = current_user();
        respond(['ok' => true, 'needs_setup' => !has_users(), 'user' => $u, 'csrf' => $u ? csrf_token() : null]);
        break;

    case 'setup': // สร้างผู้ดูแลคนแรก ทำได้ครั้งเดียวตอนยังไม่มีผู้ใช้
        require_post();
        if (has_users()) {
            throw new ApiError('ตั้งค่าผู้ดูแลไปแล้ว กรุณาเข้าสู่ระบบ', 403);
        }
        // fallthrough ไปใช้ตัวตรวจเดียวกับการเพิ่มผู้ใช้
    case 'user.add':
        if ($action === 'user.add') {
            require_user(true);
        }
        $username = (string)str_in($in, 'username', 50);
        $password = (string)($in['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new ApiError('ชื่อผู้ใช้ต้องเป็น a-z 0-9 . _ - ยาว 3-50 ตัวอักษร');
        }
        if (strlen($password) < 8) {
            throw new ApiError('รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร');
        }
        if (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetch()) {
            throw new ApiError('มีชื่อผู้ใช้นี้แล้ว');
        }
        q('INSERT INTO users (username, password_hash, display_name) VALUES (?,?,?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), str_in($in, 'display_name', 100) ?: $username]);
        if ($action === 'setup') {
            login_session(q('SELECT * FROM users WHERE username = ?', [$username])->fetch());
            respond(['ok' => true, 'user' => current_user(), 'csrf' => csrf_token()]);
        }
        respond(['ok' => true]);
        break;

    case 'login':
        require_post();
        $row = q('SELECT * FROM users WHERE username = ?', [(string)str_in($in, 'username', 50)])->fetch();
        if (!$row || !password_verify((string)($in['password'] ?? ''), $row['password_hash'])) {
            sleep(1);
            throw new ApiError('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
        }
        login_session($row);
        respond(['ok' => true, 'user' => current_user(), 'csrf' => csrf_token()]);
        break;

    case 'presence': // หน้าเว็บเรียกทุก 30 วินาที: บันทึกว่ายังอยู่ และรับรายชื่อผู้ที่ออนไลน์
        $u = require_user(true);
        $list = presence((int)$u['id'], (string)(str_in($in, 'viewing', 50) ?? ''));
        respond(['ok' => true, 'online' => $list, 'ready' => $list !== null]);
        break;

    case 'logout':
        $u = require_user(true);
        try {
            q('UPDATE users SET last_seen_at = NULL WHERE id = ?', [(int)$u['id']]);
        } catch (PDOException $e) {
            // ฐานข้อมูลที่ยังไม่มีคอลัมน์ last_seen_at ไม่ต้องทำอะไร
        }
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
        break;

    // ------------------------------------------------------------ โปรเจ็ค
    case 'projects':
        require_user();
        $list = [];
        $pending = []; // จำนวนข้อเสนอรอพิจารณาของแต่ละโปรเจ็ค ใช้กรองรายการโปรเจ็ค
        if (Guesser::storeReady()) {
            foreach (q('SELECT project_id, COUNT(*) AS n FROM dd_columns WHERE is_dropped = 0 AND suggest_json IS NOT NULL GROUP BY project_id') as $r) {
                $pending[(int)$r['project_id']] = (int)$r['n'];
            }
        }
        foreach (q('SELECT * FROM projects ORDER BY name') as $p) {
            $list[] = project_public($p) + ['stats' => Dictionary::stats((int)$p['id']), 'pending' => $pending[(int)$p['id']] ?? 0];
        }
        respond(['ok' => true, 'projects' => $list]);
        break;

    case 'dashboard': // ภาพรวมทุกโปรเจ็คสำหรับหน้าแรก
        require_user();
        respond(['ok' => true] + Dictionary::dashboard());
        break;

    case 'project': // ข้อมูลสรุปของโปรเจ็ค (ใช้ API key ได้)
        list($p) = resolve_project($in, true);
        respond(['ok' => true, 'project' => project_public($p) + ['stats' => Dictionary::stats((int)$p['id'])]]);
        break;

    case 'project.save':
        require_user(true);
        $id = (int)($in['id'] ?? 0);
        $code = (string)str_in($in, 'code', 50);
        $name = (string)str_in($in, 'name', 200);
        if (!preg_match('/^[A-Za-z0-9_-]{2,50}$/', $code)) {
            throw new ApiError('รหัสโปรเจ็คต้องเป็น a-z 0-9 _ - ยาว 2-50 ตัวอักษร (ไม่มีช่องว่าง)');
        }
        if ($name === '') {
            throw new ApiError('กรุณาใส่ชื่อโปรเจ็ค');
        }
        if (q('SELECT 1 FROM projects WHERE code = ? AND id <> ?', [$code, $id])->fetch()) {
            throw new ApiError("มีโปรเจ็ครหัส $code อยู่แล้ว");
        }
        $driver = str_in($in, 'conn_driver', 10);
        if ($driver !== null && $driver !== '' && !in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new ApiError('ชนิดฐานข้อมูลต้องเป็น mysql หรือ pgsql');
        }
        $port = (int)($in['conn_port'] ?? 0);
        $f = [
            'code' => $code, 'name' => $name, 'description' => str_in($in, 'description'),
            'conn_driver' => $driver ?: null, 'conn_host' => str_in($in, 'conn_host', 255) ?: null,
            'conn_port' => $port > 0 && $port < 65536 ? $port : null,
            'conn_database' => str_in($in, 'conn_database', 128) ?: null, 'conn_schema' => str_in($in, 'conn_schema', 128) ?: null,
            'conn_username' => str_in($in, 'conn_username', 128) ?: null, 'conn_charset' => str_in($in, 'conn_charset', 20) ?: null,
        ];
        // รหัสผ่าน: ส่งมา = เปลี่ยน, ไม่ส่ง/ว่าง = คงเดิม, clear_password = ลบ
        if (isset($in['conn_password']) && $in['conn_password'] !== '') {
            $f['conn_password_enc'] = encrypt_secret((string)$in['conn_password']);
        } elseif (!empty($in['clear_password'])) {
            $f['conn_password_enc'] = null;
        }
        if ($id) {
            $sets = [];
            foreach ($f as $k => $_) {
                $sets[] = "$k = ?";
            }
            q('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ?', array_merge(array_values($f), [$id]));
        } else {
            q('INSERT INTO projects (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
            $id = (int)db()->lastInsertId();
        }
        respond(['ok' => true, 'project' => project_public(q('SELECT * FROM projects WHERE id = ?', [$id])->fetch())]);
        break;

    case 'project.clone': // คัดลอกโปรเจ็คพร้อมความหมายทั้งหมดไปเป็นโปรเจ็คใหม่
        list($p, $actor) = resolve_project($in, false, true);
        $code = (string)str_in($in, 'code', 50);
        $name = (string)str_in($in, 'name', 200);
        if (!preg_match('/^[A-Za-z0-9_-]{2,50}$/', $code)) {
            throw new ApiError('รหัสโปรเจ็คต้องเป็น a-z 0-9 _ - ยาว 2-50 ตัวอักษร (ไม่มีช่องว่าง)');
        }
        if ($name === '') {
            throw new ApiError('กรุณาใส่ชื่อโปรเจ็ค');
        }
        if (q('SELECT 1 FROM projects WHERE code = ?', [$code])->fetch()) {
            throw new ApiError("มีโปรเจ็ครหัส $code อยู่แล้ว");
        }
        $job = Dictionary::latestSync((int)$p['id']);
        if ($job && $job['status'] === 'running') {
            throw new ApiError('โปรเจ็คต้นทางกำลังซิงค์โครงสร้างอยู่ รอให้เสร็จก่อนแล้วจึงโคลน');
        }
        $newId = Dictionary::cloneProject($p, $code, $name, !empty($in['copy_connection']), $actor);
        respond(['ok' => true, 'project' => project_public(q('SELECT * FROM projects WHERE id = ?', [$newId])->fetch()) + ['stats' => Dictionary::stats($newId)]]);
        break;

    case 'project.delete':
        list($p) = resolve_project($in, false, true);
        if ((string)($in['confirm_code'] ?? '') !== $p['code']) {
            throw new ApiError('พิมพ์รหัสโปรเจ็คเพื่อยืนยันการลบไม่ตรง');
        }
        q('DELETE FROM projects WHERE id = ?', [$p['id']]);
        respond(['ok' => true]);
        break;

    case 'project.key': // สร้าง API key ใหม่ (แสดงครั้งเดียว) key เดิมใช้ไม่ได้ทันที
        list($p) = resolve_project($in, false, true);
        $key = 'dd_' . bin2hex(random_bytes(24));
        q('UPDATE projects SET api_key_hash = ?, api_key_hint = ? WHERE id = ?', [hash('sha256', $key), substr($key, 0, 9), $p['id']]);
        respond(['ok' => true, 'api_key' => $key]);
        break;

    case 'project.test':
        list($p) = resolve_project($in, false, true);
        respond(['ok' => true] + Introspector::test($p));
        break;

    case 'project.sync': // เริ่มงานซิงค์เบื้องหลัง ตอบกลับทันที ติดตามผลด้วย sync.status
        list($p, $actor) = resolve_project($in, false, true);
        if (!project_public($p)['has_connection']) {
            throw new ApiError('โปรเจ็คนี้ยังตั้งค่า connection ไม่ครบ (ชนิดฐานข้อมูล, host, ชื่อฐานข้อมูล)');
        }
        $job = Dictionary::latestSync((int)$p['id']);
        if ($job && $job['status'] === 'running') {
            respond(['ok' => true, 'job_id' => (int)$job['id'], 'already_running' => true]);
        }
        $jobId = Dictionary::startJob((int)$p['id'], $actor);
        $php = (string)(config()['php_cli'] ?? '');
        if ($php !== '' && spawn_cli($php, [__DIR__ . '/cli/sync.php', '--job=' . $jobId])) {
            respond(['ok' => true, 'job_id' => $jobId, 'mode' => 'cli']);
        }
        respond_and_continue(['ok' => true, 'job_id' => $jobId, 'mode' => 'inline']);
        Dictionary::runSync($jobId);
        exit;

    case 'sync.status':
        list($p) = resolve_project($in, false);
        $job = Dictionary::latestSync((int)$p['id']);
        if ($job) {
            $running = $job['status'] === 'running';
            $job = [
                'id' => (int)$job['id'], 'status' => $job['status'], 'message' => (string)$job['message'], 'actor' => $job['actor'],
                'started_at' => $job['started_at'], 'finished_at' => $running ? null : $job['finished_at'],
                'elapsed' => max(0, ($running ? time() : strtotime((string)$job['finished_at'])) - strtotime($job['started_at'])),
                'tables_total' => (int)$job['tables_total'], 'columns_total' => (int)$job['columns_total'],
                'columns_new' => (int)$job['columns_new'], 'columns_dropped' => (int)$job['columns_dropped'],
            ];
        }
        respond(['ok' => true, 'job' => $job]);
        break;

    case 'sync.cancel':
        list($p, $actor) = resolve_project($in, false, true);
        $n = q("UPDATE sync_logs SET status = 'error', message = ?, finished_at = ? WHERE project_id = ? AND kind = 'sync' AND status = 'running'",
            ['ยกเลิกโดย ' . $actor, now(), $p['id']])->rowCount();
        respond(['ok' => true, 'cancelled' => $n]);
        break;

    case 'logs':
        list($p) = resolve_project($in, false);
        respond(['ok' => true, 'logs' => q('SELECT * FROM sync_logs WHERE project_id = ? ORDER BY id DESC LIMIT 30', [$p['id']])->fetchAll()]);
        break;

    // ------------------------------------------------------------ ตาราง / คอลัมน์
    case 'tables':
        list($p) = resolve_project($in, true);
        $store = Guesser::storeReady();
        $per = max(1, min(200, (int)($in['per'] ?? 60)));
        $page = max(1, (int)($in['page'] ?? 1));
        $filter = (string)($in['filter'] ?? 'all');
        $where = 't.project_id = ?';
        $params = [$p['id']];
        if ($filter === 'dropped') {
            $where .= ' AND t.is_dropped = 1';
        } elseif ($filter === 'pending') { // ตารางที่มีข้อเสนอจากระบบรอคนพิจารณา
            $where .= ' AND t.is_dropped = 0 AND ' . ($store ? 'EXISTS (SELECT 1 FROM dd_columns c WHERE c.table_id = t.id AND c.is_dropped = 0 AND c.suggest_json IS NOT NULL)' : '1 = 0');
        } else {
            $where .= ' AND t.is_dropped = 0';
            $noCol = 'EXISTS (SELECT 1 FROM dd_columns c WHERE c.table_id = t.id AND c.is_dropped = 0 AND ' . sprintf(EMPTY_DESC, 'c', 'c') . ')';
            if ($filter === 'undocumented') {
                $where .= ' AND (' . sprintf(EMPTY_DESC, 't', 't') . " OR $noCol)";
            } elseif ($filter === 'documented') {
                $where .= ' AND NOT ' . sprintf(EMPTY_DESC, 't', 't') . " AND NOT $noCol";
            }
        }
        $search = (string)str_in($in, 'q', 100);
        if ($search !== '') {
            $where .= ' AND (t.table_name LIKE ? OR t.description LIKE ? OR t.module LIKE ?)';
            array_push($params, like($search), like($search), like($search));
        }
        $total = (int)q("SELECT COUNT(*) FROM dd_tables t WHERE $where", $params)->fetchColumn();
        $rows = q("SELECT t.* FROM dd_tables t WHERE $where ORDER BY t.table_name LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();
        $counts = [];
        if ($rows) {
            $idList = implode(',', array_map('intval', array_column($rows, 'id')));
            $pend = $store ? 'COALESCE(SUM(suggest_json IS NOT NULL), 0)' : '0';
            foreach (q("SELECT table_id, COUNT(*) AS n, COALESCE(SUM(description IS NOT NULL AND description <> ''), 0) AS d, $pend AS s
                        FROM dd_columns WHERE table_id IN ($idList) AND is_dropped = 0 GROUP BY table_id") as $c) {
                $counts[(int)$c['table_id']] = [(int)$c['n'], (int)$c['d'], (int)$c['s']];
            }
        }
        $out = [];
        foreach ($rows as $t) {
            $n = $counts[(int)$t['id']] ?? [0, 0, 0];
            $out[] = Dictionary::tableOut($t) + ['columns_total' => $n[0], 'columns_described' => $n[1], 'columns_pending' => $n[2]];
        }
        respond(['ok' => true, 'total' => $total, 'page' => $page, 'per' => $per, 'tables' => $out]);
        break;

    case 'table': // รายละเอียดตารางเดียว พร้อมคอลัมน์และ index
        list($p, , $viaKey) = resolve_project($in, true);
        if (!empty($in['id'])) {
            $t = q('SELECT * FROM dd_tables WHERE id = ? AND project_id = ?', [(int)$in['id'], $p['id']])->fetch();
        } else {
            $t = q('SELECT * FROM dd_tables WHERE table_name = ? AND project_id = ?', [(string)str_in($in, 'name', 128), $p['id']])->fetch();
        }
        if (!$t) {
            throw new ApiError('ไม่พบตารางที่ระบุ', 404);
        }
        $out = Dictionary::tableOut($t) + ['described_by' => $t['described_by'], 'last_synced_at' => $t['last_synced_at']];
        // ตัวอย่างข้อมูลจากฐานข้อมูลต้นทางส่งให้เฉพาะผู้ใช้หน้าเว็บ ไม่ส่งให้ผู้เรียกด้วย API key
        $out['columns'] = array_map(function ($c) use ($viaKey) {
            return Dictionary::columnOut($viaKey ? $c : Guesser::prune($c), !$viaKey);
        }, q('SELECT * FROM dd_columns WHERE table_id = ? ORDER BY is_dropped, ordinal_position, id', [$t['id']])->fetchAll());
        $out['indexes'] = q('SELECT index_name AS name, is_unique, column_list AS columns FROM dd_indexes WHERE table_id = ? ORDER BY index_name', [$t['id']])->fetchAll();
        respond(['ok' => true, 'table' => $out]);
        break;

    case 'search': // ค้นหาคอลัมน์ทั้งโปรเจ็ค ตามชื่อหรือความหมาย
        list($p) = resolve_project($in, true);
        $search = (string)str_in($in, 'q', 100);
        if (mb_strlen($search) < 2) {
            respond(['ok' => true, 'columns' => []]);
        }
        $rows = q("SELECT c.id, c.column_name, c.column_type, c.description, t.id AS table_id, t.table_name
                   FROM dd_columns c JOIN dd_tables t ON t.id = c.table_id
                   WHERE c.project_id = ? AND c.is_dropped = 0 AND t.is_dropped = 0
                     AND (c.column_name LIKE ? OR c.description LIKE ?)
                   ORDER BY (c.column_name = ?) DESC, c.column_name, t.table_name LIMIT 300",
            [$p['id'], like($search), like($search), $search])->fetchAll();
        respond(['ok' => true, 'columns' => $rows]);
        break;

    case 'table.save':
        require_user(true);
        $u = current_user();
        $t = q('SELECT * FROM dd_tables WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
        if (!$t) {
            throw new ApiError('ไม่พบตารางที่ระบุ', 404);
        }
        $set = [];
        foreach (['description' => 65000, 'notes' => 65000, 'module' => 100] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $set[$k] = (string)str_in($in, $k, $max);
            }
        }
        if (isset($set['description']) && $set['description'] !== (string)$t['description']) {
            $set['described_by'] = $u['username'];
            $set['described_at'] = now();
        }
        if ($set) {
            $sql = [];
            foreach ($set as $k => $_) {
                $sql[] = "$k = ?";
            }
            q('UPDATE dd_tables SET ' . implode(', ', $sql) . ' WHERE id = ?', array_merge(array_values($set), [$t['id']]));
        }
        respond(['ok' => true]);
        break;

    case 'column.save':
        require_user(true);
        $u = current_user();
        $c = q('SELECT * FROM dd_columns WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
        if (!$c) {
            throw new ApiError('ไม่พบคอลัมน์ที่ระบุ', 404);
        }
        $set = [];
        foreach (['description', 'allowed_values', 'notes'] as $k) {
            if (array_key_exists($k, $in)) {
                $set[$k] = (string)str_in($in, $k);
            }
        }
        if (array_key_exists('ref', $in)) {
            $parts = explode('.', (string)str_in($in, 'ref', 257), 2);
            $set['ref_table'] = mb_substr(trim($parts[0]), 0, 128) ?: null;
            $set['ref_column'] = isset($parts[1]) ? (mb_substr(trim($parts[1]), 0, 128) ?: null) : null;
        }
        // ข้อเสนอรอพิจารณา: ช่องที่คนกรอกแล้ว หรือกดไม่ใช้ ให้ลบข้อเสนอของช่องนั้น
        foreach (['description', 'allowed_values', 'ref'] as $k) {
            if ((array_key_exists($k, $in) && trim((string)$in[$k]) !== '') || (string)($in['dismiss'] ?? '') === $k) {
                Guesser::dismiss($c, $k);
                $c = q('SELECT * FROM dd_columns WHERE id = ?', [$c['id']])->fetch();
            }
        }
        if ((string)($in['dismiss'] ?? '') === 'all') {
            Guesser::dismiss($c, 'all');
        }
        // confirm = คนตรวจแล้วว่าความหมายที่ระบบเดาให้ถูกต้อง
        if ((isset($set['description']) && $set['description'] !== (string)$c['description'])
            || (!empty($in['confirm']) && (string)$c['description'] !== '')) {
            $set['described_by'] = $u['username'];
            $set['described_at'] = now();
        }
        if ($set) {
            $sql = [];
            foreach ($set as $k => $_) {
                $sql[] = "$k = ?";
            }
            q('UPDATE dd_columns SET ' . implode(', ', $sql) . ' WHERE id = ?', array_merge(array_values($set), [$c['id']]));
        }
        respond(['ok' => true]);
        break;

    // ------------------------------------------------------------ เดาความหมายด้วยกฎในระบบ
    case 'guess.table': // ข้อเสนอสำหรับตารางเดียว ไม่บันทึก
        list($p) = resolve_project($in, false);
        respond(['ok' => true, 'suggestions' => Guesser::suggestTable($p, guess_table($in, $p))]);
        break;

    case 'guess.data': // อ่านแถวล่าสุดจากฐานข้อมูลต้นทาง ตีความ แล้วเก็บตัวอย่างและข้อเสนอไว้รอพิจารณา
        list($p) = resolve_project($in, false, true);
        @set_time_limit(120);
        respond(['ok' => true] + Guesser::interpretTable($p, guess_table($in, $p)));
        break;

    case 'guess.clear': // ล้างข้อเสนอรอพิจารณาของตาราง (samples=1 ล้างตัวอย่างข้อมูลด้วย)
        list($p) = resolve_project($in, false, true);
        respond(['ok' => true, 'cleared' => Guesser::clearTable(guess_table($in, $p), !empty($in['samples']))]);
        break;

    case 'guess.plan': // นับว่าถ้าเดาทั้งโปรเจ็คจะเติมได้กี่คอลัมน์ ไม่บันทึก
        list($p) = resolve_project($in, false);
        respond(['ok' => true] + Guesser::plan($p, $in['sources'] ?? ''));
        break;

    case 'guess.apply':
        list($p, $actor) = resolve_project($in, false, true);
        respond(['ok' => true] + Guesser::apply($p, $in['sources'] ?? '', $actor));
        break;

    case 'guess.undo':
        list($p, $actor) = resolve_project($in, false, true);
        respond(['ok' => true] + Guesser::undo($p, $actor));
        break;

    // ------------------------------------------------------------ สำหรับ Claude / ระบบอื่น
    case 'missing': // ตารางที่ยังมีคอลัมน์ไม่มีคำอธิบาย ส่งทีละชุด
        list($p) = resolve_project($in, true);
        $limit = max(1, min(100, (int)($in['limit'] ?? 20)));
        $after = (string)str_in($in, 'after', 128);
        $cond = "t.project_id = ? AND t.is_dropped = 0 AND (" . sprintf(EMPTY_DESC, 't', 't')
            . " OR EXISTS (SELECT 1 FROM dd_columns c WHERE c.table_id = t.id AND c.is_dropped = 0 AND " . sprintf(EMPTY_DESC, 'c', 'c') . "))";
        $remaining = (int)q("SELECT COUNT(*) FROM dd_tables t WHERE $cond", [$p['id']])->fetchColumn();
        $rows = q("SELECT t.* FROM dd_tables t WHERE $cond AND t.table_name > ? ORDER BY t.table_name LIMIT $limit", [$p['id'], $after])->fetchAll();
        $out = [];
        foreach ($rows as $t) {
            $cols = [];
            foreach (q('SELECT * FROM dd_columns WHERE table_id = ? AND is_dropped = 0 ORDER BY ordinal_position, id', [$t['id']]) as $c) {
                $cols[] = ['name' => $c['column_name'], 'type' => $c['column_type'], 'key' => (string)$c['column_key'],
                    'description' => (string)$c['description'], 'allowed_values' => (string)$c['allowed_values']];
            }
            $out[] = ['name' => $t['table_name'], 'type' => $t['table_type'], 'description' => (string)$t['description'], 'columns' => $cols];
        }
        respond([
            'ok' => true, 'tables_remaining' => $remaining, 'tables' => $out,
            'next_after' => count($rows) === $limit ? $rows[count($rows) - 1]['table_name'] : null,
        ]);
        break;

    case 'export':
        list($p) = resolve_project($in, true);
        $format = (string)($in['format'] ?? 'json');
        if (!in_array($format, ['json', 'md', 'csv'], true)) {
            throw new ApiError('format ต้องเป็น json, md หรือ csv');
        }
        $only = array_values(array_filter(array_map('trim', explode(',', (string)($in['tables'] ?? '')))));
        @set_time_limit(600);
        Dictionary::export($p, $format, $only);
        break;

    case 'import':
        require_post();
        list($p, $actor, $viaKey) = resolve_project($in, true, true);
        @set_time_limit(600);
        if ($viaKey && self_filled($in, 'source')) {
            $actor = mb_substr('api: ' . (string)$in['source'], 0, 100);
        }
        respond(['ok' => true] + Dictionary::import($p, $in, $actor));
        break;

    default:
        throw new ApiError('ไม่รู้จักคำสั่ง action=' . $action, 404);
}

function guess_table(array $in, array $p): array
{
    $t = q('SELECT * FROM dd_tables WHERE id = ? AND project_id = ?', [(int)($in['id'] ?? 0), $p['id']])->fetch();
    if (!$t) {
        throw new ApiError('ไม่พบตารางที่ระบุ', 404);
    }
    return $t;
}

function self_filled(array $in, string $k): bool
{
    return isset($in[$k]) && !is_array($in[$k]) && trim((string)$in[$k]) !== '';
}
