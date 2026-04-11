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
function log_action(string $action, string $entity, ?string $id, ?string $user_id): void {
    try {
        db()->prepare("INSERT INTO audit_log(id,user_id,action,entity,entity_id,ip,ts) VALUES(?,?,?,?,?,?,datetime('now'))")
           ->execute([uuid(), $user_id, $action, $entity, $id, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch(Exception) {}
}
