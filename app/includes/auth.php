<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/normalization.php';

// Do not expose PHP/database details to users if a local error occurs.
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
header('Cache-Control: no-store, private');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ofw_dormitory_session');
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $cookiePath = rtrim($scriptDirectory, '/') . '/';
    session_set_cookie_params([
        'path' => $cookiePath === '//' ? '/' : $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_valid_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Your form session expired. Please return to the previous page and try again.');
    }
}

function current_user(): ?array
{
    static $resolved = false;
    static $current = null;

    if ($resolved) {
        return $current;
    }
    $resolved = true;

    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser)) {
        return null;
    }

    $userId = filter_var($sessionUser['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($userId === false || ($lastActivity > 0 && time() - $lastActivity > 7200)) {
        unset($_SESSION['user'], $_SESSION['last_activity']);
        return null;
    }

    $statement = db()->prepare("SELECT id,full_name,username,role FROM users WHERE id=:id AND status='active' LIMIT 1");
    $statement->execute(['id' => (int) $userId]);
    $databaseUser = $statement->fetch();
    if (!$databaseUser) {
        unset($_SESSION['user'], $_SESSION['last_activity']);
        return null;
    }

    $current = [
        'id' => (int) $databaseUser['id'],
        'full_name' => (string) $databaseUser['full_name'],
        'username' => (string) $databaseUser['username'],
        'role' => (string) $databaseUser['role'],
    ];
    $_SESSION['user'] = $current;
    $_SESSION['last_activity'] = time();
    return $current;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function login_attempt_key(string $username): string
{
    $normalizedUsername = mb_strtolower(trim($username), 'UTF-8');
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $normalizedUsername . "\0" . $remoteAddress);
}

function login_retry_after(string $username): int
{
    if ($username === '') {
        return 0;
    }
    $statement = db()->prepare('SELECT blocked_until,window_started FROM login_attempts WHERE attempt_key=:attempt_key');
    $statement->execute(['attempt_key' => login_attempt_key($username)]);
    $attempt = $statement->fetch();
    if (!$attempt) {
        return 0;
    }

    $now = new DateTimeImmutable();
    $windowStarted = new DateTimeImmutable((string) $attempt['window_started']);
    if ($windowStarted < $now->modify('-15 minutes')) {
        db()->prepare('DELETE FROM login_attempts WHERE attempt_key=:attempt_key')->execute(['attempt_key' => login_attempt_key($username)]);
        return 0;
    }

    if ($attempt['blocked_until'] === null) {
        return 0;
    }
    $blockedUntil = new DateTimeImmutable((string) $attempt['blocked_until']);
    return max(0, $blockedUntil->getTimestamp() - $now->getTimestamp());
}

function record_failed_login(string $username): void
{
    if ($username === '') {
        return;
    }
    $pdo = db();
    $attemptKey = login_attempt_key($username);
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = new DateTimeImmutable();
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT attempt_count,window_started FROM login_attempts WHERE attempt_key=:attempt_key FOR UPDATE');
        $statement->execute(['attempt_key' => $attemptKey]);
        $attempt = $statement->fetch();
        $attemptCount = 1;
        $windowStarted = $now;
        if ($attempt) {
            $existingWindow = new DateTimeImmutable((string) $attempt['window_started']);
            if ($existingWindow >= $now->modify('-15 minutes')) {
                $attemptCount = (int) $attempt['attempt_count'] + 1;
                $windowStarted = $existingWindow;
            }
        }
        $blockedUntil = $attemptCount >= 8 ? $now->modify('+10 minutes')->format('Y-m-d H:i:s') : null;
        $upsert = $pdo->prepare('INSERT INTO login_attempts (attempt_key,attempted_username,remote_address,attempt_count,window_started,blocked_until) VALUES (:attempt_key,:username,:remote_address,:attempt_count,:window_started,:blocked_until) ON DUPLICATE KEY UPDATE attempted_username=VALUES(attempted_username),remote_address=VALUES(remote_address),attempt_count=VALUES(attempt_count),window_started=VALUES(window_started),blocked_until=VALUES(blocked_until)');
        $upsert->execute(['attempt_key' => $attemptKey, 'username' => mb_substr(trim($username), 0, 50), 'remote_address' => mb_substr($remoteAddress, 0, 45), 'attempt_count' => $attemptCount, 'window_started' => $windowStarted->format('Y-m-d H:i:s'), 'blocked_until' => $blockedUntil]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Login throttling update failed.');
    }
}

function clear_failed_logins(string $username): void
{
    if ($username === '') {
        return;
    }
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key=:attempt_key')->execute(['attempt_key' => login_attempt_key($username)]);
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }

    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }

    return $user;
}

function permission_catalog(): array
{
    return [
        'dashboard' => ['Dashboard', 'View overview and statistics'],
        'dormitories' => ['Dormitories & Rooms', 'Manage dormitories, rooms, and capacity'],
        'tenants' => ['Tenants', 'View and manage tenant records'],
        'payments' => ['Payments', 'Record and view rent payments'],
        'visitors' => ['Visitor Log', 'Check visitors in and out'],
        'maintenance' => ['Maintenance', 'Create and update maintenance requests'],
        'calendar' => ['Calendar', 'View and manage schedules'],
        'reports' => ['Reports', 'View and export reports'],
    ];
}

function permitted_landing_page(array $user): string
{
    if ($user['role'] === 'admin') {
        return 'dashboard.php';
    }
    $pages = [
        'dashboard' => 'dashboard.php', 'dormitories' => 'dormitories.php', 'tenants' => 'tenants.php',
        'payments' => 'payments.php', 'visitors' => 'visitors.php', 'maintenance' => 'maintenance.php',
        'calendar' => 'calendar.php', 'reports' => 'reports.php',
    ];
    $statement = db()->prepare("SELECT permission_key FROM staff_permissions WHERE user_id = :user_id");
    $statement->execute(['user_id' => $user['id']]);
    $assigned = array_flip($statement->fetchAll(PDO::FETCH_COLUMN));
    foreach ($pages as $permission => $page) {
        if (isset($assigned[$permission])) {
            return $page;
        }
    }
    return 'no_access.php';
}

function user_permissions(): array
{
    static $permissions = null;
    $user = current_user();
    if ($user === null) {
        return [];
    }
    if ($user['role'] === 'admin') {
        return array_keys(permission_catalog());
    }
    if (is_array($permissions)) {
        return $permissions;
    }
    $statement = db()->prepare("SELECT sp.permission_key FROM staff_permissions sp INNER JOIN users u ON u.id = sp.user_id WHERE sp.user_id = :user_id AND u.status = 'active'");
    $statement->execute(['user_id' => $user['id']]);
    $permissions = array_column($statement->fetchAll(), 'permission_key');
    return $permissions;
}

function has_permission(string $permission): bool
{
    return in_array($permission, user_permissions(), true);
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!array_key_exists($permission, permission_catalog()) || !has_permission($permission)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
    return $user;
}

function app_setting(string $key): ?string
{
    static $settings = [];
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    $statement = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :setting_key');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();
    $settings[$key] = $value === false ? null : (string) $value;
    return $settings[$key];
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
    $_SESSION['last_activity'] = time();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}
