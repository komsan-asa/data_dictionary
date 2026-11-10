<?php
/**
 * ซิงค์โครงสร้างจาก command line
 *
 *   php cli/sync.php <รหัสโปรเจ็ค>     ซิงค์โปรเจ็คเดียว
 *   php cli/sync.php --all             ซิงค์ทุกโปรเจ็คที่ตั้งค่า connection ไว้
 *   php cli/sync.php --job=<เลขงาน>    ใช้ภายใน: ทำงานที่หน้าเว็บสั่งไว้
 *
 * ใช้กับ Task Scheduler / cron เพื่อซิงค์อัตโนมัติได้ เช่นทุกคืน
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('DD_APP', true);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Introspector.php';
require __DIR__ . '/../src/Dictionary.php';

$arg = $argv[1] ?? '';
try {
    config(); // โหลดค่าตั้งและเขตเวลา
    if (strpos($arg, '--job=') === 0) {
        echo Dictionary::runSync((int)substr($arg, 6)), PHP_EOL;
        exit(0);
    }
    if ($arg === '--all') {
        $projects = q("SELECT * FROM projects WHERE conn_driver IS NOT NULL AND conn_host IS NOT NULL AND conn_database IS NOT NULL ORDER BY code")->fetchAll();
    } elseif ($arg !== '') {
        $projects = q("SELECT * FROM projects WHERE code = ?", [$arg])->fetchAll();
    } else {
        fwrite(STDERR, "วิธีใช้: php cli/sync.php <รหัสโปรเจ็ค> | --all\n");
        exit(2);
    }
    if (!$projects) {
        fwrite(STDERR, "ไม่พบโปรเจ็คที่ระบุ\n");
        exit(2);
    }
    $failed = 0;
    foreach ($projects as $p) {
        $job = Dictionary::latestSync((int)$p['id']);
        if ($job && $job['status'] === 'running') {
            echo $p['code'], ': มีงานซิงค์กำลังทำงานอยู่ ข้าม', PHP_EOL;
            continue;
        }
        $msg = Dictionary::runSync(Dictionary::startJob((int)$p['id'], 'cli'));
        echo $p['code'], ': ', $msg, PHP_EOL;
        if (strpos($msg, 'สำเร็จ') !== 0) {
            $failed++;
        }
    }
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ผิดพลาด: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
