<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (getenv('SESSION_SECURE_COOKIE') === 'true')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('desa_digital_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

function env_value(string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function app_url(string $page = 'home', array $params = []): string {
    $query = array_merge(['page' => $page], $params);
    return '?' . http_build_query($query);
}

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME', 'desa_digital');
    $user = env_value('DB_USER', 'desa_app');
    $pass = env_value('DB_PASSWORD', '');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Silakan kembali dan coba lagi.');
    }
}

function current_user(): ?array {
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: ' . app_url('login'));
        exit;
    }
    return $user;
}

function require_roles(array $roles): array {
    $user = require_login();
    if (!in_array($user['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk membuka halaman ini.');
    }
    return $user;
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array {
    $value = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($value) ? $value : null;
}

function redirect_to(string $page, array $params = []): never {
    header('Location: ' . app_url($page, $params));
    exit;
}

function audit_log(?int $actorId, string $action, string $entityType, ?string $entityId = null, ?array $before = null, ?array $after = null): void {
    try {
        $key = env_value('APP_KEY', 'dev-only-change-this');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = $ip !== '' ? hash_hmac('sha256', $ip, $key) : null;
        $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $actorId,
            $action,
            $entityType,
            $entityId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $ipHash,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $ignored) {
        // Failure to record an audit event must not display sensitive database details.
        error_log('Audit log could not be written.');
    }
}

function can_manage_content(?array $user = null): bool {
    $role = ($user ?? current_user())['role'] ?? '';
    return in_array($role, ['petugas', 'admin_desa'], true);
}

function can_process_requests(?array $user = null): bool {
    $role = ($user ?? current_user())['role'] ?? '';
    return in_array($role, ['petugas', 'admin_desa', 'kepala_desa'], true);
}

function role_label(string $role): string {
    return [
        'warga' => 'Warga',
        'rt' => 'Ketua RT',
        'rw' => 'Ketua RW',
        'petugas' => 'Petugas Desa',
        'admin_desa' => 'Admin Desa',
        'kepala_desa' => 'Kepala Desa',
    ][$role] ?? 'Pengguna';
}

function tracking_code(string $prefix): string {
    return strtoupper($prefix . '-' . date('ymd') . '-' . substr(bin2hex(random_bytes(5)), 0, 8));
}
