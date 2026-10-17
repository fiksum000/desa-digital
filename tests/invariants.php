<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
function check_true(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $message;
}
function load_text(string $relative): string {
    $path = dirname(__DIR__) . '/' . $relative;
    return is_file($path) ? (string)file_get_contents($path) : '';
}
foreach ([
    'index.php', 'schema.sql', 'app/bootstrap.php', 'app/modules.php', 'app/module_views.php', 'assets/style.css',
    'assets/app.js', 'assets/village-hero.svg', 'Dockerfile',
    'docker-compose.yml', 'docs/OPERASIONAL.md', '.env.example'
] as $relative) {
    check_true(is_file($root . '/' . $relative), "Missing required file: {$relative}");
}

$schema = load_text('schema.sql');
foreach ([
    'CREATE TABLE IF NOT EXISTS app_users',
    'CREATE TABLE IF NOT EXISTS rt_units',
    'CREATE TABLE IF NOT EXISTS rw_units',
    'CREATE TABLE IF NOT EXISTS family_records',
    'CREATE TABLE IF NOT EXISTS residents',
    'CREATE TABLE IF NOT EXISTS resident_documents',
    'CREATE TABLE IF NOT EXISTS resident_change_requests',
    'CREATE TABLE IF NOT EXISTS service_requests',
    'CREATE TABLE IF NOT EXISTS complaints',
    'CREATE TABLE IF NOT EXISTS news_posts',
    'CREATE TABLE IF NOT EXISTS audit_logs',
    'CREATE TABLE IF NOT EXISTS site_content_revisions',
    'CREATE TABLE IF NOT EXISTS public_directory_items',
    'CREATE TABLE IF NOT EXISTS budget_reports',
    'UNIQUE KEY uq_resident_nik'
] as $needle) {
    check_true(str_contains($schema, $needle), "Database schema missing invariant: {$needle}");
}

$index = load_text('index.php');
foreach ([
    'Layanan desa,', 'Lacak Pengajuan', 'Berita & Pengumuman',
    'verify_csrf()', 'revision_requested',
    'SELECT tracking_code', "app_url('layanan',["
] as $needle) {
    check_true(str_contains($index, $needle), "App source missing expected functionality: {$needle}");
}

$modules = load_text('app/modules.php');
foreach ([
    'function module_handle_post',
    'function module_serve_private_document',
    'function module_serve_export',
    'function module_upload_private_file',
    'session_version=session_version+1',
    'module_csv_safe'
] as $needle) {
    check_true(str_contains($modules, $needle), "Module source missing expected safety/functionality: {$needle}");
}

$views = load_text('app/module_views.php');
foreach ([
    'admin-users', 'admin-wilayah', 'admin-site', 'admin-services',
    'admin-directory', 'admin-budget', 'rt-data', 'rw-rekap',
    'private-document', 'module-export'
] as $needle) {
    check_true(str_contains($views, $needle), "Module views missing expected route: {$needle}");
}

$bootstrap = load_text('app/bootstrap.php');
foreach ([
    'function require_login',
    'PDO::ATTR_EMULATE_PREPARES => false',
    "'httponly' => true",
    "'samesite' => 'Lax'",
    'hash_equals',
    "hash_hmac('sha256'"
] as $needle) {
    check_true(str_contains($bootstrap, $needle), "Security bootstrap missing invariant: {$needle}");
}

$ignore = load_text('.gitignore');
check_true(str_contains($ignore, '.env'), 'The real environment file must be ignored by Git.');
check_true(!is_file($root . '/.env'), 'A real .env file must never be committed to the repository.');

$compose = load_text('docker-compose.yml');
check_true(str_contains($compose, 'DB_ROOT_PASSWORD'), 'Compose must require a database root password.');
check_true(str_contains($compose, 'database_data:/var/lib/mysql'), 'Database must use a persistent volume.');
check_true(!preg_match('/ports:\s*\n\s*-\s*["\']?3306:/m', $compose), 'Database port must not be exposed on the host.');

if ($failures) {
    fwrite(STDERR, "FAILED: " . count($failures) . " of {$checks} checks\n");
    foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "PASS: {$checks} static project invariants checked. (Not a substitute for integration/security testing.)\n");
