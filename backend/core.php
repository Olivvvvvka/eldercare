<?php
// ============================================================
// ElderCare — core.php
// Всё в одном файле: БД, авторизация, вспомогательные функции
// Токен передаётся в POST-поле "_token" — работает в любом XAMPP
// ============================================================

define('JWT_SECRET', 'eldercare-2025-secret');
define('JWT_TTL',    86400); // 24 часа
define('DB_FILE',    __DIR__ . '/db/eldercare.sqlite');
define('BP_CRIT_SYS', 160);
define('BP_CRIT_DIA', 100);

// --- Таймзона ---
// Жёстко задаём Europe/Moscow, чтобы не зависеть от настроек php.ini на конкретной
// машине (XAMPP по умолчанию ставит Europe/Berlin, что даёт смещение -1 ч от МСК).
// Это гарантирует, что recorded_at, ts в audit_log, created_at в events/comments
// и любые другие даты, формируемые через date()/time(), будут в московском времени.
date_default_timezone_set('Europe/Moscow');

// --- Заголовки ---
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// --- БД ---
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
    return $pdo;
}

// --- UUID ---
function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

// --- JWT ---
function jwt_make(array $data): string {
    $h = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $data['exp'] = time() + JWT_TTL;
    $p = base64_encode(json_encode($data));
    $s = base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$s";
}

function jwt_parse(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h,$p,$s] = $parts;
    if (!hash_equals(base64_encode(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)), $s)) return null;
    $d = json_decode(base64_decode($p), true);
    return ($d && $d['exp'] > time()) ? $d : null;
}

// --- Получить токен из запроса ---
// Работает через POST-поле _token (надёжно в XAMPP)
// или через GET-параметр _token (для GET-запросов)
function get_token(): string {
    // POST body field (самый надёжный)
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!empty($body['_token'])) return $body['_token'];
    // GET parameter
    if (!empty($_GET['_token'])) return $_GET['_token'];
    // Authorization header (если вдруг работает)
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) return substr($auth, 7);
    return '';
}

// --- Текущий пользователь ---
function auth(): ?array {
    $token = get_token();
    if (!$token) return null;
    return jwt_parse($token);
}

function require_auth(): array {
    $u = auth();
    if (!$u) die_json(['error' => 'Не авторизован'], 401);
    return $u;
}

// --- Ответ ---
function die_json($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Проверка доступа к пациенту ---
function can_access(string $patient_id, string $user_id): bool {
    $s = db()->prepare("SELECT 1 FROM access WHERE patient_id=? AND user_id=? AND is_active=1");
    $s->execute([$patient_id, $user_id]);
    return (bool)$s->fetch();
}

// --- Лог ---
// ts пишем через PHP date() — он использует таймзону из php.ini (Europe/Moscow),
// в отличие от SQLite datetime('now'), который всегда UTC.
// Так логика согласована с records.recorded_at (тоже PHP date()).
function log_action(string $action, string $entity, ?string $id, ?string $user_id): void {
    try {
        db()->prepare("INSERT INTO audit_log(id,user_id,action,entity,entity_id,ip,ts) VALUES(?,?,?,?,?,?,?)")
           ->execute([uuid(), $user_id, $action, $entity, $id, $_SERVER['REMOTE_ADDR'] ?? null, date('Y-m-d H:i:s')]);
    } catch(Exception) {}
}

// --- Создание события (с локальным временем) ---
// Использовать вместо прямого INSERT INTO events — гарантирует, что created_at
// записывается в локальной таймзоне (Europe/Moscow), а не в UTC.
function insert_event(string $pid, string $type, string $severity, string $message): string {
    $id = uuid();
    db()->prepare("INSERT INTO events(id,patient_id,type,severity,message,created_at) VALUES(?,?,?,?,?,?)")
       ->execute([$id, $pid, $type, $severity, $message, date('Y-m-d H:i:s')]);
    return $id;
}

// --- Создание комментария (с локальным временем) ---
function insert_comment(string $pid, string $author_id, string $type, string $visibility, string $content): string {
    $id = uuid();
    db()->prepare("INSERT INTO comments(id,patient_id,author_id,type,visibility,content,created_at) VALUES(?,?,?,?,?,?,?)")
       ->execute([$id, $pid, $author_id, $type, $visibility, $content, date('Y-m-d H:i:s')]);
    return $id;
}

// ============================================================
// TOTP (Time-based One-Time Password) — RFC 6238
// ============================================================
function verifyTotp(string $secret, string $code, int $window = 1): bool {
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    // Декодируем base32
    $secret = strtoupper(preg_replace('/\s/', '', $secret));
    $n = 0; $j = 0; $binary = '';
    for ($i = 0; $i < strlen($secret); $i++) {
        $n = ($n << 5) + strpos($base32Chars, $secret[$i]);
        $j += 5;
        if ($j >= 8) { $j -= 8; $binary .= chr(($n >> $j) & 0xFF); }
    }
    $timeStep = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $t   = pack('N*', 0) . pack('N*', $timeStep + $i);
        $hash= hash_hmac('sha1', $t, $binary, true);
        $offset = ord($hash[19]) & 0xF;
        $otp = ((ord($hash[$offset])&0x7F)<<24 | (ord($hash[$offset+1])&0xFF)<<16 |
                (ord($hash[$offset+2])&0xFF)<<8  | (ord($hash[$offset+3])&0xFF)) % 1000000;
        if (str_pad($otp, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}

// ============================================================
// Шифрование чувствительных данных (AES-256-CBC)
// ============================================================
// Префикс для опознания «нашего» шифртекста. Помогает корректно работать с
// старыми незашифрованными значениями (обратная совместимость).
define('ENCRYPT_PREFIX', 'enc1:');
define('ENCRYPT_KEY',    hash('sha256', JWT_SECRET . '_encrypt', true));

function encryptField(?string $value): string {
    if ($value === null || $value === '') return '';
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($value, 'AES-256-CBC', ENCRYPT_KEY, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) return $value; // на всякий случай — лучше открытый текст, чем потеря
    return ENCRYPT_PREFIX . base64_encode($iv . $enc);
}

function decryptField(?string $value): string {
    if ($value === null || $value === '') return '';
    // Старые незашифрованные данные — отдаём как есть.
    if (!str_starts_with($value, ENCRYPT_PREFIX)) return $value;
    $data = base64_decode(substr($value, strlen(ENCRYPT_PREFIX)), true);
    if ($data === false || strlen($data) < 17) return ''; // битый шифртекст
    $iv  = substr($data, 0, 16);
    $enc = substr($data, 16);
    $plain = openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
