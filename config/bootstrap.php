<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('กรุณาสร้างไฟล์ config/config.php จาก config.example.php ก่อนใช้งาน');
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Asia/Bangkok');
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}
function db(): PDO {
    global $config;
    static $pdo;
    if (!$pdo) {
        $d = $config['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], $d['port'] ?? 3306, $d['name']);
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $cached = null;
    if ($cached && $cached['id'] === (int)$_SESSION['uid']) return $cached;
    $q = db()->prepare('SELECT id,username,name,email,role,department_id,manager_id,position FROM users WHERE id=? AND is_active=1');
    $q->execute([(int)$_SESSION['uid']]);
    $cached = $q->fetch() ?: null;
    return $cached;
}
function requireUser(): array {
    $u = user();
    if (!$u) jsonResponse(['ok'=>false,'message'=>'กรุณาเข้าสู่ระบบใหม่'], 401);
    return $u;
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verifyCsrf(): void {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        jsonResponse(['ok'=>false,'message'=>'CSRF token ไม่ถูกต้อง'], 419);
    }
}
function isLeader(array $u): bool { return in_array($u['role'], ['superadmin','admin','CEO','manager','HR'], true); }
function isGlobal(array $u): bool { return in_array($u['role'], ['superadmin','admin','CEO'], true); }
function canAssign(array $u, int $target): bool {
    if ($target === (int)$u['id']) return true;
    if (!isLeader($u)) return false;
    $stmt = db()->prepare('SELECT id,department_id,manager_id FROM users WHERE id=? AND is_active=1');
    $stmt->execute([$target]);
    $v = $stmt->fetch();
    if (!$v) return false;
    if (isGlobal($u)) return true;
    if ((int)$v['manager_id'] === (int)$u['id']) return true;
    return $u['role'] === 'manager' && $u['department_id'] !== null && (int)$v['department_id'] === (int)$u['department_id'];
}
function canSeeTask(array $u, array $t): bool {
    if (isGlobal($u)) return true;
    if ((int)$t['created_by'] === (int)$u['id'] || (int)$t['assigned_to'] === (int)$u['id']) return true;
    if (!isLeader($u)) return false;
    if ($u['role'] === 'HR') return false;
    $assigned = (int)$t['assigned_to'];
    return $assigned > 0 && canAssign($u, $assigned);
}
function taskById(int $id): ?array {
    $q = db()->prepare('SELECT * FROM works WHERE id=?'); $q->execute([$id]);
    return $q->fetch() ?: null;
}
function audit(int $uid, string $action, string $description): void {
    $q = db()->prepare('INSERT INTO activity_log (user_id,action_type,description,ip_address) VALUES (?,?,?,?)');
    $q->execute([$uid,$action,mb_substr($description,0,1000),$_SERVER['REMOTE_ADDR'] ?? null]);
}
function notifyUser(?int $uid, string $type, int $taskId, string $message, string $eventKey): void {
    if (!$uid || $uid < 1) return;
    $q = db()->prepare('INSERT IGNORE INTO task_notifications (user_id,work_id,type,message,event_key) VALUES (?,?,?,?,?)');
    $q->execute([$uid,$taskId,$type,$message,$eventKey]);
}
function postStr(string $key, int $max = 255): string { return mb_substr(trim((string)($_POST[$key] ?? '')),0,$max); }
function postInt(string $key): int { return filter_var($_POST[$key] ?? 0, FILTER_VALIDATE_INT) ?: 0; }
function dateValid(string $date): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
