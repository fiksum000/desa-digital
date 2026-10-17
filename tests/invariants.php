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
    'index.php', 'schema.sql', 'app/bootstrap.php', 'app/modules.php', 'app/module_views.php', 'assets/style.css', 'bin/migrate_service_letters.php', 'bin/migrate_owner_role.php', 'bin/create_owner.php',
    'tests/dashboard_smoke.php', 'assets/app.js', 'assets/village-hero.svg', 'assets/village-hero.webp', 'assets/template-import-warga.csv', 'Dockerfile', 'bin/migrate_private_identifiers.php', 'bin/backup.sh', 'bin/restore.sh',
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
check_true(role_label('owner') === 'Owner', 'The Owner role must have a dedicated display label.');
check_true(can_manage_content(['role'=>'owner']), 'Owner must have top-level content management access.');
check_true(can_process_requests(['role'=>'owner']), 'Owner must have top-level request processing access.');
check_true(str_contains(load_text('app/modules.php'), "case 'module_create_admin_account':") && str_contains(load_text('app/modules.php'), "module_require_role(['owner'])"), 'Only Owner may create Admin Desa accounts.');
check_true(str_contains(load_text('app/module_views.php'), 'Buat akun Admin Desa') && str_contains(load_text('app/module_views.php'), "'rt-data' => ['rt','admin_desa','owner','petugas']"), 'Owner dashboard shortcuts must lead to authorized admin modules.');
check_true(mask_private_identifier($rawNik) === '************0002', 'NIK yang disimpan untuk tampilan hanya boleh berisi empat digit terakhir.');
check_true(private_identifier_hash($rawNik) === private_identifier_hash($rawNik), 'HMAC NIK harus deterministik untuk pemeriksaan duplikasi.');
$filePolicy = ['applicant_id' => 42];
check_true(module_can_access_service_file(['id'=>42,'role'=>'warga'], $filePolicy), 'A resident must be able to access their own service request attachments.');
check_true(!module_can_access_service_file(['id'=>43,'role'=>'warga'], $filePolicy), 'A resident must not access another resident service request attachments.');
check_true(module_can_access_service_file(['id'=>8,'role'=>'petugas'], $filePolicy), 'Authorized service staff must be able to process request attachments.');
check_true(!module_can_access_service_file(['id'=>9,'role'=>'rt'], $filePolicy), 'RT accounts must not access the private service attachment endpoint.');
$letterAccessFixture = ['applicant_id' => 42];
check_true(module_can_access_service_letter(['id'=>42,'role'=>'warga'], $letterAccessFixture), 'Owner access to an issued service letter should be allowed.');
check_true(!module_can_access_service_letter(['id'=>43,'role'=>'warga'], $letterAccessFixture), 'Access to another residents service letter should be denied.');
check_true(!module_can_access_service_letter(['id'=>9,'role'=>'rt'], $letterAccessFixture), 'RT users must not access the issued letter PDF endpoint.');

$pdfSample = module_build_service_letter_pdf([
    'service_name'=>'Surat Uji',
    'letter_number'=>'CI-LETTER-001',
    'approved_at'=>'2026-10-17 09:00:00',
    'letter_issued_at'=>'2026-10-17 09:05:00',
    'applicant_name'=>'Warga Contoh',
    'subject'=>'Keperluan uji',
    'tracking_code'=>'CI-SR-TEST-001',
    'request_data'=>json_encode(['details'=>'Pernyataan uji dengan tanda kurung (contoh).'],JSON_THROW_ON_ERROR),
], [
    'village_name'=>'Desa Uji',
    'office_address'=>'Jalan Desa 1',
    'village_regency'=>'Kabupaten Uji',
    'village_province'=>'Jawa Barat',
    'village_head_name'=>'Kepala Desa Uji',
]);
check_true(str_starts_with($pdfSample, '%PDF-1.4'), 'Service letter output must start with a PDF header.');
check_true(str_contains($pdfSample, 'CI-LETTER-001'), 'Service letter PDF must include the entered letter number.');
check_true(str_contains($pdfSample, '%%EOF'), 'Service letter PDF must contain an EOF marker.');

check_true(module_service_status_transition_allowed('submitted','in_review'), 'A submitted request may enter review.');
check_true(!module_service_status_transition_allowed('submitted','approved'), 'A submitted request must not skip the review stage.');
check_true(module_service_status_transition_allowed('in_review','approved'), 'A reviewed request may be approved by an authorized final approver.');
check_true(!module_service_status_transition_allowed('revision_requested','approved'), 'A request after revision must return to review before approval.');
check_true(module_service_status_transition_allowed('approved','ready'), 'An approved request may be marked ready for collection.');
check_true(!module_service_status_transition_allowed('approved','completed'), 'An approved request must not skip the ready-for-collection stage.');
check_true(module_service_status_transition_allowed('ready','completed'), 'A ready request may be completed.');
check_true(!module_service_status_transition_allowed('completed','in_review'), 'A completed request must remain closed.');
check_true(module_resident_review_decision_allowed('pending_review','approve'), 'A pending resident may be approved.');
check_true(module_resident_review_decision_allowed('pending_review','archive'), 'A pending resident may be archived.');
check_true(!module_resident_review_decision_allowed('active','archive'), 'An already active resident must not be reviewed again as pending.');
check_true(!module_resident_review_decision_allowed('archived','approve'), 'An archived resident must not be approved from the pending queue.');
check_true(!module_resident_review_decision_allowed('pending_review','completed'), 'Resident review must reject unrelated decisions.');
check_true(module_document_status_transition_allowed('pending','approved'), 'A pending resident document may be approved.');
check_true(module_document_status_transition_allowed('pending','revision_requested'), 'A pending resident document may be returned for revision.');
check_true(module_document_status_transition_allowed('revision_requested','approved'), 'A corrected resident document may be approved after re-review.');
check_true(module_document_status_transition_allowed('approved','archived'), 'An approved resident document may be archived with a recorded reason.');
check_true(!module_document_status_transition_allowed('approved','rejected'), 'An approved resident document must not be retroactively rejected.');
check_true(!module_document_status_transition_allowed('rejected','approved'), 'A rejected resident document must not be approved without a new submission.');
check_true(!module_document_status_transition_allowed('archived','approved'), 'An archived resident document must remain closed.');
check_true(!module_document_status_transition_allowed('pending','completed'), 'A resident document must not accept unrelated statuses.');

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
    'letter_number VARCHAR(100) NULL',
    'letter_snapshot JSON NULL',
    'UNIQUE KEY uq_service_letter_number (letter_number)',
    'CONSTRAINT fk_request_letter_issuer',
    'family_number_cipher TEXT NOT NULL',
    'nik_cipher TEXT NOT NULL'
] as $needle) {
    check_true(str_contains($schema, $needle), "Database schema missing invariant: {$needle}");
}

$style = load_text('assets/style.css');
foreach ([
    'Mobile hero composition aligned to DESA MAKASARI reference',
    '.hero-visual{position:absolute;inset:0',
    '.hero-copy{position:relative;z-index:2',
    '.benefits,.welcome-card,.slider-dots{display:none}',
    '.hero-actions .button-primary{background:#b9f45c;color:#14291d}'
] as $needle) {
    check_true(str_contains($style, $needle), "Reference-responsive styling missing invariant: {$needle}");
}

$index = load_text('index.php');
foreach ([
    'Layanan desa,', 'Lacak Pengajuan', 'Berita & Pengumuman', "site_image_val('hero_image','assets/village-hero.webp')", 'user_notifications WHERE user_id=? ORDER BY',
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
    'function module_can_access_service_letter',
    'function module_build_service_letter_pdf',
    'function module_serve_service_letter_pdf',
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


$assignedPrivateFile = ['applicant_id'=>7, 'assigned_to'=>42];
check_true(module_can_access_service_file(['id'=>7, 'role'=>'warga'], $assignedPrivateFile), 'A resident must be able to access their own service attachment.');
check_true(!module_can_access_service_file(['id'=>8, 'role'=>'warga'], $assignedPrivateFile), 'A resident must not access another resident service attachment.');
check_true(module_can_access_service_file(['id'=>42, 'role'=>'petugas'], $assignedPrivateFile), 'The assigned officer must be able to access the service attachment.');
check_true(!module_can_access_service_file(['id'=>43, 'role'=>'petugas'], $assignedPrivateFile), 'An officer must not access an attachment assigned to another officer.');
check_true(module_can_access_service_file(['id'=>43, 'role'=>'petugas'], ['applicant_id'=>7, 'assigned_to'=>null]), 'Unassigned service attachments must remain available in the staff queue.');
check_true(module_can_access_service_file(['id'=>99, 'role'=>'admin_desa'], $assignedPrivateFile), 'The village administrator must retain service attachment access.');

$views = load_text('app/module_views.php');
foreach ([
    'admin-users', 'admin-wilayah', 'admin-site', 'admin-services',
    'admin-directory', 'admin-budget', 'rt-data', 'rw-rekap',
    'private-document', 'module-export', 'notifikasi'
] as $needle) {
    check_true(str_contains($views, $needle), "Module views missing expected route: {$needle}");
}

$letterMigration = load_text('bin/migrate_service_letters.php');
check_true(str_contains($letterMigration, 'letter_number') && str_contains($letterMigration, 'letter_snapshot') && str_contains($letterMigration, 'letter_issued_by') && str_contains($letterMigration, 'letter_issued_at'), 'Service letter migration must cover number, issuer, and issue time.');
check_true(str_contains($letterMigration, 'uq_service_letter_number') && str_contains($letterMigration, 'fk_request_letter_issuer'), 'Service letter migration must create a unique number index and issuer foreign key.');
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


$exportSource = load_text('app/modules.php');
check_true(str_contains($exportSource, "if(\$role==='rt') { \$whereParts[]='rt.id=?';") && str_contains($exportSource, "elseif(\$role==='rw') { \$whereParts[]='rt.rw_id=?';"), 'Resident/family exports must constrain rows to the logged-in RT/RW scope.');
check_true(str_contains($exportSource, "module_audit_actor(\$user,'export','report_'.\$kind"), 'Sensitive report exports must create an audit record.');
check_true(str_contains($exportSource, "header('Cache-Control: private, no-store, max-age=0')"), 'Private downloads and report exports must disable shared caching.');
check_true(module_csv_safe('=1+1') === "'=1+1", 'CSV export must neutralize formula cells starting with equals.');
check_true(module_csv_safe("  +SUM(A1:A2)") === "'  +SUM(A1:A2)", 'CSV export must neutralize formula cells after leading spaces.');
check_true(module_csv_safe('@SUM(A1:A2)') === "'@SUM(A1:A2)", 'CSV export must neutralize formula cells starting with at-sign.');
check_true(module_csv_safe('-1+2') === "'-1+2", 'CSV export must neutralize formula cells starting with hyphen.');
check_true(module_csv_safe('Warga biasa') === 'Warga biasa', 'CSV export must preserve ordinary text.');
check_true(!module_can_access_service_file(['id'=>43,'role'=>'warga'], ['applicant_id'=>42,'assigned_to'=>0]), 'A different resident must not access an unassigned request attachment.');
check_true(!module_can_access_service_file(['id'=>9,'role'=>'rt'], ['applicant_id'=>42,'assigned_to'=>9]), 'RT role must not access service attachments even if its numeric ID matches assignment.');
check_true(!module_can_access_service_file(['id'=>9,'role'=>'petugas'], ['applicant_id'=>42,'assigned_to'=>10]), 'A staff member must not access another staff member\'s assigned request attachment.');
check_true(module_can_access_service_file(['id'=>9,'role'=>'petugas'], ['applicant_id'=>42,'assigned_to'=>0]), 'Staff must be able to access unassigned requests in the work queue.');
check_true(!module_can_access_service_letter(['id'=>43,'role'=>'warga'], ['applicant_id'=>42,'status'=>'approved']), 'A different resident must not access another resident\'s issued letter.');
check_true(module_can_access_service_letter(['id'=>9,'role'=>'petugas'], ['applicant_id'=>42,'status'=>'approved']), 'Authorized service staff must be able to access issued service letters.');

$moduleViews = load_text('app/module_views.php');
$modules = load_text('app/modules.php');
check_true(str_contains($moduleViews, 'Unggah dokumen ke arsip privat'), 'The RT document page must expose the private document upload form.');
check_true(str_contains($moduleViews, 'name="document_type"') && str_contains($moduleViews, 'value="ktp">KTP') && str_contains($moduleViews, 'value="kk">Kartu Keluarga'), 'The upload form must explicitly offer KTP and KK document types.');
check_true(str_contains($moduleViews, 'name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required'), 'The upload form must restrict advertised file types and require a file.');
check_true(str_contains($moduleViews, "if(\$role==='rt') {\$resWhere=' WHERE r.rt_id=?'"), 'The RT upload selector must list residents only from the signed-in RT scope.');
check_true(str_contains($modules, "case 'module_upload_document':") && str_contains($modules, "module_resident_scope(\$user,(int)\$residentId)"), 'The upload handler must enforce resident territory on the server.');
check_true(str_contains($modules, "module_upload_private_file(\$_FILES['document'] ?? [])"), 'Resident documents must use private upload validation/storage.');
check_true(str_contains($modules, "INSERT INTO resident_documents") && str_contains($modules, "'pending'"), 'Uploaded KTP/KK documents must enter the review queue.');
check_true(str_contains($modules, "module_audit_actor(\$user,'upload','resident_documents'"), 'Resident document uploads must be audit logged.');

$bundleSource = load_text('app/modules.php');
$bundleView = load_text('app/module_views.php');
$indexSource = load_text('index.php');
check_true(str_contains($bundleSource, "if ((string)(\$_GET['page'] ?? '') !== 'admin-resident-export') return;"), 'Admin resident bundle endpoint must be explicitly routed.');
check_true(str_contains($bundleSource, "if (!array_key_exists('bundle', \$_GET) && !array_key_exists('reason', \$_GET)) return;"), 'The initial export-page request must render the filter form instead of starting a download.');
check_true(str_contains($bundleSource, "!in_array((string)(\$user['role'] ?? ''), ['admin_desa','owner'], true)"), 'Full resident data bundle must be restricted to Admin Desa and Owner.');
check_true(str_contains($bundleSource, "decrypt_private_identifier((string)\$row['nik_cipher'])") && str_contains($bundleSource, "decrypt_private_identifier((string)\$row['family_number_cipher'])"), 'Full NIK and KK must be decrypted through the application privacy helper.');
check_true(str_contains($bundleSource, "status='approved' AND document_type IN ('ktp','kk')"), 'ZIP bundles must include only approved KTP/KK documents.');
check_true(str_contains($bundleSource, 'AND rt_id IN ({$rtPlaceholders})'), 'ZIP documents must also belong to an RT inside the selected resident scope.');
check_true(str_contains($bundleSource, "document_type='ktp'") && str_contains($bundleSource, "document_type='kk'"), 'KTP exports must match selected residents and KK exports must match selected family records.');
check_true(str_contains($bundleSource, "module_audit_actor(\$user, 'export', 'resident_private_bundle'"), 'Private resident bundle exports must be audit logged.');
check_true(str_contains($bundleSource, "header('Cache-Control: private, no-store, max-age=0')"), 'Private resident bundles must disable caching.');
check_true(str_contains($bundleSource, "LIMIT 10001") && str_contains($bundleSource, "count(\$rows) > 10000"), 'Resident exports must enforce the row limit.');
check_true(str_contains($bundleSource, 'scopeChecks') && str_contains($bundleSource, 'ID wilayah tidak cocok dengan filter'), 'Resident export scope IDs must be validated against the selected active region table.');
check_true(str_contains($bundleSource, 'ZipArchive::CREATE | ZipArchive::OVERWRITE'), 'ZIP export must create the temporary archive before overwriting it.');
check_true(str_contains($bundleView, "'admin-resident-export' => ['admin_desa','owner']") && str_contains($bundleView, 'Unduh Data Penduduk'), 'Admin export UI must be restricted to Admin Desa and Owner.');
check_true(str_contains($indexSource, "'admin-resident-export','laporan'") || str_contains($indexSource, "'admin-budget','admin-resident-export'"), 'Admin resident export module must be routable from index.php.');
check_true(str_contains(load_text('schema.sql'), "ENUM('owner','warga','rt','rw','petugas','admin_desa','kepala_desa')"), 'Schema must recognize the top-level Owner role.');
check_true(str_contains($indexSource, 'module_serve_admin_resident_bundle();'), 'Admin resident bundle endpoint must be invoked by the request dispatcher.');


// Public site copy/images must be editable through the Admin Desa/Owner CMS.
$publicViews=load_text('app/module_views.php');$publicHandler=load_text('app/modules.php');$publicIndex=load_text('index.php');$publicCss=load_text('assets/style.css');$publicJs=load_text('assets/app.js');
check_true(str_contains($publicViews,"'hero_cta_apply'=>'") && str_contains($publicViews,"'profile_intro'=>'") && str_contains($publicViews,"'map_title'=>'"), 'CMS must expose editable copy for public pages.');
check_true(str_contains($publicHandler,"'register_image'") && str_contains($publicHandler,"'news_fallback_image'"), 'CMS handler must allowlist public image values.');
check_true(str_contains($publicHandler,'module_site_image_value_allowed') && str_contains($publicHandler,'module_site_image_keys'), 'Site image publishing/restoration must validate image keys and paths.');
check_true(str_contains(load_text('app/bootstrap.php'),"Content-Security-Policy: default-src 'self'") && str_contains(load_text('app/bootstrap.php'),"frame-ancestors 'none'"), 'Public responses must include a restrictive content security policy.');
check_true(str_contains($publicIndex,'function public_image_src') && str_contains($publicIndex,'public_image_src($article'), 'Public article/news image URLs must be restricted to allowed local files.');
check_true(str_contains($publicViews,"'admin-site' => ['admin_desa','owner']"), 'Public site CMS must be restricted to Admin Desa and Owner.');
check_true(str_contains($publicIndex,"site_image_val('logo_image'") && str_contains($publicIndex,"site_image_val('login_image'") && str_contains($publicIndex,"site_image_val('register_image'"), 'Brand and auth images must use CMS-managed values.');
check_true(str_contains($publicIndex,"site_val('home_news_title'") && str_contains($publicIndex,"site_val('nav_home'") && str_contains($publicIndex,"site_val('footer_summary'"), 'Navigation, home sections, and footer copy must use managed values.');
check_true(str_contains($publicCss,'object-fit:cover') && str_contains($publicCss,'.news-image img') && str_contains($publicCss,'.auth-intro>img'), 'Public images must auto-fit responsive frames.');
check_true(str_contains($publicIndex,"SELECT content_key,content_value FROM site_content WHERE status='published'") && str_contains($publicIndex,'$siteContent[(string)$siteRow[\'content_key\']]'), 'Public renderer must load only published CMS values, never drafts.');
check_true(str_contains($publicIndex,'function site_image_val') && str_contains($publicIndex,'is_uploaded_file($tmp)') && str_contains($publicIndex,'getimagesize($tmp)'), 'CMS image rendering and uploads must validate paths, upload origin, file signature, and dimensions.');
check_true(str_contains($publicJs,'[data-preview-image-key]') && str_contains($publicJs,'data-preview-site-image'), 'CMS must preview replacement images.');
check_true(str_contains($publicViews,'reset_site_image[') && str_contains($publicHandler,'$resetImages=$_POST[\'reset_site_image\']'), 'Admin Desa/Owner must be able to restore default public images without uploading a placeholder.');
check_true(str_contains($publicViews,'data-site-setting-filter') && str_contains($publicJs,'siteSettingFilter'), 'Large public-content CMS must have a working settings search.');
check_true(str_contains($publicIndex,"site_val('news_fallback_alt'") && str_contains($publicIndex,"site_val('nav_open_menu_aria'") && str_contains($publicJs,"data-close-label"), 'Public image alt text and menu accessibility labels must be editable.');
check_true(str_contains($publicViews,'data-setting-key') && str_contains($publicJs,'siteSettingFilter.value'), 'CMS search must match editable setting keys/labels.');
if ($failures) {
    fwrite(STDERR, "FAILED: " . count($failures) . " of {$checks} checks\n");
    foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "PASS: {$checks} static project invariants checked. (Not a substitute for integration/security testing.)\n");
