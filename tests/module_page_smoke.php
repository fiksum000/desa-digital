<?php
declare(strict_types=1);

// Render selected authenticated module pages against the real CI MariaDB schema.
// The fixture is synthetic, uniquely named, and cleaned up in a finally block.
if (!getenv('APP_KEY')) putenv('APP_KEY=' . str_repeat('c', 64));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_USER_AGENT'] = 'DESA-DIGITAL-CI-page-smoke';

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

function fixture_key(string $purpose): string {
    return hash_hmac('sha256', 'DESA-DIGITAL:' . $purpose, (string)getenv('APP_KEY'), true);
}
function fixture_identifier(string $digits): array {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = base64_encode($nonce . sodium_crypto_secretbox($digits, $nonce, fixture_key('identifier-encryption')));
    $hash = hash_hmac('sha256', $digits, fixture_key('identifier-index'));
    return ['masked'=>str_repeat('*',12).substr($digits,-4),'cipher'=>$cipher,'hash'=>$hash];
}
function fixture_digits(PDO $pdo): string {
    do {
        $digits = (string)random_int(1000000000000000, 9999999999999999);
        $hash = hash_hmac('sha256', $digits, fixture_key('identifier-index'));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM residents WHERE nik_hash=?');
        $stmt->execute([$hash]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $digits;
}
function smoke_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$page = (string)($argv[1] ?? 'rt-data');
if (!in_array($page, ['rt-data','dokumen','notifikasi'], true)) {
    fwrite(STDERR, "Unsupported page smoke target.\n");
    exit(2);
}

$tag = bin2hex(random_bytes(4));
$dusunId = $rwId = $rtId = $userId = $familyId = $residentId = 0;
try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO dusun (name,code) VALUES (?,?)')->execute(['CI Smoke ' . $tag, 'CS' . strtoupper($tag)]);
    $dusunId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rw_units (dusun_id,number_code) VALUES (?,?)')->execute([$dusunId, '9' . substr($tag,0,1)]);
    $rwId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rt_units (rw_id,number_code) VALUES (?,?)')->execute([$rwId, '8' . substr($tag,1,1)]);
    $rtId = (int)$pdo->lastInsertId();
    $username = 'ci-rt-' . $tag;
    $pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role,rt_id,is_active,must_change_password,session_version) VALUES (?,?,?,'rt',?,1,0,1)")
        ->execute([$username, 'CI Smoke RT', password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), $rtId]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_notifications (user_id,title,message,notification_type,entity_type,entity_id) VALUES (?,?,?,'data_change','resident_change_request',?)")
        ->execute([$userId,'CI Smoke Notification','Synthetic notification for the authenticated inbox test.','CI-' . $tag]);

    $kk = fixture_digits($pdo);
    $nik = fixture_digits($pdo);
    $kkFields = fixture_identifier($kk);
    $nikFields = fixture_identifier($nik);
    $pdo->prepare("INSERT INTO family_records (family_number,family_number_cipher,family_number_hash,rt_id,address,status,created_by) VALUES (?,?,?,?,?,'active',?)")
        ->execute([$kkFields['masked'],$kkFields['cipher'],$kkFields['hash'],$rtId,'Alamat sintetis CI',$userId]);
    $familyId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,gender,resident_status,created_by) VALUES (?,?,?,?,?,?,'unknown','active',?)")
        ->execute([$familyId,$rtId,$nikFields['masked'],$nikFields['cipher'],$nikFields['hash'],'CI Smoke Resident ' . $tag,$userId]);
    $residentId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE family_records SET head_resident_id=? WHERE id=?')->execute([$residentId,$familyId]);
    $pdo->commit();

    $_SESSION['user'] = [
        'id'=>$userId,'username'=>$username,'full_name'=>'CI Smoke RT','role'=>'rt',
        'rt_id'=>$rtId,'rw_id'=>null,'must_change_password'=>0,'session_version'=>1
    ];
    $_GET = ['page'=>$page];
    if ($page === 'rt-data') $_GET['q'] = 'CI Smoke Resident';
    if ($page === 'notifikasi') $_GET['page'] = 'notifikasi';
    if ($page === 'dokumen') {
        $_GET['q'] = '1234';
        $_GET['status'] = 'approved';
        $_GET['type'] = 'kk';
    }
    $_POST = [];
    $_SERVER['REQUEST_URI'] = '/index.php?page=' . rawurlencode($page);
    ob_start();
    require dirname(__DIR__) . '/index.php';
    $html = (string)ob_get_clean();

    if ($page === 'rt-data') {
        smoke_assert(str_contains($html, 'Daftar penduduk'), 'RT directory heading did not render.');
        smoke_assert(str_contains($html, 'Data Belum Lengkap'), 'RT incomplete-record queue did not render.');
        smoke_assert(str_contains($html, 'CI Smoke Resident ' . $tag), 'Resident search did not return the synthetic resident.');
        smoke_assert(str_contains($html, 'KTP belum disetujui'), 'The incomplete-record queue did not flag a missing approved KTP.');
        smoke_assert(str_contains($html, 'KK keluarga belum disetujui'), 'The incomplete-record queue did not flag a missing approved KK.');
    } elseif ($page === 'notifikasi') {
        smoke_assert(str_contains($html, 'Kotak masuk'), 'Notification inbox did not render.');
        smoke_assert(str_contains($html, 'CI Smoke Notification'), 'Recipient notification was not shown in the inbox.');
        smoke_assert(str_contains($html, 'Belum dibaca'), 'Unread notification state was not rendered.');
    } else {
        smoke_assert(str_contains($html, 'Arsip dokumen &amp; antrean pemeriksaan') || str_contains($html, 'Arsip dokumen & antrean pemeriksaan'), 'Private document archive did not render.');
        smoke_assert(str_contains($html, 'Cari dokumen'), 'Document search controls did not render.');
        smoke_assert(str_contains($html, 'CI Smoke Resident ' . $tag), 'Private upload selector did not include the scoped resident.');
    }
    fwrite(STDOUT, "PASS: authenticated {$page} page smoke test.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errorMessage = $e->getMessage();
} finally {
    // Best-effort fixture cleanup; all values are synthetic.
    if (!$pdo->inTransaction()) {
        try {
            if ($residentId) {
                $pdo->prepare('DELETE FROM resident_documents WHERE resident_id=? OR family_id=?')->execute([$residentId,$familyId]);
                $pdo->prepare('DELETE FROM resident_change_requests WHERE resident_id=? OR family_id=?')->execute([$residentId,$familyId]);
                $pdo->prepare('DELETE FROM residents WHERE id=?')->execute([$residentId]);
            }
            if ($familyId) $pdo->prepare('DELETE FROM family_records WHERE id=?')->execute([$familyId]);
            if ($userId) $pdo->prepare('DELETE FROM user_notifications WHERE user_id=?')->execute([$userId]);
            if ($userId) $pdo->prepare('DELETE FROM app_users WHERE id=?')->execute([$userId]);
            if ($rtId) $pdo->prepare('DELETE FROM rt_units WHERE id=?')->execute([$rtId]);
            if ($rwId) $pdo->prepare('DELETE FROM rw_units WHERE id=?')->execute([$rwId]);
            if ($dusunId) $pdo->prepare('DELETE FROM dusun WHERE id=?')->execute([$dusunId]);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, "WARN: could not clean synthetic smoke fixture.\n");
        }
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
if (isset($errorMessage)) {
    fwrite(STDERR, "FAIL: {$page} page smoke test: " . $errorMessage . "\n");
    exit(1);
}
