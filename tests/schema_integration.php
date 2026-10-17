<?php
declare(strict_types=1);

putenv('APP_KEY=' . str_repeat('c', 64));
require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = db(); // Use the same connection the application uses so row locks and snapshots are tested faithfully.

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
    'site_content_revisions','public_directory_items','budget_reports','audit_logs','auth_attempts','user_notifications',
];
require_once dirname(__DIR__) . '/app/modules.php';
expect_true(module_csv_safe('=SUM(A1:A2)') === "'=SUM(A1:A2)", 'CSV formulas beginning with equals must be neutralized.');
expect_true(module_csv_safe('+CMD') === "'+CMD", 'CSV formulas beginning with plus must be neutralized.');
expect_true(module_csv_safe('Ordinary text') === 'Ordinary text', 'Ordinary CSV text must remain readable.');
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
foreach ($required as $table) expect_true(in_array($table, $tables, true), "Missing table {$table}");

// The throttling buckets persist in MariaDB, so a fresh browser session cannot reset failed attempts.
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
$limitedAccount = 'ci-lock-' . bin2hex(random_bytes(5));
for ($i=0; $i<5; $i++) auth_rate_limit_record_failure($limitedAccount);
$accountBlocked = false;
try { auth_rate_limit_assert_allowed($limitedAccount); } catch (RuntimeException $e) { $accountBlocked = true; }
expect_true($accountBlocked, 'The fifth failed password attempt must lock that account for the window.');
auth_rate_limit_clear_account($limitedAccount);
$accountAllowedAfterClear = true;
try { auth_rate_limit_assert_allowed($limitedAccount); } catch (RuntimeException $e) { $accountAllowedAfterClear = false; }
expect_true($accountAllowedAfterClear, 'Clearing a successful account login must preserve a network bucket below its limit.');
$keys = auth_rate_limit_keys($limitedAccount);
db()->prepare('DELETE FROM auth_attempts WHERE bucket_hash IN (?,?)')->execute([$keys['account'],$keys['ip']]);

$networkUsers = [];
$networkAccountHashes = [];
for ($i=0; $i<20; $i++) {
    $name = 'ci-ip-' . $i . '-' . bin2hex(random_bytes(3));
    $networkUsers[] = $name;
    $networkAccountHashes[] = auth_rate_limit_keys($name)['account'];
    auth_rate_limit_record_failure($name);
}
$networkBlocked = false;
try { auth_rate_limit_assert_allowed('ci-ip-new-account'); } catch (RuntimeException $e) { $networkBlocked = true; }
expect_true($networkBlocked, 'Twenty failed attempts from one IP must temporarily throttle that network.');
$ipHash = auth_rate_limit_keys('ci-ip-cleanup')['ip'];
$cleanupHashes = array_merge($networkAccountHashes, [$ipHash]);
$placeholders = implode(',', array_fill(0, count($cleanupHashes), '?'));
db()->prepare('DELETE FROM auth_attempts WHERE bucket_hash IN (' . $placeholders . ')')->execute($cleanupHashes);


// Commit a minimal territory fixture first because module_is_allowed_rt intentionally
// checks the database through the application's separate connection.
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO dusun (name,code) VALUES (?,?)')->execute(['Dusun Scope','CS01']);
    $scopeDusun = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rw_units (dusun_id,number_code) VALUES (?,?)')->execute([$scopeDusun,'10']);
    $scopeRwA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rw_units (dusun_id,number_code) VALUES (?,?)')->execute([$scopeDusun,'20']);
    $scopeRwB = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rt_units (rw_id,number_code) VALUES (?,?)')->execute([$scopeRwA,'101']);
    $scopeRtA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rt_units (rw_id,number_code) VALUES (?,?)')->execute([$scopeRwB,'201']);
    $scopeRtB = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
expect_true(module_is_allowed_rt(['role'=>'rt','rt_id'=>$scopeRtA],$scopeRtA), 'RT must be able to access its own active RT.');
expect_true(!module_is_allowed_rt(['role'=>'rt','rt_id'=>$scopeRtA],$scopeRtB), 'RT must not access another RT by changing submitted IDs.');
expect_true(module_is_allowed_rt(['role'=>'rw','rw_id'=>$scopeRwA],$scopeRtA), 'RW must be able to access an RT inside its assigned RW.');
expect_true(!module_is_allowed_rt(['role'=>'rw','rw_id'=>$scopeRwA],$scopeRtB), 'RW must not access an RT outside its assigned RW.');
expect_true(module_is_allowed_rt(['role'=>'admin_desa'],$scopeRtB), 'Admin desa may manage an active RT in another RW.');
expect_true(!module_is_allowed_rt(['role'=>'warga'],$scopeRtA), 'Warga must not use the RT management scope helper.');
$pdo->prepare('UPDATE rt_units SET is_active=0 WHERE id=?')->execute([$scopeRtB]);
expect_true(!module_is_allowed_rt(['role'=>'admin_desa'],$scopeRtB), 'Inactive RTs must not be accepted by scoped operations.');

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
    $pdo->prepare("INSERT INTO user_notifications (user_id,title,message,notification_type,entity_type,entity_id) VALUES (?,?,?,'data_change','resident_change_request','CI-1')")
        ->execute([$rtUserId,'Notifikasi uji','Pemberitahuan privat untuk pengurus RT.']);
    $notificationId=(int)$pdo->lastInsertId();
    $notifyStmt=$pdo->prepare('SELECT id,read_at FROM user_notifications WHERE id=? AND user_id=?');
    $notifyStmt->execute([$notificationId,$rtUserId]);$ownedNotification=$notifyStmt->fetch();
    expect_true($ownedNotification && $ownedNotification['read_at']===null, 'Notifications should appear unread only in the recipient inbox.');
    $notifyStmt->execute([$notificationId,$wargaId]);
    expect_true(!$notifyStmt->fetch(), 'A different account must not read another user’s notification.');
    $pdo->prepare('UPDATE user_notifications SET read_at=NOW() WHERE id=? AND user_id=?')->execute([$notificationId,$rtUserId]);
    $notifyStmt->execute([$notificationId,$rtUserId]);$readNotification=$notifyStmt->fetch();
    expect_true($readNotification && $readNotification['read_at']!==null, 'A recipient may mark their own notification as read.');
    $stmt=$pdo->prepare('SELECT role,rt_id,rw_id,must_change_password,session_version FROM app_users WHERE id=?');
    $stmt->execute([$rtUserId]); $rtUser=$stmt->fetch();
    expect_true($rtUser && $rtUser['role']==='rt' && (int)$rtUser['rt_id']===$rtId && (int)$rtUser['must_change_password']===1, 'Role and RT assignment must persist');
    expect_true((int)$rtUser['session_version']===1, 'Initial session version must be present');

    $rawFamilyNumber = '3201000000000001';
    $rawNik = '3201000000000002';
    $familyMask = mask_private_identifier($rawFamilyNumber);
    $familyCipher = encrypt_private_identifier($rawFamilyNumber);
    $familyHash = private_identifier_hash($rawFamilyNumber);
    $nikMask = mask_private_identifier($rawNik);
    $nikCipher = encrypt_private_identifier($rawNik);
    $nikHash = private_identifier_hash($rawNik);
    $pdo->prepare("INSERT INTO family_records (family_number,family_number_cipher,family_number_hash,rt_id,address,status,created_by) VALUES (?,?,?, ?,?,'pending_review',?)")
        ->execute([$familyMask,$familyCipher,$familyHash,$rtId,'Alamat CI',$rtUserId]);
    $familyId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,birth_date,gender,resident_status,created_by) VALUES (?,?,?,?,?,?,?,'L','pending_review',?)")
        ->execute([$familyId,$rtId,$nikMask,$nikCipher,$nikHash,'Penduduk CI','2000-01-01',$rtUserId]);
    $residentId = (int)$pdo->lastInsertId();
    $storedIdentifiers = $pdo->prepare('SELECT f.family_number,f.family_number_cipher,f.family_number_hash,r.nik,r.nik_cipher,r.nik_hash FROM family_records f JOIN residents r ON r.family_id=f.id WHERE f.id=?');
    $storedIdentifiers->execute([$familyId]);
    $stored = $storedIdentifiers->fetch();
    expect_true($stored && $stored['family_number']===$familyMask && $stored['nik']===$nikMask, 'Database must store masked identifiers, not cleartext.');
    expect_true(decrypt_private_identifier($stored['family_number_cipher'])===$rawFamilyNumber, 'Family number ciphertext must decrypt with APP_KEY.');
    expect_true(decrypt_private_identifier($stored['nik_cipher'])===$rawNik, 'NIK ciphertext must decrypt with APP_KEY.');
    expect_true(hash_equals($stored['family_number_hash'],$familyHash) && hash_equals($stored['nik_hash'],$nikHash), 'HMAC indexes must match normalized identifiers.');
    $pdo->prepare('UPDATE family_records SET head_resident_id=? WHERE id=?')->execute([$residentId,$familyId]);
    $stmt=$pdo->prepare('SELECT r.id,f.head_resident_id FROM residents r JOIN family_records f ON f.id=r.family_id WHERE r.id=?');
    $stmt->execute([$residentId]); $familyHead=$stmt->fetch();
    expect_true($familyHead && (int)$familyHead['head_resident_id']===$residentId, 'Family head relationship must be storable');

    $sourceSnapshot = module_change_snapshot($residentId,$familyId,'update',false);
    $requestForSnapshot = [
        'resident_id'=>$residentId,
        'family_id'=>$familyId,
        'change_type'=>'update',
        'source_snapshot'=>json_encode($sourceSnapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
    ];
    expect_true(module_change_snapshot_matches($requestForSnapshot), 'An unchanged resident and household snapshot must be approved by the conflict checker.');

    $pdo->prepare('UPDATE residents SET phone=? WHERE id=?')->execute(['555-0101',$residentId]);
    expect_true(!module_change_snapshot_matches($requestForSnapshot), 'A changed resident field must put the request into conflict instead of overwriting it.');
    $pdo->prepare('UPDATE residents SET phone=NULL WHERE id=?')->execute([$residentId]);

    $moveSnapshot = module_change_snapshot($residentId,$familyId,'move_rt',false);
    $moveRequestForSnapshot = [
        'resident_id'=>$residentId,
        'family_id'=>$familyId,
        'change_type'=>'move_rt',
        'source_snapshot'=>json_encode($moveSnapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
    ];
    $siblingNik='3201000000000003';
    $siblingNikHash=private_identifier_hash($siblingNik);
    $siblingNikCipher=encrypt_private_identifier($siblingNik);
    $siblingNikMask=mask_private_identifier($siblingNik);
    $pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,gender,resident_status,created_by) VALUES (?,?,?,?,?,?,'P','pending_review',?)")
        ->execute([$familyId,$rtId,$siblingNikMask,$siblingNikCipher,$siblingNikHash,'Anggota CI',$rtUserId]);
    $siblingId=(int)$pdo->lastInsertId();
    expect_true(!module_change_snapshot_matches($moveRequestForSnapshot), 'Adding another household member must mark a move request as conflicted.');
    $pdo->prepare('DELETE FROM residents WHERE id=?')->execute([$siblingId]);

    $duplicateNikBlocked = false;
    try {
        $pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,resident_status,created_by) VALUES (?,?,?,?,?,?,'pending_review',?)")
            ->execute([$familyId,$rtId,$nikMask,$nikCipher,$nikHash,'Duplicate NIK',$rtUserId]);
    } catch (PDOException $e) {
        $duplicateNikBlocked = (string)$e->getCode()==='23000';
    }
    expect_true($duplicateNikBlocked, 'Duplicate NIK must be blocked by database unique constraint');

    $duplicateKkBlocked = false;
    try {
        $pdo->prepare("INSERT INTO family_records (family_number,family_number_cipher,family_number_hash,rt_id,address,status,created_by) VALUES (?,?,?,? ,?,'draft',?)")
            ->execute([$familyMask,$familyCipher,$familyHash,$rtId,'Alamat duplikat',$rtUserId]);
    } catch (PDOException $e) {
        $duplicateKkBlocked = (string)$e->getCode()==='23000';
    }
    expect_true($duplicateKkBlocked, 'Duplicate KK must be blocked by database unique constraint');

    $serviceId = (int)$pdo->query('SELECT id FROM service_types ORDER BY id LIMIT 1')->fetchColumn();
    expect_true($serviceId > 0, 'Default public service types must be seeded');
    $pdo->prepare("INSERT INTO service_requests (tracking_code,service_type_id,applicant_id,subject,request_data,status) VALUES (?,?,?,?,?,?)")
        ->execute(['CI-SR-TEST-001',$serviceId,$wargaId,'CI pengajuan',json_encode(['details'=>'CI test'],JSON_THROW_ON_ERROR),'submitted']);
    $serviceRequestId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE service_requests SET letter_number=?,letter_snapshot=?,letter_issued_by=?,letter_issued_at=NOW() WHERE id=?')->execute(['CI/LET/2026/001',json_encode(['village_name'=>'Desa CI','office_address'=>'Alamat CI','village_regency'=>'Kabupaten CI','village_province'=>'Jawa Barat','village_head_name'=>'Kepala Desa CI'],JSON_THROW_ON_ERROR),$adminId,$serviceRequestId]);
    $letterStmt = $pdo->prepare('SELECT letter_number,letter_issued_by,letter_issued_at FROM service_requests WHERE id=?');
    $letterStmt->execute([$serviceRequestId]);
    $letterRow = $letterStmt->fetch();
    expect_true($letterRow && $letterRow['letter_number']==='CI/LET/2026/001' && (int)$letterRow['letter_issued_by']===$adminId && !empty($letterRow['letter_issued_at']), 'Official letter number and issuer metadata must persist.');
    $serviceFileKey = bin2hex(random_bytes(24)) . '.pdf';
    $pdo->prepare('INSERT INTO service_request_files (request_id,storage_key,original_name,mime_type,size_bytes,uploaded_by) VALUES (?,?,?,?,?,?)')
        ->execute([$serviceRequestId,$serviceFileKey,'ci-private.pdf','application/pdf',1024,$wargaId]);
    $serviceFileId = (int)$pdo->lastInsertId();
    $privateServiceFile = $pdo->prepare('SELECT f.id,f.request_id,f.storage_key,sr.applicant_id FROM service_request_files f JOIN service_requests sr ON sr.id=f.request_id WHERE f.id=? LIMIT 1');
    $privateServiceFile->execute([$serviceFileId]);
    $serviceFileRow = $privateServiceFile->fetch();
    expect_true($serviceFileRow && (int)$serviceFileRow['request_id']===$serviceRequestId && (int)$serviceFileRow['applicant_id']===$wargaId, 'Private service attachments must be linked to exactly one request and its owner.');
    expect_true(module_can_access_service_file(['id'=>$wargaId,'role'=>'warga'],$serviceFileRow), 'The request owner must be authorized for their private attachment.');
    expect_true(!module_can_access_service_file(['id'=>$rtUserId,'role'=>'rt'],$serviceFileRow), 'RT users must not gain access to private service attachments.');
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
