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
    'assets/app.js', 'assets/village-hero.svg', 'Dockerfile', 'bin/migrate_private_identifiers.php',
    'docker-compose.yml', 'docs/OPERASIONAL.md', '.env.example'
] as $relative) {
    check_true(is_file($root . '/' . $relative), "Missing required file: {$relative}");
}

putenv('APP_KEY=' . str_repeat('a', 64));
require_once $root . '/app/bootstrap.php';
$rawNik = '3201000000000002';
$encryptedNik = encrypt_private_identifier($rawNik);
check_true($encryptedNik !== $rawNik, 'NIK harus disimpan dalam bentuk terenkripsi.');
check_true(decrypt_private_identifier($encryptedNik) === $rawNik, 'NIK terenkripsi harus bisa dibuka hanya dengan APP_KEY yang sama.');
check_true(mask_private_identifier($rawNik) === '************0002', 'NIK yang disimpan untuk tampilan hanya boleh berisi empat digit terakhir.');
check_true(private_identifier_hash($rawNik) === private_identifier_hash($rawNik), 'HMAC NIK harus deterministik untuk pemeriksaan duplikasi.');
putenv('APP_KEY=' . str_repeat('b', 64));
check_true(decrypt_private_identifier($encryptedNik) === null, 'Ciphertext tidak boleh bisa dibuka dengan APP_KEY yang berbeda.');
putenv('APP_KEY=' . str_repeat('a', 64));

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
    'UNIQUE KEY uq_resident_nik_hash',
    'UNIQUE KEY uq_family_number_hash',
    'family_number_cipher TEXT NOT NULL',
    'nik_cipher TEXT NOT NULL'
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
    'function module_import_residents',
    'function module_normalize_import_header',
    'module_publish_site_revision',
    'module_restore_site_version',
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

$privacyMigration = load_text('bin/migrate_private_identifiers.php');
check_true(str_contains($privacyMigration, 'DROP INDEX') && str_contains($privacyMigration, 'decrypt_private_identifier'), 'A safe and rerunnable migration must exist for earlier development databases.');
check_true(str_contains($modules, 'family_number_hash=?') && str_contains($modules, 'nik_hash=?'), 'Duplicate detection must compare keyed hashes, not cleartext identifiers.');
check_true(str_contains($modules, 'family_number_cipher') && str_contains($modules, 'nik_cipher'), 'New household/resident records must encrypt raw identifiers at write time.');
check_true(!str_contains($schema, 'UNIQUE KEY uq_resident_nik (nik)') && !str_contains($schema, 'UNIQUE KEY uq_family_number (family_number)'), 'Masked display columns must not be used as uniqueness indexes.');

$bootstrap = load_text('app/bootstrap.php');
foreach ([
    'function require_login',
    'PDO::ATTR_EMULATE_PREPARES => false',
    "'httponly' => true",
    "'samesite' => 'Lax'",
    'hash_equals',
    "hash_hmac('sha256'",
    'function private_identifier_hash',
    'function encrypt_private_identifier',
    'function decrypt_private_identifier',
    'function mask_private_identifier'
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
