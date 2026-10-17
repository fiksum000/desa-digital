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

require_once dirname(__DIR__) . '/app/bootstrap.php';
$role = (string)($argv[1] ?? '');
$page = (string)($argv[2] ?? 'dashboard');
$allowed = [
    'admin_desa' => ['dashboard','admin-resident-export'],
    'owner' => ['dashboard','admin-users','admin-resident-export'],
];
if (!isset($allowed[$role]) || !in_array($page, $allowed[$role], true)) {
    fwrite(STDERR, "Unsupported dashboard smoke target.\n");
    exit(2);
}

$pdo = db();
$tag = bin2hex(random_bytes(6));
$username = 'ci-' . $role . '-' . $tag;
$userId = 0;
$exitCode = 0;
try {
    $stmt = $pdo->prepare('INSERT INTO app_users (username,full_name,password_hash,role,is_active,must_change_password,session_version) VALUES (?,?,?,?,1,0,1)');
    $stmt->execute([$username, 'CI ' . ($role === 'owner' ? 'Owner' : 'Admin Desa'), password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role]);
    $userId = (int)$pdo->lastInsertId();

    $_SESSION['user'] = [
        'id'=>$userId,
        'username'=>$username,
        'full_name'=>'CI ' . ($role === 'owner' ? 'Owner' : 'Admin Desa'),
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
