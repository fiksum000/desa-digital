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
    if ($secureCookie) {
        // Emit HSTS only when HTTPS is detected or explicitly enabled for the proxy.
        header('Strict-Transport-Security: max-age=15552000');
    }
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// App HTML may contain session tokens, tracking results, and authenticated pages.
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' blob:; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'");

function env_value(string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function app_url(string $page = 'home', array $params = []): string {
    $query = array_merge(['page' => $page], $params);
    return '?' . http_build_query($query);
}

/**
 * Protect national identifiers at rest. APP_KEY must be a long random secret
 * and must be backed up securely; losing it makes encrypted identifiers unrecoverable.
 */
function private_identifier_key(string $purpose): string {
    $configured = trim(env_value('APP_KEY', ''));
    if (strlen($configured) < 32 || preg_match('/replace|placeholder|example|change-me|dev-only/i', $configured)) {
        throw new RuntimeException('APP_KEY belum diisi dengan rahasia acak minimal 32 karakter.');
    }
    return hash_hmac('sha256', 'DESA-DIGITAL:' . $purpose, $configured, true);
}

function normalize_private_identifier(string $value): string {
    $digits = preg_replace('/\\D+/', '', $value) ?? '';
    if (!preg_match('/^[0-9]{16}$/', $digits)) {
        throw new RuntimeException('NIK dan nomor KK harus terdiri dari 16 digit angka.');
    }
    return $digits;
}

function private_identifier_hash(string $value): string {
    $digits = normalize_private_identifier($value);
    return hash_hmac('sha256', $digits, private_identifier_key('identifier-index'));
}

function encrypt_private_identifier(string $value): string {
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('Ekstensi sodium dibutuhkan untuk melindungi NIK dan nomor KK.');
    }
    $digits = normalize_private_identifier($value);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($digits, $nonce, private_identifier_key('identifier-encryption'));
    return base64_encode($nonce . $ciphertext);
}

function decrypt_private_identifier(string $value): ?string {
    if (!function_exists('sodium_crypto_secretbox_open')) {
        throw new RuntimeException('Ekstensi sodium dibutuhkan untuk membaca NIK dan nomor KK.');
    }
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, private_identifier_key('identifier-encryption'));
    return is_string($plain) && preg_match('/^[0-9]{16}$/', $plain) ? $plain : null;
}

function mask_private_identifier(string $value): string {
    $digits = normalize_private_identifier($value);
    return str_repeat('*', 12) . substr($digits, -4);
}

function auth_rate_limit_keys(string $username): array {
    $key = private_identifier_key('authentication-rate-limit');
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if ($ip === '') $ip = 'unknown';
    return [
        'account' => hash_hmac('sha256', "account\0" . strtolower(trim($username)), $key),
        'ip' => hash_hmac('sha256', "ip\0" . $ip, $key),
    ];
}

function auth_rate_limit_assert_allowed(string $username): void {
    $keys = auth_rate_limit_keys($username);
    $now = time();
    $stmt = db()->prepare('SELECT bucket_type,attempts,window_started_at,locked_until FROM auth_attempts WHERE bucket_hash=? LIMIT 1');
    foreach ($keys as $type => $hash) {
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) continue;
        $limit = $type === 'account' ? 5 : 20;
        $windowStart = strtotime((string)$row['window_started_at']) ?: 0;
        $lockUntil = !empty($row['locked_until']) ? (strtotime((string)$row['locked_until']) ?: 0) : 0;
        if ($lockUntil > $now || ((int)$row['attempts'] >= $limit && $windowStart > $now - 600)) {
            throw new RuntimeException('Terlalu banyak percobaan masuk dari akun atau jaringan ini. Tunggu 10 menit lalu coba kembali.');
        }
    }
}

function auth_rate_limit_record_failure(string $username): void {
    $keys = auth_rate_limit_keys($username);
    // Sort keys to keep row lock order deterministic and reduce deadlock risk.
    asort($keys, SORT_STRING);
    $pdo = db();
    $now = time();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO auth_attempts (bucket_hash,bucket_type,attempts,window_started_at,locked_until) VALUES (?,?,0,NOW(),NULL)');
        $select = $pdo->prepare('SELECT attempts,window_started_at,locked_until FROM auth_attempts WHERE bucket_hash=? FOR UPDATE');
        $update = $pdo->prepare('UPDATE auth_attempts SET attempts=?,window_started_at=?,locked_until=?,updated_at=NOW() WHERE bucket_hash=?');
        foreach ($keys as $type => $hash) {
            $insert->execute([$hash,$type]);
            $select->execute([$hash]);
            $row = $select->fetch();
            if (!$row) throw new RuntimeException('Pembatasan percobaan login tidak dapat diperbarui.');
            $windowStart = strtotime((string)$row['window_started_at']) ?: 0;
            $lockUntil = !empty($row['locked_until']) ? (strtotime((string)$row['locked_until']) ?: 0) : 0;
            $attempts = (int)$row['attempts'];
            if ($windowStart <= $now - 600 || ($lockUntil > 0 && $lockUntil <= $now)) {
                $attempts = 0;
                $windowStart = $now;
            }
            $attempts++;
            $limit = $type === 'account' ? 5 : 20;
            $locked = $attempts >= $limit ? date('Y-m-d H:i:s', $now + 600) : null;
            $update->execute([$attempts,date('Y-m-d H:i:s',$windowStart),$locked,$hash]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function auth_rate_limit_clear_account(string $username): void {
    $keys = auth_rate_limit_keys($username);
    db()->prepare("DELETE FROM auth_attempts WHERE bucket_hash=? AND bucket_type='account'")->execute([$keys['account']]);
}

/**
 * Atomically limit write actions for an authenticated account using the existing
 * authentication-throttle table. Keys are HMACs; raw account IDs are not stored.
 */
function user_action_rate_limit_consume(string $action, int $userId, int $limit = 10, int $windowSeconds = 3600): void {
    if ($userId < 1 || !preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $action) || $limit < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Konfigurasi pembatasan tindakan tidak valid.');
    }
    $key = private_identifier_key('user-action-rate-limit');
    $bucketHash = hash_hmac('sha256', 'user-action|' . $action . '|' . $userId, $key);
    $pdo = db();
    $now = time();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT IGNORE INTO auth_attempts (bucket_hash,bucket_type,attempts,window_started_at,locked_until) VALUES (?,'account',0,NOW(),NULL)");
        $insert->execute([$bucketHash]);
        $select = $pdo->prepare('SELECT attempts,window_started_at,locked_until FROM auth_attempts WHERE bucket_hash=? FOR UPDATE');
        $select->execute([$bucketHash]);
        $row = $select->fetch();
        if (!$row) throw new RuntimeException('Pembatasan pengiriman tidak dapat diperbarui.');

        $windowStart = strtotime((string)$row['window_started_at']) ?: $now;
        $lockedUntil = !empty($row['locked_until']) ? (strtotime((string)$row['locked_until']) ?: 0) : 0;
        $attempts = (int)$row['attempts'];
        if ($windowStart <= $now - $windowSeconds || ($lockedUntil > 0 && $lockedUntil <= $now)) {
            $attempts = 0;
            $windowStart = $now;
            $lockedUntil = 0;
        }
        if ($lockedUntil > $now || $attempts >= $limit) {
            $pdo->rollBack();
            throw new RuntimeException('Batas pengiriman tercapai. Tunggu hingga pembatasan berakhir sebelum mencoba lagi.');
        }

        $attempts++;
        $lock = $attempts >= $limit ? date('Y-m-d H:i:s', $windowStart + $windowSeconds) : null;
        $update = $pdo->prepare('UPDATE auth_attempts SET attempts=?,window_started_at=?,locked_until=?,updated_at=NOW() WHERE bucket_hash=?');
        $update->execute([$attempts,date('Y-m-d H:i:s',$windowStart),$lock,$bucketHash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) return null;
    $user = $_SESSION['user'];
    try {
        $stmt = db()->prepare('SELECT is_active, session_version FROM app_users WHERE id=? LIMIT 1');
        $stmt->execute([(int)($user['id'] ?? 0)]);
        $state = $stmt->fetch();
        if (!$state || !(int)$state['is_active']
            || (int)($user['session_version'] ?? 0) !== (int)$state['session_version']) {
            unset($_SESSION['user']);
            session_regenerate_id(true);
            return null;
        }
    } catch (Throwable $ignored) {
        // Fail closed for authenticated areas when the account state cannot be verified.
        return null;
    }
    return $user;
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
    return in_array($role, ['petugas', 'admin_desa', 'owner'], true);
}

function can_process_requests(?array $user = null): bool {
    $role = ($user ?? current_user())['role'] ?? '';
    return in_array($role, ['petugas', 'admin_desa', 'owner', 'kepala_desa'], true);
}

function role_label(string $role): string {
    return [
        'warga' => 'Warga',
        'rt' => 'Ketua RT',
        'rw' => 'Ketua RW',
        'petugas' => 'Petugas Desa',
        'admin_desa' => 'Admin Desa',
        'owner' => 'Owner',
        'kepala_desa' => 'Kepala Desa',
    ][$role] ?? 'Pengguna';
}

function tracking_code(string $prefix): string {
    // 128-bit random suffix makes public tracking tokens impractical to enumerate.
    // Existing installations widen their columns with bin/migrate_tracking_codes.php.
    return strtoupper($prefix . '-' . date('ymd') . '-' . bin2hex(random_bytes(16)));
}
