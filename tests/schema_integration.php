<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'desa_digital';
$user = getenv('DB_USER') ?: 'ci_app';
$pass = getenv('DB_PASSWORD') ?: 'ci-app-pass';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$checks = 0;
function expect_true(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$required = [
    'dusun','rw_units','rt_units','app_users','family_records','residents',
    'resident_documents','resident_change_requests','service_types','service_requests',
    'service_request_files','complaints','news_posts','site_content','site_content_versions',
    'site_content_revisions','public_directory_items','budget_reports','audit_logs',
];
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
foreach ($required as $table) expect_true(in_array($table, $tables, true), "Missing table {$table}");

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role) VALUES (?,?,?,'admin_desa')")
        ->execute(['ci_admin','CI Admin',password_hash('ci-test-password',PASSWORD_DEFAULT)]);
    $adminId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role) VALUES (?,?,?,'warga')")
        ->execute(['ci_warga','CI Warga',password_hash('ci-test-password',PASSWORD_DEFAULT)]);
    $wargaId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO dusun (name,code) VALUES (?,?)')->execute(['Dusun CI','CI01']);
    $dusunId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rw_units (dusun_id,number_code) VALUES (?,?)')->execute([$dusunId,'01']);
    $rwId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rt_units (rw_id,number_code) VALUES (?,?)')->execute([$rwId,'001']);
    $rtId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role,rt_id,rw_id,created_by,must_change_password) VALUES (?,?,?,'rt',?,?,?,1)")
        ->execute(['ci_rt','CI Ketua RT',password_hash('ci-test-password',PASSWORD_DEFAULT),$rtId,$rwId,$adminId]);
    $rtUserId = (int)$pdo->lastInsertId();
    $stmt=$pdo->prepare('SELECT role,rt_id,rw_id,must_change_password,session_version FROM app_users WHERE id=?');
    $stmt->execute([$rtUserId]); $rtUser=$stmt->fetch();
    expect_true($rtUser && $rtUser['role']==='rt' && (int)$rtUser['rt_id']===$rtId && (int)$rtUser['must_change_password']===1, 'Role and RT assignment must persist');
    expect_true((int)$rtUser['session_version']===1, 'Initial session version must be present');

    $pdo->prepare("INSERT INTO family_records (family_number,rt_id,address,status,created_by) VALUES (?,?,?,'pending_review',?)")
        ->execute(['3201000000000001',$rtId,'Alamat CI',$rtUserId]);
    $familyId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,full_name,birth_date,gender,resident_status,created_by) VALUES (?,?,?,?,?,'L','pending_review',?)")
        ->execute([$familyId,$rtId,'3201000000000002','Penduduk CI','2000-01-01',$rtUserId]);
    $residentId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE family_records SET head_resident_id=? WHERE id=?')->execute([$residentId,$familyId]);
    $stmt=$pdo->prepare('SELECT r.id,f.head_resident_id FROM residents r JOIN family_records f ON f.id=r.family_id WHERE r.id=?');
    $stmt->execute([$residentId]); $familyHead=$stmt->fetch();
    expect_true($familyHead && (int)$familyHead['head_resident_id']===$residentId, 'Family head relationship must be storable');

    $duplicateNikBlocked = false;
    try {
        $pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,full_name,resident_status,created_by) VALUES (?,?,?,?, 'pending_review',?)")
            ->execute([$familyId,$rtId,'3201000000000002','Duplicate NIK',$rtUserId]);
    } catch (PDOException $e) {
        $duplicateNikBlocked = (string)$e->getCode()==='23000';
    }
    expect_true($duplicateNikBlocked, 'Duplicate NIK must be blocked by database unique constraint');

    $duplicateKkBlocked = false;
    try {
        $pdo->prepare("INSERT INTO family_records (family_number,rt_id,address,status,created_by) VALUES (?,?,?,'draft',?)")
            ->execute(['3201000000000001',$rtId,'Alamat duplikat',$rtUserId]);
    } catch (PDOException $e) {
        $duplicateKkBlocked = (string)$e->getCode()==='23000';
    }
    expect_true($duplicateKkBlocked, 'Duplicate KK must be blocked by database unique constraint');

    $serviceId = (int)$pdo->query('SELECT id FROM service_types ORDER BY id LIMIT 1')->fetchColumn();
    expect_true($serviceId > 0, 'Default public service types must be seeded');
    $pdo->prepare("INSERT INTO service_requests (tracking_code,service_type_id,applicant_id,subject,request_data,status) VALUES (?,?,?,?,'{"details":"CI test"}','submitted')")
        ->execute(['CI-SR-TEST-001',$serviceId,$wargaId,'CI pengajuan']);
    $pdo->prepare("INSERT INTO complaints (tracking_code,reporter_id,title,category,description,status) VALUES (?,?,?,'umum',?,'received')")
        ->execute(['CI-PG-TEST-001',$wargaId,'CI pengaduan','Contoh data otomatis untuk tes skema.']);
    $pdo->prepare("INSERT INTO site_content (content_key,content_value,value_type,status,updated_by) VALUES ('ci_test','ok','text','published',?)")
        ->execute([$adminId]);
    $pdo->prepare("INSERT INTO audit_logs (actor_id,action,entity_type,entity_id) VALUES (?,'ci_test','system','1')")
        ->execute([$adminId]);
    expect_true((int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE tracking_code='CI-SR-TEST-001'")->fetchColumn()===1, 'Service request insert must work');
    expect_true((int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE tracking_code='CI-PG-TEST-001'")->fetchColumn()===1, 'Complaint insert must work');
    expect_true((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='ci_test'")->fetchColumn()===1, 'Audit log insert must work');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
fwrite(STDOUT, "PASS: {$checks} MariaDB schema and transaction checks.\n");
