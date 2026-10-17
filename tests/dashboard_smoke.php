<?php
declare(strict_types=1);

// Render privileged dashboards with synthetic CI accounts only. No real resident
// data or production account is used by this test.
if (!getenv('APP_KEY')) putenv('APP_KEY=' . str_repeat('d', 64));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['HTTP_USER_AGENT'] = 'DESA-DIGITAL-CI-dashboard-smoke';

session_name('desa_digital_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'desa_digital';
$dbUser = getenv('DB_USER') ?: 'ci_app';
$dbPass = getenv('DB_PASSWORD') ?: 'ci-app-pass';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$role = (string)($argv[1] ?? '');
$page = (string)($argv[2] ?? 'dashboard');
$allowed = [
    'admin_desa' => ['dashboard','admin-resident-export','admin-site','admin-audit'],
    'owner' => ['dashboard','admin-users','admin-resident-export','admin-site','admin-audit'],
    // Authorization regressions: residents must never see CMS or audit log content.
    'warga' => ['admin-site','admin-audit'],
];
if (!isset($allowed[$role]) || !in_array($page, $allowed[$role], true)) {
    fwrite(STDERR, "Unsupported dashboard smoke target.\n");
    exit(2);
}

$tag = bin2hex(random_bytes(6));
$username = 'ci-' . $role . '-' . $tag;
$userId = 0;
$exitCode = 0;
try {
    $testName = $role === 'owner' ? 'CI Owner' : ($role === 'admin_desa' ? 'CI Admin Desa' : 'CI Warga');
    $stmt = $pdo->prepare('INSERT INTO app_users (username,full_name,password_hash,role,is_active,must_change_password,session_version) VALUES (?,?,?,?,1,0,1)');
    $stmt->execute([$username, $testName, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role]);
    $userId = (int)$pdo->lastInsertId();

    $_SESSION['user'] = [
        'id'=>$userId,
        'username'=>$username,
        'full_name'=>$testName,
        'role'=>$role,
        'rt_id'=>null,
        'rw_id'=>null,
        'must_change_password'=>0,
        'session_version'=>1,
    ];
    $_GET = ['page'=>$page];
    $_POST = [];
    $_SERVER['REQUEST_URI'] = '/index.php?page=' . rawurlencode($page);
    ob_start();
    require dirname(__DIR__) . '/index.php';
    $html = (string)ob_get_clean();

    if ($page === 'dashboard') {
        $heading = $role === 'owner' ? 'Dashboard Owner' : 'Dashboard Admin Desa';
        if (!str_contains($html, $heading) || !str_contains($html, 'dashboard-metrics') || !str_contains($html, 'Unduh Data Penduduk')) {
            throw new RuntimeException('Dashboard headline, overview cards, or resident-export shortcut did not render.');
        }
        if ($role === 'owner' && !str_contains($html, 'Kontrol Owner')) {
            throw new RuntimeException('Owner-specific overview did not render.');
        }
    } elseif ($page === 'admin-users') {
        if (!str_contains($html, 'Buat akun Admin Desa') || !str_contains($html, 'module_create_admin_account')) {
            throw new RuntimeException('Owner Admin Desa account-management form did not render.');
        }
    } elseif ($page === 'admin-resident-export') {
        if (!str_contains($html, 'Siapkan ekspor') || !str_contains($html, 'resident-export-scope')) {
            throw new RuntimeException('Initial resident-export request did not render its filter form.');
        }
    } elseif ($page === 'admin-audit' && $role === 'warga') {
        if (!str_contains($html, 'Akses terbatas') || str_contains($html, 'Cari aktivitas') || str_contains($html, '<th>Pelaku</th>')) {
            throw new RuntimeException('Resident role must be denied the audit viewer and its activity records.');
        }
    } elseif ($page === 'admin-audit') {
        foreach (['Log Aktivitas','Cari aktivitas','Tindakan','Jenis data','ID objek','Ditemukan'] as $needle) {
            if (!str_contains($html, $needle)) {
                throw new RuntimeException('Audit log viewer is missing safe activity metadata or search controls: ' . $needle);
            }
        }
        if (str_contains($html, '<th>Detail perubahan</th>') || str_contains($html, 'name="before_data"')) {
            throw new RuntimeException('Audit viewer must not render raw before/after payloads.');
        }
    } elseif ($page === 'admin-site' && $role === 'warga') {
        if (!str_contains($html, 'Akses terbatas') || str_contains($html, 'data-site-setting-filter') || str_contains($html, 'name="hero_image"')) {
            throw new RuntimeException('Resident role must be denied the website editor and its image upload controls.');
        }
    } elseif ($page === 'admin-site') {
        foreach (['Tampilan Website','data-site-setting-filter','Foto utama beranda','name="hero_image"','name="logo_image"','reset_site_image[hero_image]','Terbitkan ke website','name="_csrf"'] as $needle) {
            if (!str_contains($html, $needle)) {
                throw new RuntimeException('CMS editor for ' . $role . ' is missing its settings, image controls, draft/publish actions, or CSRF field: ' . $needle);
            }
        }
    }
    fwrite(STDOUT, "PASS: {$role} / {$page} rendered.\n");
} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    fwrite(STDERR, "Dashboard smoke failed for {$role} / {$page}: " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM app_users WHERE id=?')->execute([$userId]);
    }
    unset($_SESSION['user']);
}
exit($exitCode);
