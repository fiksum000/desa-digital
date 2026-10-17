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
    'assets/app.js', 'assets/village-hero.svg', 'assets/village-hero.webp', 'assets/template-import-warga.csv', 'Dockerfile', 'bin/migrate_private_identifiers.php', 'bin/backup.sh', 'bin/restore.sh',
    'docker-compose.yml', 'docs/OPERASIONAL.md', '.env.example'
] as $relative) {
    check_true(is_file($root . '/' . $relative), "Missing required file: {$relative}");
}

putenv('APP_KEY=' . str_repeat('a', 64));
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/modules.php';
$rawNik = '3201000000000002';
$encryptedNik = encrypt_private_identifier($rawNik);
check_true($encryptedNik !== $rawNik, 'NIK harus disimpan dalam bentuk terenkripsi.');
check_true(decrypt_private_identifier($encryptedNik) === $rawNik, 'NIK terenkripsi harus bisa dibuka hanya dengan APP_KEY yang sama.');
check_true(mask_private_identifier($rawNik) === '************0002', 'NIK yang disimpan untuk tampilan hanya boleh berisi empat digit terakhir.');
check_true(private_identifier_hash($rawNik) === private_identifier_hash($rawNik), 'HMAC NIK harus deterministik untuk pemeriksaan duplikasi.');
$filePolicy = ['applicant_id' => 42];
check_true(module_can_access_service_file(['id'=>42,'role'=>'warga'], $filePolicy), 'A resident must be able to access their own service request attachments.');
check_true(!module_can_access_service_file(['id'=>43,'role'=>'warga'], $filePolicy), 'A resident must not access another resident service request attachments.');
check_true(module_can_access_service_file(['id'=>8,'role'=>'petugas'], $filePolicy), 'Authorized service staff must be able to process request attachments.');
check_true(!module_can_access_service_file(['id'=>9,'role'=>'rt'], $filePolicy), 'RT accounts must not access the private service attachment endpoint.');
check_true(module_service_status_transition_allowed('submitted','in_review'), 'A submitted request may enter review.');
check_true(!module_service_status_transition_allowed('submitted','approved'), 'A submitted request must not skip the review stage.');
check_true(module_service_status_transition_allowed('in_review','approved'), 'A reviewed request may be approved by an authorized final approver.');
check_true(!module_service_status_transition_allowed('revision_requested','approved'), 'A request after revision must return to review before approval.');
check_true(module_service_status_transition_allowed('approved','ready'), 'An approved request may be marked ready for collection.');
check_true(!module_service_status_transition_allowed('approved','completed'), 'An approved request must not skip the ready-for-collection stage.');
check_true(module_service_status_transition_allowed('ready','completed'), 'A ready request may be completed.');
check_true(!module_service_status_transition_allowed('completed','in_review'), 'A completed request must remain closed.');

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
    "source_snapshot JSON NULL COMMENT 'Keyed digests of source state; no cleartext PII.'",
    'CREATE TABLE IF NOT EXISTS service_requests',
    'CREATE TABLE IF NOT EXISTS complaints',
    'CREATE TABLE IF NOT EXISTS news_posts',
    'CREATE TABLE IF NOT EXISTS audit_logs',
    'CREATE TABLE IF NOT EXISTS site_content_revisions',
    'CREATE TABLE IF NOT EXISTS public_directory_items',
    'CREATE TABLE IF NOT EXISTS budget_reports',
    'CREATE TABLE IF NOT EXISTS auth_attempts',
    'CREATE TABLE IF NOT EXISTS user_notifications',
    'UNIQUE KEY uq_resident_nik_hash',
    'UNIQUE KEY uq_family_number_hash',
    'family_number_cipher TEXT NOT NULL',
    'nik_cipher TEXT NOT NULL'
] as $needle) {
    check_true(str_contains($schema, $needle), "Database schema missing invariant: {$needle}");
}

$style = load_text('assets/style.css');
foreach ([
    'Mobile hero composition aligned to DESA DIGITAL reference',
    '.hero-visual{position:absolute;inset:0',
    '.hero-copy{position:relative;z-index:2',
    '.benefits,.welcome-card,.slider-dots{display:none}',
    '.hero-actions .button-primary{background:#b9f45c;color:#14291d}'
] as $needle) {
    check_true(str_contains($style, $needle), "Reference-responsive styling missing invariant: {$needle}");
}

$index = load_text('index.php');
foreach ([
    'Layanan desa,', 'Lacak Pengajuan', 'Berita & Pengumuman', "site_val('hero_image','assets/village-hero.webp')", 'user_notifications WHERE user_id=? ORDER BY',
    'verify_csrf()', 'revision_requested',
    'SELECT tracking_code', "app_url('layanan',["
] as $needle) {
    check_true(str_contains($index, $needle), "App source missing expected functionality: {$needle}");
}

$modules = load_text('app/modules.php');
foreach ([
    'function module_handle_post',
    'function module_change_snapshot',
    'function module_change_snapshot_matches',
    "case 'module_mark_notification_read'",
    'INSERT INTO user_notifications',
    "status='conflict'",
    'function module_serve_private_document',
    'function module_serve_private_service_file',
    'function module_can_access_service_file',
    'function module_service_status_transition_allowed',
    'function module_serve_export',
    'function module_upload_private_file',
    'module_import_residents',
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
    'private-document', 'module-export', 'notifikasi'
] as $needle) {
    check_true(str_contains($views, $needle), "Module views missing expected route: {$needle}");
}

$privacyMigration = load_text('bin/migrate_private_identifiers.php');
check_true(str_contains($privacyMigration, 'DROP INDEX') && str_contains($privacyMigration, 'decrypt_private_identifier'), 'A safe and rerunnable migration must exist for earlier development databases.');
check_true(str_contains($modules, 'family_number_hash=?') && str_contains($modules, 'nik_hash=?'), 'Duplicate detection must compare keyed hashes, not cleartext identifiers.');
check_true(str_contains($modules, 'family_number_cipher') && str_contains($modules, 'nik_cipher'), 'New household/resident records must encrypt raw identifiers at write time.');
check_true(!str_contains($schema, 'UNIQUE KEY uq_resident_nik (nik)') && !str_contains($schema, 'UNIQUE KEY uq_family_number (family_number)'), 'Masked display columns must not be used as uniqueness indexes.');

$loginSource = load_text('index.php');
check_true(str_contains($loginSource, 'auth_rate_limit_assert_allowed($username)'), 'Login must enforce persistent rate limits before checking credentials.');
check_true(str_contains($loginSource, 'name="attachments[]"') && str_contains($loginSource, 'normalize_private_uploads') && str_contains($loginSource, 'save_service_request_files'), 'Service request forms must upload attachments into private storage.');
check_true(str_contains($loginSource, 'auth_rate_limit_record_failure($username)'), 'Login must record failed attempts in the database.');
check_true(str_contains($loginSource, 'auth_rate_limit_clear_account($username)'), 'Successful login must clear the account failure bucket.');
check_true(str_contains($schema, "'conflict'"), 'Change-request schema must include a conflict state.');
check_true(str_contains($index, "'conflict'=>'Konflik data'"), 'Conflicted requests must have an explicit user-facing label.');
$migration = load_text('bin/migrate_private_identifiers.php');
check_true(str_contains($migration, 'CREATE TABLE IF NOT EXISTS auth_attempts'), 'The upgrade migration must create the persistent rate-limit table.');
check_true(str_contains($migration, 'CREATE TABLE IF NOT EXISTS user_notifications'), 'The upgrade migration must create the private notification inbox.');

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
    'function mask_private_identifier',
    'function auth_rate_limit_assert_allowed',
    'function auth_rate_limit_record_failure',
    'function auth_rate_limit_clear_account'
] as $needle) {
    check_true(str_contains($bootstrap, $needle), "Security bootstrap missing invariant: {$needle}");
}

$ignore = load_text('.gitignore');
check_true(str_contains($ignore, '.env'), 'The real environment file must be ignored by Git.');
check_true(!is_file($root . '/.env'), 'A real .env file must never be committed to the repository.');

$backupScript = load_text('bin/backup.sh');
$restoreScript = load_text('bin/restore.sh');
check_true(str_contains($backupScript, 'mariadb-dump') && str_contains($backupScript, '--symmetric') && str_contains($backupScript, 'BACKUP_PASSPHRASE_FILE'), 'Backup must encrypt the database and uploads with a separately supplied passphrase file.');
check_true(str_contains($backupScript, 'storage/uploads assets/uploads'), 'Backup must include private uploads and public media.');
check_true(str_contains($backupScript, 'BACKUP_DIR') && str_contains($backupScript, 'tidak boleh berada di dalam repository'), 'Backup output must be outside the Git repository.');
check_true(str_contains($restoreScript, 'Ketik RESTORE') && str_contains($restoreScript, 'APP_KEY') && str_contains($restoreScript, 'docker compose stop web'), 'Restore must require confirmation and warn about APP_KEY while stopping writes.');
check_true(str_contains($restoreScript, 'assets/uploads') && str_contains($restoreScript, 'storage/uploads') && str_contains($restoreScript, 'jalur arsip tidak diizinkan'), 'Restore must restore upload volumes and validate archive paths.');
$htaccess = load_text('.htaccess');
check_true(str_contains($htaccess, 'RewriteRule ^(app|bin|docker|docs|tests|storage|'), 'Apache rewrite rules must block internal and private directories when served outside Docker.');
check_true(str_contains($htaccess, '[F,L,NC]'), 'Internal path blocking must return a forbidden response.');
check_true(str_contains($htaccess, '.env(\\..*)?'), 'Environment variants must not be exposed over HTTP.');
check_true(str_contains($htaccess, '.*~)$'), 'Temporary editor backups must not be exposed over HTTP.');
$vhost = load_text('docker/apache-vhost.conf');
check_true(str_contains($vhost, '<Directory /var/www/html/bin>'), 'Apache config must protect the CLI tools folder.');
check_true(str_contains($vhost, '<Directory /var/www/html/docker>'), 'Apache config must protect the server config folder.');
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
$vhost = load_text('docker/apache-vhost.conf');
check_true(str_contains($vhost, '<Directory /var/www/html/bin>'), 'Apache config must protect the CLI tools folder.');
check_true(str_contains($vhost, '<Directory /var/www/html/docker>'), 'Apache config must protect the server config folder.');
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
$vhost = load_text('docker/apache-vhost.conf');
check_true(str_contains($vhost, '<Directory /var/www/html/bin>'), 'Apache config must protect the CLI tools folder.');
check_true(str_contains($vhost, '<Directory /var/www/html/docker>'), 'Apache config must protect the server config folder.');
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
$vhost = load_text('docker/apache-vhost.conf');
check_true(str_contains($vhost, '<Directory /var/www/html/bin>'), 'Apache config must protect the CLI tools folder.');
check_true(str_contains($vhost, '<Directory /var/www/html/docker>'), 'Apache config must protect the server config folder.');
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
