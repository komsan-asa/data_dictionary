<?php
/**
 * ส่วนกลางของระบบ: โหลด config, เชื่อมต่อฐานข้อมูล, session, ฟังก์ชันช่วย
 * รองรับ PHP 7.4 ขึ้นไป
 */
declare(strict_types=1);

if (!defined('DD_APP')) {
    http_response_code(403);
    exit;
}

class ApiError extends Exception
{
    public $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/config.php';
        if (!is_file($file)) {
            throw new ApiError('ไม่พบไฟล์ config/config.php ให้คัดลอกจาก config.sample.php แล้วแก้ค่าเชื่อมต่อ', 500);
        }
        $cfg = require $file;
        date_default_timezone_set((string)($cfg['timezone'] ?? 'Asia/Bangkok'));
    }
    return $cfg;
}

function make_pdo(): PDO
{
    $c = config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'],
        (int)($c['port'] ?? 3306),
        $c['name']
    );
    try {
        return new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new ApiError('เชื่อมต่อฐานข้อมูล ' . $c['name'] . ' ไม่ได้ ตรวจค่าใน config/config.php และรัน database/schema.sql แล้วหรือยัง', 500);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = make_pdo();
    }
    return $pdo;
}

/** การเชื่อมต่อที่สอง ใช้รายงานความคืบหน้าของงานซิงค์ ให้เห็นได้ทันทีแม้งานหลักยังอยู่ใน transaction */
function db2(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = make_pdo();
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

// ---------- input / output ----------

function read_input(): array
{
    $in = $_GET;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                throw new ApiError('เนื้อหาที่ส่งมาไม่ใช่ JSON ที่ถูกต้อง: ' . json_last_error_msg());
            }
            $in = $body + $in;
        }
    }
    return $in;
}

function respond($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * ตอบกลับเบราว์เซอร์ทันที แล้วให้สคริปต์ทำงานต่อเบื้องหลัง
 * (ใช้เมื่อไม่ได้ตั้ง php_cli ไว้ใน config)
 */
function respond_and_continue(array $data): void
{
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // ปล่อย session lock ไม่ให้คำขออื่นของผู้ใช้ค้างรอ
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
}

/** สั่ง PHP CLI ให้ทำงานแยกเป็น process เบื้องหลัง คืน false ถ้าสั่งไม่ได้ */
function spawn_cli(string $php, array $args): bool
{
    if (!is_file($php)) {
        return false; // ตั้ง php_cli ผิด ให้กลับไปใช้วิธีสำรอง
    }
    $cmd = escapeshellarg($php);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        if (!function_exists('popen')) {
            return false;
        }
        $h = @popen('start /B "" ' . $cmd . ' > NUL 2>&1', 'r');
        if ($h === false) {
            return false;
        }
        pclose($h);
        return true;
    }
    if (!function_exists('exec')) {
        return false;
    }
    $rc = 1;
    $out = [];
    @exec('nohup ' . $cmd . ' > /dev/null 2>&1 &', $out, $rc);
    return $rc === 0;
}

function str_in(array $in, string $key, int $max = 65000): ?string
{
    if (!array_key_exists($key, $in) || $in[$key] === null) {
        return null;
    }
    if (is_array($in[$key])) {
        throw new ApiError("ค่า $key ต้องเป็นข้อความ");
    }
    $v = trim((string)$in[$key]);
    return mb_substr($v, 0, $max);
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new ApiError('คำสั่งนี้ต้องเรียกด้วย POST', 405);
    }
}

// ---------- เข้ารหัสรหัสผ่าน connection ----------

function secret_key(): string
{
    $k = (string)(config()['app_key'] ?? '');
    if (strlen($k) < 16) {
        throw new ApiError('ยังไม่ได้ตั้งค่า app_key ใน config/config.php (อย่างน้อย 16 ตัวอักษร)', 500);
    }
    return hash('sha256', $k, true);
}

function encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function decrypt_secret(?string $enc): string
{
    if ($enc === null || $enc === '') {
        return '';
    }
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new ApiError('ถอดรหัสรหัสผ่าน connection ไม่ได้ ให้บันทึกรหัสผ่านใหม่', 500);
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) {
        throw new ApiError('ถอดรหัสรหัสผ่าน connection ไม่ได้ (app_key ถูกเปลี่ยน?) ให้บันทึกรหัสผ่านใหม่', 500);
    }
    return $plain;
}

// ---------- session / สิทธิ์ ----------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('DDSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function current_user(): ?array
{
    start_session();
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

/** ต้องล็อกอินหน้าเว็บ และคำสั่งที่แก้ข้อมูลต้องมี CSRF token */
function require_user(bool $write = false): array
{
    $u = current_user();
    if (!$u) {
        throw new ApiError('กรุณาเข้าสู่ระบบ', 401);
    }
    if ($write) {
        require_post();
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(csrf_token(), (string)$sent)) {
            throw new ApiError('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่', 403);
        }
    }
    return $u;
}

function sent_api_key(): string
{
    $key = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($key === '') {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($auth, 'Bearer ') === 0) {
            $key = trim(substr($auth, 7));
        }
    }
    return $key;
}

/**
 * หาโปรเจ็คที่คำสั่งนี้ทำงานด้วย และตรวจสิทธิ์
 * - ผู้ใช้หน้าเว็บ: ระบุ project (รหัส) หรือ project_id
 * - API key: ผูกกับโปรเจ็คเดียว อ่านและนำเข้าได้เท่านั้น
 * คืนค่า [project, actor, isApiKey]
 */
function resolve_project(array $in, bool $allowApiKey, bool $write = false): array
{
    $key = sent_api_key();
    if ($key !== '') {
        if (!$allowApiKey) {
            throw new ApiError('คำสั่งนี้ใช้ได้เฉพาะผู้ใช้ที่เข้าสู่ระบบหน้าเว็บ', 403);
        }
        $p = q('SELECT * FROM projects WHERE api_key_hash = ?', [hash('sha256', $key)])->fetch();
        if (!$p) {
            usleep(300000);
            throw new ApiError('API key ไม่ถูกต้อง', 401);
        }
        $want = str_in($in, 'project');
        if ($want !== null && $want !== '' && strcasecmp($want, $p['code']) !== 0) {
            throw new ApiError('API key นี้ใช้กับโปรเจ็ค ' . $p['code'] . ' เท่านั้น', 403);
        }
        return [$p, 'api-key', true];
    }

    $u = require_user($write);
    if (!empty($in['project_id'])) {
        $p = q('SELECT * FROM projects WHERE id = ?', [(int)$in['project_id']])->fetch();
    } else {
        $p = q('SELECT * FROM projects WHERE code = ?', [(string)(str_in($in, 'project') ?? '')])->fetch();
    }
    if (!$p) {
        throw new ApiError('ไม่พบโปรเจ็คที่ระบุ', 404);
    }
    return [$p, $u['username'], false];
}

/** ข้อมูลโปรเจ็คที่ส่งออกไปหน้าเว็บได้ (ไม่มีรหัสผ่าน/hash) */
function project_public(array $p): array
{
    return [
        'id'            => (int)$p['id'],
        'code'          => $p['code'],
        'name'          => $p['name'],
        'description'   => $p['description'],
        'api_key_hint'  => $p['api_key_hint'],
        'conn_driver'   => $p['conn_driver'],
        'conn_host'     => $p['conn_host'],
        'conn_port'     => $p['conn_port'] !== null ? (int)$p['conn_port'] : null,
        'conn_database' => $p['conn_database'],
        'conn_schema'   => $p['conn_schema'],
        'conn_username' => $p['conn_username'],
        'conn_charset'  => $p['conn_charset'],
        'has_password'  => $p['conn_password_enc'] !== null && $p['conn_password_enc'] !== '',
        'has_connection' => !empty($p['conn_driver']) && !empty($p['conn_host']) && !empty($p['conn_database']),
        'last_sync_at'  => $p['last_sync_at'],
    ];
}
