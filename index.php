<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$viewer = current_user();
$dbAvailable = true;
$services = [];
$news = [];
$tracking = null;
$article = null;
$requests = [];
$complaints = [];
$adminRequests = [];
$adminComplaints = [];
$adminNews = [];
$notifications = [];
$unreadNotificationCount = 0;
$editingNews = null;
$stats = ['residents' => null, 'families' => null, 'dusun' => null];

function status_text(string $status): string {
    return [
        'submitted'=>'Diajukan','in_review'=>'Sedang diperiksa','revision_requested'=>'Perlu perbaikan',
        'approved'=>'Disetujui','rejected'=>'Ditolak','ready'=>'Siap diambil','completed'=>'Selesai',
        'cancelled'=>'Dibatalkan','received'=>'Diterima','in_progress'=>'Ditindaklanjuti',
        'waiting_reporter'=>'Menunggu keterangan','conflict'=>'Konflik data','resolved'=>'Tuntas','published'=>'Terbit','draft'=>'Draf',
        'archived'=>'Arsip'
    ][$status] ?? ucfirst(str_replace('_',' ',$status));
}
function status_class(string $status): string {
    return in_array($status, ['approved','completed','resolved','published','ready'], true) ? 'good'
      : (in_array($status, ['rejected','cancelled','archived'], true) ? 'bad'
      : (in_array($status, ['revision_requested','waiting_reporter','conflict'], true) ? 'warn' : 'neutral'));
}
function safe_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
    return trim(substr((string)$value, 0, 220), '-') ?: 'berita-' . bin2hex(random_bytes(3));
}
function input_local_datetime_to_utc(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new DateTimeZone('Asia/Jakarta'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new RuntimeException('Format jadwal tanggal tidak valid.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function utc_datetime_to_local_input(?string $value): string {
    if (!$value) return '';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d\\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}
function utc_datetime_to_local_display(?string $value, string $format = 'd M Y H:i'): string {
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Jakarta'))->format($format);
    } catch (Throwable $e) {
        return '—';
    }
}
$siteContent = [];
function site_val(string $key, string $default = ''): string {
    global $siteContent;
    return isset($siteContent[$key]) ? (string)$siteContent[$key] : $default;
}

if ($viewer && !empty($viewer['must_change_password'])) {
    $requestedAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
    if ($page !== 'change-password' && !in_array($requestedAction, ['change_password','logout'], true)) {
        redirect_to('change-password');
    }
}

function upload_public_image(string $field): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Gambar gagal diunggah atau melebihi 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Format gambar harus JPG, PNG, atau WebP.');
    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Penyimpanan gambar tidak tersedia.');
    $name = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Gagal menyimpan gambar.');
    return 'assets/uploads/' . $name;
}

function service_request_files_for_display(PDO $pdo, int $requestId): array {
    static $cache = [];
    if (!array_key_exists($requestId, $cache)) {
        $stmt = $pdo->prepare('SELECT id,original_name,mime_type,size_bytes,created_at FROM service_request_files WHERE request_id=? ORDER BY id');
        $stmt->execute([$requestId]);
        $cache[$requestId] = $stmt->fetchAll();
    }
    return $cache[$requestId];
}

function normalize_private_uploads(string $field, int $maxFiles = 5): array {
    if (!isset($_FILES[$field])) return [];
    $input = $_FILES[$field];
    if (!is_array($input['name'] ?? null)) {
        if ((int)($input['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
        throw new RuntimeException('Format lampiran tidak valid.');
    }
    $files = [];
    foreach ($input['name'] as $index => $name) {
        $error = (int)($input['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        $files[] = [
            'name' => (string)$name,
            'type' => (string)($input['type'][$index] ?? ''),
            'tmp_name' => (string)($input['tmp_name'][$index] ?? ''),
            'error' => $error,
            'size' => (int)($input['size'][$index] ?? 0),
        ];
    }
    if (count($files) > $maxFiles) {
        throw new RuntimeException('Maksimal ' . $maxFiles . ' lampiran per pengajuan.');
    }
    return $files;
}

function save_service_request_files(PDO $pdo, int $requestId, int $userId, array $uploads): array {
    $storedKeys = [];
    try {
        foreach ($uploads as $upload) {
            $saved = module_upload_private_file($upload);
            $storedKeys[] = (string)$saved['key'];
            $stmt = $pdo->prepare(
                'INSERT INTO service_request_files
                (request_id,storage_key,original_name,mime_type,size_bytes,uploaded_by)
                VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $requestId, $saved['key'], $saved['name'], $saved['mime'],
                $saved['size'], $userId
            ]);
            $fileId = (int)$pdo->lastInsertId();
            audit_log($userId, 'upload', 'service_request_files', (string)$fileId, null, [
                'request_id' => $requestId,
                'mime_type' => $saved['mime'],
                'size_bytes' => $saved['size']
            ]);
        }
        return $storedKeys;
    } catch (Throwable $e) {
        foreach ($storedKeys as $key) {
            $path = __DIR__ . '/storage/uploads/' . $key;
            if (is_file($path)) @unlink($path);
        }
        throw $e;
    }
}

require_once __DIR__ . '/app/modules.php';
module_serve_private_document();
module_serve_private_service_file();
module_serve_service_letter_pdf();
module_serve_export();
module_handle_post();

try {
    $pdo = db();
    foreach ($pdo->query("SELECT content_key,content_value FROM site_content WHERE status='published'")->fetchAll() as $contentRow) {
        $siteContent[(string)$contentRow['content_key']] = (string)$contentRow['content_value'];
    }
    $services = $pdo->query('SELECT id, name, slug, description, requirements, estimated_days FROM service_types WHERE is_published=1 ORDER BY sort_order, name')->fetchAll();
    $news = $pdo->query("SELECT id,title,slug,category,excerpt,body,image_key,alt_text,publish_at,created_at FROM news_posts WHERE status='published' AND (publish_at IS NULL OR publish_at<=NOW()) AND (expire_at IS NULL OR expire_at>NOW()) ORDER BY COALESCE(publish_at,created_at) DESC LIMIT 6")->fetchAll();
    $stats['residents'] = (int)$pdo->query("SELECT COUNT(*) FROM residents WHERE resident_status='active'")->fetchColumn();
    $stats['families'] = (int)$pdo->query("SELECT COUNT(*) FROM family_records WHERE status='active'")->fetchColumn();
    $stats['dusun'] = (int)$pdo->query("SELECT COUNT(*) FROM dusun WHERE is_active=1")->fetchColumn();
    if ($viewer) {
        $notificationStmt=$pdo->prepare('SELECT id,title,message,notification_type,entity_type,entity_id,read_at,created_at FROM user_notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 6');
        $notificationStmt->execute([(int)$viewer['id']]);
        $notifications=$notificationStmt->fetchAll();
        $unreadStmt=$pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND read_at IS NULL');
        $unreadStmt->execute([(int)$viewer['id']]);
        $unreadNotificationCount=(int)$unreadStmt->fetchColumn();
    }
} catch (Throwable $exception) {
    $dbAvailable = false;
    error_log('DESA DIGITAL database unavailable.');
    $services = [
        ['id'=>0,'name'=>'Layanan Surat','slug'=>'layanan-surat','description'=>'Ajukan surat keterangan dengan mudah dan cepat.','requirements'=>null,'estimated_days'=>null],
        ['id'=>0,'name'=>'Pengaduan Warga','slug'=>'pengaduan-warga','description'=>'Sampaikan aspirasi, keluhan, atau laporan Anda.','requirements'=>null,'estimated_days'=>null],
        ['id'=>0,'name'=>'Informasi Desa','slug'=>'informasi-desa','description'=>'Akses informasi program, kegiatan, dan data desa.','requirements'=>null,'estimated_days'=>null],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        $pdo = db();
        if ($action === 'register') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($name) < 3 || mb_strlen($name) > 160) throw new RuntimeException('Nama harus terdiri dari 3–160 karakter.');
            if (!preg_match('/^[a-z0-9._-]{4,80}$/', $username)) throw new RuntimeException('Username harus 4–80 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email tidak valid.');
            if (strlen($password) < 12) throw new RuntimeException('Kata sandi minimal 12 karakter.');
            $stmt = $pdo->prepare("INSERT INTO app_users (username,email,full_name,password_hash,role) VALUES (?,?,?,?,'warga')");
            $stmt->execute([$username, $email !== '' ? $email : null, $name, password_hash($password, PASSWORD_DEFAULT)]);
            audit_log((int)$pdo->lastInsertId(), 'register', 'app_users', (string)$pdo->lastInsertId());
            flash('Akun warga berhasil dibuat. Silakan masuk.');
            redirect_to('login');
        } elseif ($action === 'login') {
            // Keep a small per-session guard as defense in depth, but enforce the real
            // per-account and per-network limit in the database so new sessions cannot bypass it.
            $now = time();
            if (!isset($_SESSION['login_window']) || $now - (int)$_SESSION['login_window'] > 600) {
                $_SESSION['login_window'] = $now; $_SESSION['login_failures'] = 0;
            }
            if ((int)($_SESSION['login_failures'] ?? 0) >= 5) throw new RuntimeException('Terlalu banyak percobaan. Tunggu 10 menit lalu coba kembali.');
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            auth_rate_limit_assert_allowed($username);
            $stmt = $pdo->prepare('SELECT id,username,full_name,password_hash,role,rt_id,rw_id,is_active,must_change_password,session_version FROM app_users WHERE username=? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !(int)$user['is_active'] || !password_verify($password, $user['password_hash'])) {
                $_SESSION['login_failures'] = (int)($_SESSION['login_failures'] ?? 0) + 1;
                auth_rate_limit_record_failure($username);
                usleep(250000);
                throw new RuntimeException('Username atau kata sandi tidak cocok.');
            }
            auth_rate_limit_clear_account($username);
            session_regenerate_id(true);
            unset($user['password_hash'], $user['is_active']);
            $_SESSION['user'] = $user;
            $_SESSION['login_failures'] = 0;
            $pdo->prepare('UPDATE app_users SET last_login_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
            audit_log((int)$user['id'], 'login', 'app_users', (string)$user['id']);
            redirect_to(!empty($user['must_change_password']) ? 'change-password' : 'dashboard');
        } elseif ($action === 'change_password') {
            $user = require_login();
            $oldPassword = (string)($_POST['old_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            if (strlen($newPassword) < 14) throw new RuntimeException('Kata sandi baru minimal 14 karakter.');
            if (!hash_equals($newPassword, $confirmPassword)) throw new RuntimeException('Konfirmasi kata sandi belum sama.');
            if (hash_equals($oldPassword, $newPassword)) throw new RuntimeException('Gunakan kata sandi baru yang berbeda dari kata sandi lama.');
            $stmt = $pdo->prepare('SELECT password_hash,session_version,is_active FROM app_users WHERE id=? LIMIT 1');
            $stmt->execute([(int)$user['id']]);
            $account = $stmt->fetch();
            if (!$account || !(int)$account['is_active'] || !password_verify($oldPassword, $account['password_hash'])) {
                throw new RuntimeException('Kata sandi lama tidak cocok.');
            }
            $newVersion = (int)$account['session_version'] + 1;
            $stmt = $pdo->prepare('UPDATE app_users SET password_hash=?,must_change_password=0,session_version=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $newVersion, (int)$user['id']]);
            $_SESSION['user']['must_change_password'] = 0;
            $_SESSION['user']['session_version'] = $newVersion;
            audit_log((int)$user['id'], 'change_password', 'app_users', (string)$user['id'], null, ['session_version'=>$newVersion]);
            flash('Kata sandi berhasil diganti.');
            redirect_to('dashboard');
        } elseif ($action === 'logout') {
            $user = current_user();
            if ($user) audit_log((int)$user['id'], 'logout', 'app_users', (string)$user['id']);
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', ['expires'=>time()-42000,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>'Lax']);
            }
            session_destroy();
            redirect_to('home');
        } elseif ($action === 'apply_service') {
            $user = require_login();
            if (($user['role'] ?? '') !== 'warga') throw new RuntimeException('Pengajuan online tersedia untuk akun warga.');
            $serviceId = filter_input(INPUT_POST, 'service_type_id', FILTER_VALIDATE_INT);
            $subject = trim((string)($_POST['subject'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $uploads = normalize_private_uploads('attachments', 5);
            if (!$serviceId || mb_strlen($subject) < 4 || mb_strlen($subject) > 190) throw new RuntimeException('Pilih layanan dan isi keperluan dengan benar.');
            if (mb_strlen($details) > 4000) throw new RuntimeException('Keterangan terlalu panjang.');
            $check = $pdo->prepare('SELECT id FROM service_types WHERE id=? AND is_published=1');
            $check->execute([$serviceId]);
            if (!$check->fetch()) throw new RuntimeException('Jenis layanan tidak tersedia.');
            $code = tracking_code('SR');
            $storedKeys = [];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO service_requests (tracking_code,service_type_id,applicant_id,subject,request_data,status) VALUES (?,?,?,?,?,\'submitted\')');
                $stmt->execute([$code,$serviceId,(int)$user['id'],$subject,json_encode(['details'=>$details], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                $requestId = (int)$pdo->lastInsertId();
                $storedKeys = save_service_request_files($pdo, $requestId, (int)$user['id'], $uploads);
                audit_log((int)$user['id'], 'create', 'service_requests', (string)$requestId, null, ['tracking_code'=>$code,'attachment_count'=>count($storedKeys)]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($storedKeys as $key) {
                    $path = __DIR__ . '/storage/uploads/' . $key;
                    if (is_file($path)) @unlink($path);
                }
                throw $e;
            }
            flash('Pengajuan terkirim. Simpan kode pelacakan ' . $code . ($storedKeys ? ' Lampiran berhasil disimpan secara privat.' : '') . '.');
            redirect_to('dashboard');
        } elseif ($action === 'revise_service') {
            $user = require_login();
            if (($user['role'] ?? '') !== 'warga') throw new RuntimeException('Akses tidak diizinkan.');
            $id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
            $subject = trim((string)($_POST['subject'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $uploads = normalize_private_uploads('attachments', 5);
            $stmt = $pdo->prepare("SELECT id,status,request_data FROM service_requests WHERE id=? AND applicant_id=? LIMIT 1");
            $stmt->execute([$id,(int)$user['id']]);
            $row = $stmt->fetch();
            if (!$row || $row['status'] !== 'revision_requested') throw new RuntimeException('Pengajuan tidak sedang menunggu revisi.');
            if (mb_strlen($subject) < 4 || mb_strlen($subject) > 190 || mb_strlen($details) > 4000) throw new RuntimeException('Data revisi tidak valid.');
            $storedKeys = [];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE service_requests SET subject=?, request_data=?, status='submitted', public_status_note='Revisi telah dikirim dan menunggu pemeriksaan baru.' WHERE id=? AND applicant_id=? AND status='revision_requested'");
                $stmt->execute([$subject,json_encode(['details'=>$details], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),(int)$id,(int)$user['id']]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Status pengajuan berubah. Muat ulang halaman sebelum mengirim revisi.');
                $storedKeys = save_service_request_files($pdo, (int)$id, (int)$user['id'], $uploads);
                audit_log((int)$user['id'], 'revise', 'service_requests', (string)$id, null, ['attachment_count'=>count($storedKeys)]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($storedKeys as $key) {
                    $path = __DIR__ . '/storage/uploads/' . $key;
                    if (is_file($path)) @unlink($path);
                }
                throw $e;
            }
            flash('Revisi dikirim pada nomor pengajuan yang sama.' . ($storedKeys ? ' Lampiran tambahan tersimpan secara privat.' : ''));
            redirect_to('dashboard');
        } elseif ($action === 'submit_complaint') {
            $user = require_login();
            if (($user['role'] ?? '') !== 'warga') throw new RuntimeException('Pengaduan online tersedia untuk akun warga.');
            $title = trim((string)($_POST['title'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'umum'));
            $description = trim((string)($_POST['description'] ?? ''));
            if (mb_strlen($title) < 5 || mb_strlen($title) > 190 || mb_strlen($description) < 10 || mb_strlen($description) > 5000) throw new RuntimeException('Judul dan keterangan pengaduan belum memenuhi ketentuan.');
            $code = tracking_code('PG');
            $stmt = $pdo->prepare("INSERT INTO complaints (tracking_code,reporter_id,title,category,description,status) VALUES (?,?,?,?,?,'received')");
            $stmt->execute([$code,(int)$user['id'],$title,$category,$description]);
            audit_log((int)$user['id'], 'create', 'complaints', (string)$pdo->lastInsertId(), null, ['tracking_code'=>$code]);
            flash('Pengaduan diterima. Simpan kode pelacakan ' . $code . '.');
            redirect_to('dashboard');
        } elseif ($action === 'set_request_status') {
            $user = require_roles(['petugas','admin_desa','kepala_desa']);
            $id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
            $newStatus = (string)($_POST['status'] ?? '');
            $publicNote = trim((string)($_POST['public_status_note'] ?? ''));
            $internalNote = trim((string)($_POST['internal_note'] ?? ''));
            $allowed = ['in_review','revision_requested','approved','rejected','ready','completed'];
            if (!$id || !in_array($newStatus, $allowed, true)) throw new RuntimeException('Status pengajuan tidak valid.');
            if (in_array($newStatus, ['approved','rejected','ready','completed'], true) && !in_array($user['role'], ['admin_desa','kepala_desa'], true)) {
                throw new RuntimeException('Persetujuan/keputusan akhir harus dilakukan admin desa atau kepala desa.');
            }
            $stmt = $pdo->prepare('SELECT id,status,letter_number FROM service_requests WHERE id=?');
            $stmt->execute([$id]); $before = $stmt->fetch();
            if (!$before) throw new RuntimeException('Pengajuan tidak ditemukan.');
            if (!module_service_status_transition_allowed((string)$before['status'], $newStatus)) throw new RuntimeException('Perubahan status tidak sesuai alur pelayanan surat. Muat ulang halaman dan periksa status terbaru.');
            if ($newStatus === 'ready' && trim((string)($before['letter_number'] ?? '')) === '') throw new RuntimeException('Terbitkan nomor surat terlebih dahulu sebelum menandai surat siap diambil.');
            $stmt = $pdo->prepare('UPDATE service_requests SET status=?,public_status_note=?,internal_note=?,assigned_to=?,approved_by=CASE WHEN ? IN (\'approved\',\'rejected\') THEN ? ELSE approved_by END,approved_at=CASE WHEN ? IN (\'approved\',\'rejected\') THEN NOW() ELSE approved_at END,completed_at=CASE WHEN ?=\'completed\' THEN NOW() ELSE completed_at END WHERE id=? AND status=?');
            $stmt->execute([$newStatus,mb_substr($publicNote,0,500),mb_substr($internalNote,0,4000),(int)$user['id'],$newStatus,(int)$user['id'],$newStatus,$newStatus,(int)$id,(string)$before['status']]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Status pengajuan berubah sebelum tersimpan. Muat ulang antrean lalu coba lagi.');
            audit_log((int)$user['id'], 'status_change', 'service_requests', (string)$id, ['status'=>$before['status']], ['status'=>$newStatus]);
            flash('Status pengajuan diperbarui.');
            redirect_to('admin');
        } elseif ($action === 'issue_service_letter') {
            $user = require_roles(['admin_desa','kepala_desa']);
            $id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
            $letterNumber = trim((string)($_POST['letter_number'] ?? ''));
            if (!$id || !preg_match('/^[\\pL0-9][\\pL0-9 .\\/_-]{2,99}$/u', $letterNumber)) {
                throw new RuntimeException('Nomor surat wajib diisi dengan format yang valid (3–100 karakter).');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT id,status,letter_number FROM service_requests WHERE id=? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row || $row['status'] !== 'approved') {
                    throw new RuntimeException('Nomor surat hanya dapat diterbitkan setelah pengajuan disetujui.');
                }
                if (trim((string)($row['letter_number'] ?? '')) !== '') {
                    throw new RuntimeException('Nomor surat sudah diterbitkan. Gunakan tautan Unduh PDF, jangan membuat nomor kedua.');
                }

                $stmt = $pdo->query("SELECT content_key,content_value FROM site_content WHERE status='published'");
                $site = [];
                foreach ($stmt->fetchAll() as $item) $site[(string)$item['content_key']] = trim((string)$item['content_value']);
                $required = [
                    'village_name' => 'Nama desa',
                    'office_address' => 'Alamat kantor desa',
                    'village_regency' => 'Kabupaten/Kota',
                    'village_province' => 'Provinsi',
                    'village_head_name' => 'Nama kepala desa/pejabat penandatangan',
                ];
                $missing = [];
                foreach ($required as $key => $label) {
                    if (($site[$key] ?? '') === '') $missing[] = $label;
                }
                if ($missing) throw new RuntimeException('Lengkapi pengaturan Admin Desa → Tampilan Website terlebih dahulu: ' . implode(', ', $missing) . '.');

                $snapshot = [];
                foreach (['village_name','village_subdistrict','village_regency','village_province','village_head_name','village_head_title','office_address','office_phone'] as $key) {
                    $snapshot[$key] = (string)($site[$key] ?? '');
                }
                $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $stmt = $pdo->prepare("UPDATE service_requests SET letter_number=?,letter_snapshot=?,letter_issued_by=?,letter_issued_at=NOW() WHERE id=? AND status='approved' AND letter_number IS NULL");
                $stmt->execute([$letterNumber,$snapshotJson,(int)$user['id'],(int)$id]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Pengajuan berubah saat nomor surat diterbitkan. Muat ulang halaman lalu coba lagi.');
                audit_log((int)$user['id'], 'issue_letter', 'service_requests', (string)$id, null, ['letter_number'=>$letterNumber]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            flash('Nomor surat sudah dicatat. Unduh dan periksa PDF sebelum menandai surat siap diambil.');
            redirect_to('admin');
} elseif ($action === 'save_news') {
            $user = require_roles(['petugas','admin_desa']);
            $id = filter_input(INPUT_POST, 'news_id', FILTER_VALIDATE_INT) ?: null;
            $title = trim((string)($_POST['title'] ?? ''));
            $category = (string)($_POST['category'] ?? 'berita');
            $excerpt = trim((string)($_POST['excerpt'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $status = (string)($_POST['status'] ?? 'draft');
            $publishAt = input_local_datetime_to_utc((string)($_POST['publish_at'] ?? ''));
            $expireAt = input_local_datetime_to_utc((string)($_POST['expire_at'] ?? ''));
            if (mb_strlen($title) < 4 || mb_strlen($title) > 220 || mb_strlen($body) < 10 || mb_strlen($body) > 30000) throw new RuntimeException('Judul atau isi berita belum valid.');
            if (!in_array($category, ['kegiatan','pengumuman','program','berita'], true) || !in_array($status, ['draft','published','archived'], true)) throw new RuntimeException('Kategori atau status berita tidak valid.');
            if ($status === 'published' && $publishAt === null) $publishAt = gmdate('Y-m-d H:i:s');
            if ($publishAt !== null && $expireAt !== null && $expireAt <= $publishAt) throw new RuntimeException('Waktu selesai tayang harus setelah waktu mulai tayang.');
            $altText = trim((string)($_POST['alt_text'] ?? '')) ?: $title;
            $uploadedImage = upload_public_image('image');
            if ($id) {
                $stmt=$pdo->prepare('SELECT id,slug,image_key,status,publish_at,expire_at,title FROM news_posts WHERE id=? LIMIT 1');
                $stmt->execute([$id]); $before=$stmt->fetch();
                if (!$before) throw new RuntimeException('Berita yang akan diedit tidak ditemukan.');
                $imageKey = $uploadedImage ?? $before['image_key'];
                $stmt=$pdo->prepare('UPDATE news_posts SET title=?,category=?,excerpt=?,body=?,image_key=?,alt_text=?,status=?,publish_at=?,expire_at=? WHERE id=?');
                $stmt->execute([$title,$category,mb_substr($excerpt,0,500),$body,$imageKey,$altText,$status,$publishAt,$expireAt,(int)$id]);
                audit_log((int)$user['id'],'update','news_posts',(string)$id,$before,['title'=>$title,'status'=>$status,'publish_at'=>$publishAt,'expire_at'=>$expireAt]);
                flash($status === 'published' ? 'Berita diperbarui dan dijadwalkan/diterbitkan.' : ($status === 'draft' ? 'Perubahan berita disimpan sebagai draf.' : 'Berita diarsipkan.'));
            } else {
                $slug = safe_slug($title) . '-' . bin2hex(random_bytes(3));
                $stmt = $pdo->prepare('INSERT INTO news_posts (title,slug,category,excerpt,body,image_key,alt_text,status,publish_at,expire_at,author_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$title,$slug,$category,mb_substr($excerpt,0,500),$body,$uploadedImage,$altText,$status,$publishAt,$expireAt,(int)$user['id']]);
                $newId=(string)$pdo->lastInsertId();
                audit_log((int)$user['id'], 'create', 'news_posts', $newId, null, ['title'=>$title,'status'=>$status,'publish_at'=>$publishAt,'expire_at'=>$expireAt]);
                flash($status === 'published' ? 'Berita berhasil dijadwalkan/diterbitkan.' : ($status === 'draft' ? 'Berita disimpan sebagai draf.' : 'Berita dibuat sebagai arsip.'));
            }
            redirect_to('admin');
        } elseif ($action === 'set_news_status') {
            $user = require_roles(['petugas','admin_desa']);
            $id = filter_input(INPUT_POST, 'news_id', FILTER_VALIDATE_INT);
            $status = (string)($_POST['status'] ?? '');
            if (!$id || !in_array($status, ['published','draft','archived'], true)) throw new RuntimeException('Status berita tidak valid.');
            $pdo->prepare('UPDATE news_posts SET status=?,publish_at=CASE WHEN ?=\'published\' AND publish_at IS NULL THEN NOW() ELSE publish_at END WHERE id=?')->execute([$status,$status,$id]);
            audit_log((int)$user['id'], 'status_change', 'news_posts', (string)$id, null, ['status'=>$status]);
            flash('Status berita diperbarui.');
            redirect_to('admin');
        } else {
            throw new RuntimeException('Aksi tidak dikenali.');
        }
    } catch (Throwable $exception) {
        if ($exception instanceof PDOException) {
            error_log('DESA DIGITAL database operation failed.');
            $message = 'Data belum dapat disimpan. Periksa isian atau hubungi admin desa.';
        } else {
            $message = $exception->getMessage();
        }
        flash($message, 'error');
        $fallbackPage = $action === 'login' ? 'login' : ($action === 'register' ? 'register' : ($action === 'change_password' ? 'change-password' : ($action === 'save_news' ? 'admin' : ($action === 'submit_complaint' ? 'pengaduan' : ($action === 'apply_service' ? 'layanan' : 'dashboard')))));
        redirect_to($fallbackPage);
    }
}

try {
    $pdo = db();
    if ($page === 'track' && isset($_GET['code'])) {
        $code = strtoupper(trim((string)$_GET['code']));
        if (preg_match('/^[A-Z0-9-]{5,32}$/', $code)) {
            $stmt = $pdo->prepare('SELECT tracking_code,subject AS title,status,public_status_note AS public_note,created_at,updated_at,\'service\' AS item_type FROM service_requests WHERE tracking_code=? UNION ALL SELECT tracking_code,title,status,public_response,created_at,updated_at,\'complaint\' AS item_type FROM complaints WHERE tracking_code=? LIMIT 1');
            $stmt->execute([$code,$code]); $tracking = $stmt->fetch() ?: null;
        }
    }
    if ($page === 'article') {
        $slug = trim((string)($_GET['slug'] ?? ''));
        $stmt = $pdo->prepare("SELECT * FROM news_posts WHERE slug=? AND status='published' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP()) AND (expire_at IS NULL OR expire_at > UTC_TIMESTAMP()) AND (publish_at IS NULL OR publish_at<=NOW()) AND (expire_at IS NULL OR expire_at>NOW()) LIMIT 1");
        $stmt->execute([$slug]); $article = $stmt->fetch() ?: null;
    }
    if ($page === 'dashboard' && $viewer) {
        $stmt = $pdo->prepare('SELECT sr.*, st.name AS service_name FROM service_requests sr JOIN service_types st ON st.id=sr.service_type_id WHERE sr.applicant_id=? ORDER BY sr.created_at DESC LIMIT 50');
        $stmt->execute([(int)$viewer['id']]); $requests = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM complaints WHERE reporter_id=? ORDER BY created_at DESC LIMIT 50');
        $stmt->execute([(int)$viewer['id']]); $complaints = $stmt->fetchAll();
    }
    if ($page === 'admin' && $viewer && in_array($viewer['role'], ['petugas','admin_desa','kepala_desa'], true)) {
        $adminRequests = $pdo->query('SELECT sr.*,u.full_name AS applicant_name,st.name AS service_name FROM service_requests sr JOIN app_users u ON u.id=sr.applicant_id JOIN service_types st ON st.id=sr.service_type_id ORDER BY FIELD(sr.status,\'submitted\',\'in_review\',\'revision_requested\',\'approved\',\'ready\',\'completed\'),sr.created_at DESC LIMIT 100')->fetchAll();
        $adminComplaints = $pdo->query('SELECT c.*,u.full_name AS reporter_name FROM complaints c JOIN app_users u ON u.id=c.reporter_id ORDER BY c.created_at DESC LIMIT 50')->fetchAll();
        $adminNews = $pdo->query('SELECT id,title,status,category,created_at,publish_at,expire_at FROM news_posts ORDER BY created_at DESC LIMIT 30')->fetchAll();
        $editNewsId = filter_input(INPUT_GET, 'edit_news', FILTER_VALIDATE_INT);
        if ($editNewsId) {
            $stmt=$pdo->prepare('SELECT * FROM news_posts WHERE id=? LIMIT 1');
            $stmt->execute([$editNewsId]);
            $editingNews=$stmt->fetch() ?: null;
        }
    }
} catch (Throwable $exception) {
    $dbAvailable = false;
    error_log('DESA DIGITAL could not load requested data.');
}

$flashMessage = take_flash();
$pageTitles = ['home'=>'Beranda','layanan'=>'Layanan Desa','pengaduan'=>'Pengaduan Warga','track'=>'Lacak Pengajuan','berita'=>'Berita & Pengumuman','article'=>'Berita','profil'=>'Profil Desa','login'=>'Masuk','register'=>'Daftar Akun','dashboard'=>'Dashboard Saya','change-password'=>'Ganti Kata Sandi','admin'=>'Panel Pengelolaan'];
$title = $pageTitles[$page] ?? 'DESA DIGITAL';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#075b46">
  <meta name="description" content="Portal informasi dan pelayanan publik desa. Pengajuan layanan, pelacakan, berita, dan pengaduan warga.">
  <title><?= e($title) ?> · DESA DIGITAL</title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
<header class="site-header">
  <a class="brand" href="<?= e(app_url('home')) ?>" aria-label="DESA DIGITAL - Beranda">
    <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 40 40"><path d="M5 31 27 4h9L14 36H5z" fill="#77b84a"/><path d="M31 4h5v14c0 10-7 18-18 18h-4z" fill="#07634e"/><path d="M5 31 27 4" stroke="#e8f4d7" stroke-width="1.3"/></svg></span>
    <span><strong>DESA</strong> DIGITAL</span>
  </a>
  <nav class="main-nav" aria-label="Navigasi utama">
    <a class="<?= $page==='home'?'active':'' ?>" href="<?= e(app_url('home')) ?>">Beranda</a>
    <a class="<?= $page==='layanan'?'active':'' ?>" href="<?= e(app_url('layanan')) ?>">Layanan Desa</a>
    <a class="<?= in_array($page,['berita','article'],true)?'active':'' ?>" href="<?= e(app_url('berita')) ?>">Berita & Pengumuman</a>
    <a class="<?= $page==='pengaduan'?'active':'' ?>" href="<?= e(app_url('pengaduan')) ?>">Pengaduan</a>
    <a class="<?= $page==='profil'?'active':'' ?>" href="<?= e(app_url('profil')) ?>">Profil Desa</a>
    <div class="nav-more">
      <button class="nav-more-button" type="button" aria-expanded="false" aria-controls="nav-more-menu">Lainnya <span aria-hidden="true">⌄</span></button>
      <div class="nav-more-menu" id="nav-more-menu">
        <a href="<?= e(app_url('umkm')) ?>">UMKM & Wisata</a>
        <a href="<?= e(app_url('peta')) ?>">Peta Desa</a>
        <a href="<?= e(app_url('transparansi')) ?>">Transparansi Anggaran</a>
        <a href="<?= e(app_url('kontak')) ?>">Kontak</a>
      </div>
    </div>
  </nav>
  <div class="header-actions">
    <a class="icon-button search-link" href="<?= e(app_url('berita')) ?>" aria-label="Cari berita">⌕</a>
    <button class="icon-button mobile-menu-toggle" type="button" aria-label="Buka menu tambahan" aria-expanded="false" aria-controls="mobile-drawer">☰</button>
    <?php if ($viewer): ?>
      <a class="login-button" href="<?= e(app_url('dashboard')) ?>">♙ <span><?= e($viewer['full_name']) ?></span></a>
    <?php else: ?>
      <a class="login-button" href="<?= e(app_url('login')) ?>">♙ <span>Masuk</span></a>
    <?php endif; ?>
  </div>
</header>

<?php if ($flashMessage): ?>
  <div class="flash-wrap"><div class="flash <?= e($flashMessage['type'] ?? 'success') ?>"><?= e($flashMessage['message'] ?? '') ?></div></div>
<?php endif; ?>
<?php if (!$dbAvailable && !in_array($page,['login','register','home','layanan','berita','profil'],true)): ?>
  <div class="flash-wrap"><div class="flash error">Database belum siap atau sedang tidak tersedia. Data tidak dihapus; periksa konfigurasi server.</div></div>
<?php endif; ?>

<main>
<?php if ($page === 'home'): ?>
  <section class="hero">
    <div class="hero-copy">
      <span class="eyebrow">Pelayanan Publik Desa</span>
      <h1><?= e(site_val('hero_title_line1','Layanan desa,')) ?><br><?= e(site_val('hero_title_prefix','kini')) ?> <em><?= e(site_val('hero_title_highlight','lebih dekat')) ?></em></h1>
      <p class="hero-description"><?= e(site_val('hero_subtitle','Akses berbagai layanan surat, pengaduan, informasi, dan kabar desa dalam satu tempat, dengan mudah, cepat, dan transparan.')) ?></p>
      <div class="hero-actions">
        <a class="button button-primary" href="<?= e(app_url('layanan')) ?>"><span class="button-icon">▤</span> Ajukan Surat <span class="arrow">→</span></a>
        <a class="button button-outline" href="<?= e(app_url('track')) ?>"><span class="button-icon">⌕</span> Lacak Pengajuan <span class="arrow">→</span></a>
      </div>
      <div class="benefits">
        <div><span class="benefit-icon">▣</span><span>Mudah<br>diakses</span></div>
        <div><span class="benefit-icon">ϟ</span><span>Proses<br>lebih cepat</span></div>
        <div><span class="benefit-icon">⬟</span><span>Transparan<br>dan terpercaya</span></div>
        <div><span class="benefit-icon">♟</span><span>untuk<br>Seluruh Warga</span></div>
      </div>
    </div>
    <div class="hero-visual">
      <img src="<?= e(site_val('hero_image','assets/village-hero.webp')) ?>" alt="<?= e(site_val('hero_image_alt','Pemandangan desa dan pegunungan')) ?>" style="object-position:<?= max(0,min(100,(int)site_val('hero_focus_x','50'))) ?>% <?= max(0,min(100,(int)site_val('hero_focus_y','50'))) ?>%">
      <div class="welcome-card"><span class="weather-icon">☀</span><strong><?= e(site_val('welcome_title','Pagi yang baik, Warga Desa!')) ?></strong><p><?= e(site_val('welcome_text','Bersama membangun desa yang lebih maju dan sejahtera.')) ?></p></div>
      <div class="slider-dots"><span>‹</span><i></i><span>›</span></div>
    </div>
  </section>

  <section class="service-strip" aria-label="Layanan utama">
    <a class="service-card green-card" href="<?= e(app_url('layanan')) ?>"><span class="service-icon">▤</span><h2>Layanan Surat</h2><p>Ajukan berbagai surat keterangan dengan mudah dan cepat.</p><span class="round-arrow">→</span></a>
    <a class="service-card amber-card" href="<?= e(app_url('pengaduan')) ?>"><span class="service-icon">⚑</span><h2>Pengaduan Warga</h2><p>Sampaikan aspirasi, keluhan, atau laporan Anda.</p><span class="round-arrow">→</span></a>
    <a class="service-card blue-card" href="<?= e(app_url('profil')) ?>"><span class="service-icon">ⓘ</span><h2>Informasi Desa</h2><p>Akses informasi seputar program, kegiatan, dan data desa.</p><span class="round-arrow">→</span></a>
    <div class="track-card"><div class="track-heading"><span class="track-icon">◷</span><div><h2>Cek Status Pengajuan</h2><p>Masukkan nomor pengajuan Anda</p></div></div>
      <form method="get" action="index.php" class="track-form"><input type="hidden" name="page" value="track"><label class="sr-only" for="home-track">Kode pengajuan</label><input id="home-track" name="code" placeholder="Contoh: SR-260101-AB12CD34" required maxlength="32"><button type="submit" aria-label="Cek status">⌕</button></form>
    </div>
  </section>

  <section class="news-section">
    <div class="section-heading"><div><h2>Berita & Pengumuman</h2><p>Informasi terbaru seputar kegiatan, program, dan perkembangan desa.</p></div><a href="<?= e(app_url('berita')) ?>" class="see-all">Lihat Semua <span>→</span></a></div>
    <?php if ($news): ?>
      <div class="news-grid">
      <?php foreach (array_slice($news,0,3) as $item): ?>
        <article class="news-card"><a class="news-image" href="<?= e(app_url('article',['slug'=>$item['slug']])) ?>">
          <?php if (!empty($item['image_key'])): ?><img src="<?= e($item['image_key']) ?>" alt="<?= e($item['alt_text'] ?: $item['title']) ?>"><?php else: ?><img src="assets/village-hero.webp" alt="Ilustrasi desa"><?php endif; ?>
          <span class="news-tag"><?= e(strtoupper($item['category'])) ?></span></a>
          <div class="news-content"><time><?= e(date('d M Y', strtotime($item['publish_at'] ?: $item['created_at']))) ?></time><h3><a href="<?= e(app_url('article',['slug'=>$item['slug']])) ?>"><?= e($item['title']) ?></a></h3><a class="news-arrow" href="<?= e(app_url('article',['slug'=>$item['slug']])) ?>" aria-label="Baca berita">→</a></div></article>
      <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-news"><span class="empty-news-icon">▤</span><div><strong>Berita desa akan tampil di sini.</strong><p>Petugas dapat menerbitkan berita dan pengumuman melalui panel pengelolaan.</p></div></div>
    <?php endif; ?>
  </section>
  <section class="stats-band" aria-label="Ringkasan data desa">
    <div class="stat-item"><span class="stat-icon">♧</span><div><strong><?= $stats['residents'] === null ? '—' : number_format($stats['residents'],0,',','.') ?></strong><span>Jumlah Penduduk</span></div></div>
    <div class="stat-item"><span class="stat-icon">⌂</span><div><strong><?= $stats['families'] === null ? '—' : number_format($stats['families'],0,',','.') ?></strong><span>Jumlah KK</span></div></div>
    <div class="stat-item"><span class="stat-icon">▧</span><div><strong><?= e(site_val('stat_area','—')) ?></strong><span>Luas Wilayah</span></div></div>
    <div class="stat-item"><span class="stat-icon">♧</span><div><strong><?= $stats['dusun'] === null ? '—' : number_format($stats['dusun'],0,',','.') ?></strong><span>Dusun</span></div></div>
  </section>

<?php elseif ($page === 'layanan'): ?>
  <section class="page-hero"><span class="eyebrow">Pelayanan Publik</span><h1>Layanan Desa</h1><p>Pilih jenis layanan yang kamu butuhkan. Status pengajuan dapat dipantau dengan kode pelacakan.</p></section>
  <div class="content-layout">
    <section class="panel"><h2>Daftar layanan</h2>
      <?php if ($services): ?><div class="list-cards"><?php foreach ($services as $service): ?><article class="list-card"><span class="service-icon">▤</span><div><h3><?= e($service['name']) ?></h3><p><?= e($service['description'] ?? '') ?></p><?php if (!empty($service['estimated_days'])): ?><small>Estimasi <?= (int)$service['estimated_days'] ?> hari kerja</small><?php endif; ?></div><?php if ($viewer && ($viewer['role'] ?? '')==='warga' && $dbAvailable && (int)$service['id']>0): ?><a class="button button-small button-primary" href="<?= e(app_url('layanan',['type'=>(int)$service['id']])) ?>">Ajukan</a><?php else: ?><a class="button button-small button-outline" href="<?= e(app_url($viewer?'login':'login')) ?>">Masuk untuk ajukan</a><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><p>Belum ada layanan yang diterbitkan.</p><?php endif; ?>
    </section>
    <?php if ($viewer && ($viewer['role'] ?? '')==='warga' && $page==='layanan' && isset($_GET['type'])): ?>
      <?php $typeId=(int)$_GET['type']; $selected=null; foreach($services as $sv){if((int)$sv['id']===$typeId)$selected=$sv;} ?>
      <?php if($selected): ?><section class="panel"><h2>Formulir pengajuan</h2><form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="apply_service"><input type="hidden" name="service_type_id" value="<?= (int)$selected['id'] ?>"><label>Jenis layanan<input value="<?= e($selected['name']) ?>" disabled></label><label>Keperluan / subjek<input name="subject" maxlength="190" required></label><label>Keterangan tambahan<textarea name="details" rows="4" maxlength="4000"></textarea></label><label>Lampiran persyaratan (opsional, maksimal 5 file)<input type="file" name="attachments[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple><small>PDF/JPG/PNG/WebP, maksimal 5 MB per file.</small></label><button class="button button-primary" type="submit">Kirim pengajuan →</button></form></section><?php endif; ?>
    <?php endif; ?>
  </div>

<?php elseif ($page === 'pengaduan'): ?>
  <section class="page-hero"><span class="eyebrow">Suara Warga</span><h1>Pengaduan Warga</h1><p>Sampaikan aspirasi, keluhan, atau laporan. Data pelapor hanya terlihat oleh pihak yang berwenang.</p></section>
  <section class="panel narrow-panel"><h2>Kirim pengaduan</h2>
    <?php if (!$viewer): ?><p>Silakan <a class="text-link" href="<?= e(app_url('login')) ?>">masuk ke akun warga</a> untuk mengirim pengaduan dan melacak tindak lanjutnya.</p>
    <?php elseif (($viewer['role'] ?? '')!=='warga'): ?><p>Akun ini tidak memiliki akses untuk mengirim pengaduan sebagai warga.</p>
    <?php else: ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="submit_complaint"><label>Judul laporan<input name="title" required maxlength="190"></label><label>Kategori<select name="category"><option value="umum">Umum</option><option value="infrastruktur">Infrastruktur</option><option value="pelayanan">Pelayanan</option><option value="lingkungan">Lingkungan</option><option value="sosial">Sosial</option></select></label><label>Detail laporan<textarea name="description" rows="6" maxlength="5000" required></textarea></label><button class="button button-primary" type="submit">Kirim pengaduan →</button></form><?php endif; ?>
  </section>

<?php elseif ($page === 'track'): ?>
  <section class="page-hero"><span class="eyebrow">Transparan & Terpantau</span><h1>Lacak Pengajuan</h1><p>Masukkan kode pelacakan untuk melihat status dan pembaruan yang boleh dibagikan kepada publik.</p></section>
  <section class="panel narrow-panel"><form method="get" class="track-form large-track"><input type="hidden" name="page" value="track"><label class="sr-only" for="track-code">Kode pelacakan</label><input id="track-code" name="code" placeholder="Contoh: SR-260101-AB12CD34" required maxlength="32" value="<?= e((string)($_GET['code'] ?? '')) ?>"><button type="submit">Periksa</button></form>
  <?php if (isset($_GET['code'])): ?><?php if ($tracking): ?><div class="tracking-result"><span class="status-pill <?= e(status_class($tracking['status'])) ?>"><?= e(status_text($tracking['status'])) ?></span><h2><?= e($tracking['title']) ?></h2><p class="muted">Kode: <strong><?= e($tracking['tracking_code']) ?></strong></p><p><?= e($tracking['public_note'] ?: 'Belum ada catatan pembaruan untuk ditampilkan.') ?></p><dl class="detail-list"><div><dt>Dikirim</dt><dd><?= e(date('d M Y H:i',strtotime($tracking['created_at']))) ?></dd></div><div><dt>Pembaruan terakhir</dt><dd><?= e(date('d M Y H:i',strtotime($tracking['updated_at']))) ?></dd></div></dl></div><?php else: ?><p class="flash error inline-flash">Kode tidak ditemukan. Periksa kembali kode pada bukti pengajuan.</p><?php endif; ?><?php endif; ?>
  </section>

<?php elseif ($page === 'berita'): ?>
  <section class="page-hero"><span class="eyebrow">Kabar Desa</span><h1>Berita & Pengumuman</h1><p>Informasi kegiatan, program, dan pengumuman resmi desa.</p></section>
  <?php if ($news): ?><div class="news-grid news-grid-wide"><?php foreach($news as $item): ?><article class="news-card"><a class="news-image" href="<?= e(app_url('article',['slug'=>$item['slug']])) ?>"><?php if(!empty($item['image_key'])): ?><img src="<?= e($item['image_key']) ?>" alt="<?= e($item['alt_text'] ?: $item['title']) ?>"><?php else: ?><img src="assets/village-hero.webp" alt="Ilustrasi desa"><?php endif; ?><span class="news-tag"><?= e(strtoupper($item['category'])) ?></span></a><div class="news-content"><time><?= e(date('d M Y',strtotime($item['publish_at'] ?: $item['created_at']))) ?></time><h3><a href="<?= e(app_url('article',['slug'=>$item['slug']])) ?>"><?= e($item['title']) ?></a></h3><p><?= e($item['excerpt'] ?? '') ?></p></div></article><?php endforeach; ?></div><?php else: ?><div class="empty-news"><div><strong>Belum ada berita yang diterbitkan.</strong><p>Konten akan tampil setelah disetujui dan diterbitkan oleh petugas.</p></div></div><?php endif; ?>

<?php elseif ($page === 'article'): ?>
  <?php if (!$article): ?><section class="page-hero"><h1>Berita tidak ditemukan</h1><p>Berita mungkin sudah diarsipkan atau alamatnya salah.</p><a class="button button-primary" href="<?= e(app_url('berita')) ?>">Kembali ke berita</a></section>
  <?php else: ?><article class="article-page"><?php if(!empty($article['image_key'])): ?><img class="article-cover" src="<?= e($article['image_key']) ?>" alt="<?= e($article['alt_text'] ?: $article['title']) ?>"><?php endif; ?><span class="eyebrow"><?= e(strtoupper($article['category'])) ?></span><h1><?= e($article['title']) ?></h1><p class="muted"><?= e(date('d M Y',strtotime($article['publish_at'] ?: $article['created_at']))) ?></p><?php if($article['excerpt']): ?><p class="article-excerpt"><?= e($article['excerpt']) ?></p><?php endif; ?><div class="article-body"><?= nl2br(e($article['body'])) ?></div><a class="text-link" href="<?= e(app_url('berita')) ?>">← Kembali ke berita</a></article><?php endif; ?>

<?php elseif ($page === 'profil'): ?>
  <section class="page-hero"><span class="eyebrow">Tentang Kami</span><h1>Profil Desa</h1><p>Ruang informasi resmi desa. Identitas, sejarah, struktur pemerintahan, potensi, alamat, dan kontak akan dikelola melalui panel admin.</p></section>
  <section class="profile-grid"><div class="panel"><span class="profile-icon">⌂</span><h2>Profil & sejarah</h2><p><?= e(site_val('profil_sejarah','Informasi profil desa dapat diperbarui oleh admin desa setelah data resmi tersedia.')) ?></p></div><div class="panel"><span class="profile-icon">♧</span><h2>Potensi desa</h2><p><?= e(site_val('profil_potensi','UMKM, wisata, kegiatan masyarakat, dan program unggulan akan ditampilkan setelah konten diterbitkan.')) ?></p></div><div class="panel"><span class="profile-icon">✉</span><h2>Kontak kantor</h2><p>Alamat dan nomor layanan resmi perlu diisi melalui pengaturan website.</p></div></section>

<?php elseif ($page === 'login'): ?>
  <section class="auth-shell"><div class="auth-intro"><span class="eyebrow">Selamat datang kembali</span><h1>Masuk ke<br><em>DESA DIGITAL</em></h1><p>Akses layanan warga atau panel kerja sesuai peran akun kamu.</p><img src="assets/village-hero.webp" alt="" aria-hidden="true"></div><div class="panel auth-panel"><h2>Masuk</h2><p class="muted">Gunakan username dan kata sandi yang terdaftar.</p><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Username<input name="username" autocomplete="username" required maxlength="80"></label><label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label><button class="button button-primary button-full" type="submit">Masuk →</button></form><p class="auth-foot">Belum punya akun warga? <a href="<?= e(app_url('register')) ?>">Daftar di sini</a></p></div></section>

<?php elseif ($page === 'register'): ?>
  <section class="auth-shell"><div class="auth-intro"><span class="eyebrow">Akun warga</span><h1>Layanan desa,<br><em>lebih dekat.</em></h1><p>Buat akun untuk mengajukan layanan dan melacak status permohonan milikmu.</p><img src="assets/village-hero.webp" alt="" aria-hidden="true"></div><div class="panel auth-panel"><h2>Daftar akun warga</h2><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="register"><label>Nama lengkap<input name="full_name" autocomplete="name" required maxlength="160"></label><label>Username<input name="username" autocomplete="username" pattern="[A-Za-z0-9._-]{4,80}" required minlength="4" maxlength="80"></label><label>Email (opsional)<input type="email" name="email" autocomplete="email" maxlength="190"></label><label>Kata sandi<input type="password" name="password" autocomplete="new-password" required minlength="12"><small>Minimal 12 karakter.</small></label><button class="button button-primary button-full" type="submit">Buat akun →</button></form><p class="auth-foot">Sudah punya akun? <a href="<?= e(app_url('login')) ?>">Masuk</a></p></div></section>

<?php elseif ($page === 'change-password'): ?>
  <?php if (!$viewer): ?><section class="page-hero"><h1>Masuk diperlukan</h1><p>Masuk dulu untuk mengganti kata sandi.</p><a class="button button-primary" href="<?= e(app_url('login')) ?>">Masuk</a></section>
  <?php else: ?><section class="page-hero"><span class="eyebrow">Keamanan Akun</span><h1>Ganti kata sandi</h1><p>Untuk melindungi akun dan data warga, kata sandi awal atau hasil reset wajib diganti sebelum menggunakan menu lain.</p></section><section class="panel narrow-panel"><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="change_password"><label>Kata sandi lama<input type="password" name="old_password" autocomplete="current-password" required></label><label>Kata sandi baru<input type="password" name="new_password" minlength="14" autocomplete="new-password" required><small>Minimal 14 karakter.</small></label><label>Ulangi kata sandi baru<input type="password" name="confirm_password" minlength="14" autocomplete="new-password" required></label><button class="button button-primary" type="submit">Simpan kata sandi baru →</button></form><form method="post" class="logout-form"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="button button-outline" type="submit">Keluar</button></form></section><?php endif; ?>

<?php elseif ($page === 'dashboard'): ?>
  <?php if (!$viewer): ?><section class="page-hero"><h1>Dashboard Warga</h1><p>Masuk terlebih dahulu untuk melihat data milik akun.</p><a class="button button-primary" href="<?= e(app_url('login')) ?>">Masuk</a></section>
  <?php else: ?><section class="page-hero dashboard-hero"><span class="eyebrow"><?= e(role_label($viewer['role'])) ?></span><h1>Halo, <?= e($viewer['full_name']) ?></h1><p>Berikut ringkasan aktivitas dan layanan yang terkait dengan akunmu.</p><div class="button-row"><a class="button button-outline" href="<?= e(app_url('notifikasi')) ?>">Notifikasi<?php if($unreadNotificationCount>0): ?> (<?= (int)$unreadNotificationCount ?> belum dibaca)<?php endif; ?></a><?php if(in_array($viewer['role'],['petugas','admin_desa','kepala_desa'],true)): ?><a class="button button-primary" href="<?= e(app_url('admin')) ?>">Buka panel pengelolaan →</a><?php endif; ?></div></section>
  <?php if(($viewer['role'] ?? '')==='warga'): ?><section class="panel"><div class="section-heading compact-heading"><div><h2>Pengajuan saya</h2><p>Nomor pelacakan tetap sama selama proses revisi.</p></div><a class="button button-small button-primary" href="<?= e(app_url('layanan')) ?>">Ajukan surat</a></div><?php if($requests): ?><div class="table-wrap"><table><thead><tr><th>Kode</th><th>Layanan</th><th>Status</th><th>Dibuat</th><th>Catatan</th></tr></thead><tbody><?php foreach($requests as $row): ?><tr><td><strong><?= e($row['tracking_code']) ?></strong></td><td><?= e($row['service_name']) ?><small><?= e($row['subject']) ?></small><?php foreach (service_request_files_for_display($pdo,(int)$row['id']) as $requestFile): ?><small><a class="text-link" href="<?= e(app_url('service-file',['id'=>(int)$requestFile['id']])) ?>">Lampiran: <?= e($requestFile['original_name']) ?></a></small><?php endforeach; ?></td><td><span class="status-pill <?= e(status_class($row['status'])) ?>"><?= e(status_text($row['status'])) ?></span></td><td><?= e(date('d M Y',strtotime($row['created_at']))) ?></td><td><?= e($row['public_status_note'] ?: '—') ?><?php if($row['status']==='revision_requested'): ?><details class="revise-details"><summary>Kirim revisi</summary><form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="revise_service"><input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>"><label>Keperluan<input name="subject" value="<?= e($row['subject']) ?>" required maxlength="190"></label><label>Keterangan<textarea name="details" rows="3"><?= e((string)((json_decode($row['request_data'],true)['details'] ?? '') ?: '')) ?></textarea></label><label>Lampiran tambahan (opsional, maksimal 5 file)<input type="file" name="attachments[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple><small>PDF/JPG/PNG/WebP, maksimal 5 MB per file.</small></label><button class="button button-small button-primary" type="submit">Kirim revisi</button></form></details><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>Belum ada pengajuan. Pilih jenis surat untuk memulai.</p><?php endif; ?></section>
  <section class="panel"><div class="section-heading compact-heading"><div><h2>Pengaduan saya</h2><p>Pelacakan pengaduan tanpa menampilkan isi laporan ke publik.</p></div><a class="button button-small button-outline" href="<?= e(app_url('pengaduan')) ?>">Buat pengaduan</a></div><?php if($complaints): ?><div class="table-wrap"><table><thead><tr><th>Kode</th><th>Judul</th><th>Status</th><th>Dibuat</th></tr></thead><tbody><?php foreach($complaints as $row): ?><tr><td><strong><?= e($row['tracking_code']) ?></strong></td><td><?= e($row['title']) ?></td><td><span class="status-pill <?= e(status_class($row['status'])) ?>"><?= e(status_text($row['status'])) ?></span></td><td><?= e(date('d M Y',strtotime($row['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>Belum ada pengaduan.</p><?php endif; ?></section>
  <?php else: ?><section class="panel"><h2>Ruang kerja <?= e(role_label($viewer['role'])) ?></h2><p>Hak akses server mengikuti wilayah dan penugasan akun. Gunakan pintasan sesuai peran untuk mengelola data dan menindaklanjuti pekerjaan.</p><div class="button-row"><?php if($viewer['role']==='rt'): ?><a class="button button-primary" href="<?= e(app_url('rt-data')) ?>">Data Warga</a><a class="button button-outline" href="<?= e(app_url('dokumen')) ?>">Dokumen Warga</a><a class="button button-outline" href="<?= e(app_url('perubahan')) ?>">Antrean Verifikasi</a><a class="button button-outline" href="<?= e(app_url('laporan')) ?>">Laporan RT</a><?php elseif($viewer['role']==='rw'): ?><a class="button button-primary" href="<?= e(app_url('rw-rekap')) ?>">Rekap RW</a><a class="button button-outline" href="<?= e(app_url('laporan')) ?>">Laporan RW</a><?php elseif(in_array($viewer['role'],['petugas','admin_desa'],true)): ?><a class="button button-primary" href="<?= e(app_url('perubahan')) ?>">Antrean Verifikasi</a><a class="button button-outline" href="<?= e(app_url('dokumen')) ?>">Dokumen Warga</a><a class="button button-outline" href="<?= e(app_url('laporan')) ?>">Laporan</a><?php elseif($viewer['role']==='kepala_desa'): ?><a class="button button-primary" href="<?= e(app_url('laporan')) ?>">Laporan Desa</a><a class="button button-outline" href="<?= e(app_url('admin')) ?>">Panel Pengelolaan</a><?php endif; ?><a class="button button-outline" href="<?= e(app_url('notifikasi')) ?>">Notifikasi</a></div><?php if(in_array($viewer['role'],['rt','rw'],true)): ?><p class="muted">Wilayah tugas: <?= $viewer['rt_id'] ? 'RT terpilih' : ($viewer['rw_id'] ? 'RW terpilih' : 'Belum ditetapkan') ?>. Minta admin desa memeriksa penugasan jika belum benar.</p><?php endif; ?></section><?php endif; ?>
  <?php endif; ?>

<?php elseif ($page === 'admin'): ?>
  <?php if (!$viewer || !in_array($viewer['role'],['petugas','admin_desa','kepala_desa'],true)): ?><section class="page-hero"><h1>Akses terbatas</h1><p>Halaman pengelolaan hanya untuk petugas yang memiliki izin.</p><a class="button button-primary" href="<?= e(app_url('login')) ?>">Masuk</a></section>
  <?php else: ?><section class="page-hero dashboard-hero"><span class="eyebrow">Ruang Kerja</span><h1>Panel Pengelolaan</h1><p>Kelola antrean layanan dan konten publik sesuai kewenangan akun.</p></section>
  <section class="panel"><div class="section-heading compact-heading"><div><h2>Antrean pengajuan surat</h2><p>Keputusan akhir disediakan hanya untuk admin desa atau kepala desa.</p></div></div><?php if($adminRequests): ?><div class="table-wrap"><table><thead><tr><th>Pengajuan</th><th>Pemohon</th><th>Status</th><th>Diajukan</th><th>Perbarui status</th></tr></thead><tbody><?php foreach($adminRequests as $row): ?><tr><td><strong><?= e($row['tracking_code']) ?></strong><small><?= e($row['service_name']) ?> · <?= e($row['subject']) ?></small><?php foreach (service_request_files_for_display($pdo,(int)$row['id']) as $requestFile): ?><small><a class="text-link" href="<?= e(app_url('service-file',['id'=>(int)$requestFile['id']])) ?>">Lampiran: <?= e($requestFile['original_name']) ?></a></small><?php endforeach; ?></td><td><?= e($row['applicant_name']) ?></td><td><span class="status-pill <?= e(status_class($row['status'])) ?>"><?= e(status_text($row['status'])) ?></span></td><td><?= e(date('d M Y',strtotime($row['created_at']))) ?></td><td><form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_request_status"><input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>"><select name="status" aria-label="Status baru"><?php foreach(['in_review','revision_requested','approved','rejected','ready','completed'] as $st): if(!module_service_status_transition_allowed((string)$row['status'],$st)) continue; if(in_array($st,['approved','rejected','ready','completed'],true) && !in_array(($viewer['role'] ?? ''),['admin_desa','kepala_desa'],true)) continue; ?><option value="<?= e($st) ?>"><?= e(status_text($st)) ?></option><?php endforeach; ?></select><input name="public_status_note" placeholder="Catatan untuk warga" maxlength="500"><input name="internal_note" placeholder="Catatan internal" maxlength="1000"><button class="button button-small button-primary" type="submit">Simpan</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>Belum ada pengajuan dalam antrean.</p><?php endif; ?></section>
  <section class="panel"><h2>Berita & pengumuman</h2><p>Setiap berita bisa disimpan sebagai draf, diterbitkan, atau dijadwalkan dengan batas tayang. Jangan unggah dokumen identitas atau data pribadi warga ke media publik.</p><?php if(can_manage_content($viewer)): ?>
    <form method="post" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_news"><input type="hidden" name="news_id" value="<?= (int)($editingNews['id'] ?? 0) ?>">
      <label class="full-span">Judul berita<input name="title" required maxlength="220" value="<?= e($editingNews['title'] ?? '') ?>"></label>
      <label>Kategori<select name="category"><?php foreach(['kegiatan'=>'Kegiatan','pengumuman'=>'Pengumuman','program'=>'Program','berita'=>'Berita'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= (($editingNews['category'] ?? 'berita')===$key?' selected':'') ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>Status<select name="status"><?php foreach(['draft'=>'Simpan draf','published'=>'Terbitkan/jadwalkan','archived'=>'Arsipkan'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= (($editingNews['status'] ?? 'draft')===$key?' selected':'') ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="full-span">Ringkasan<input name="excerpt" maxlength="500" value="<?= e($editingNews['excerpt'] ?? '') ?>"></label>
      <label class="full-span">Isi berita<textarea name="body" rows="5" required maxlength="30000"><?= e($editingNews['body'] ?? '') ?></textarea></label>
      <?php if(!empty($editingNews['image_key'])): ?><div class="full-span"><small>Foto saat ini: <?= e($editingNews['image_key']) ?></small></div><?php endif; ?>
      <label>Foto baru (JPG/PNG/WebP, maks. 5 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
      <label>Teks alternatif foto<input name="alt_text" maxlength="255" value="<?= e($editingNews['alt_text'] ?? '') ?>"></label>
      <label>Mulai tayang (waktu WIB)<input type="datetime-local" name="publish_at" value="<?= e(utc_datetime_to_local_input($editingNews['publish_at'] ?? null)) ?>"><small>Kosong saat diterbitkan berarti mulai sekarang.</small></label>
      <label>Selesai tayang (opsional, WIB)<input type="datetime-local" name="expire_at" value="<?= e(utc_datetime_to_local_input($editingNews['expire_at'] ?? null)) ?>"></label>
      <div class="full-span button-row"><button class="button button-primary" type="submit"><?= $editingNews ? 'Simpan perubahan berita →' : 'Simpan berita →' ?></button><?php if($editingNews): ?><a class="button button-outline" href="<?= e(app_url('admin')) ?>">Batal edit</a><?php endif; ?></div>
    </form>
    <?php else: ?><p>Akun kepala desa hanya dapat mengakses fungsi sesuai kewenangan yang ditetapkan.</p><?php endif; ?>
    <?php if($adminNews): ?><div class="table-wrap admin-news-table"><table><thead><tr><th>Berita</th><th>Status & jadwal</th><th>Aksi</th></tr></thead><tbody><?php foreach($adminNews as $row): ?><tr><td><strong><?= e($row['title']) ?></strong><small><?= e($row['category']) ?> · Dibuat <?= e(utc_datetime_to_local_display($row['created_at'])) ?></small></td><td><span class="status-pill <?= e(status_class($row['status'])) ?>"><?= e(status_text($row['status'])) ?></span><small>Mulai: <?= e(utc_datetime_to_local_display($row['publish_at'])) ?></small><small>Selesai: <?= e(utc_datetime_to_local_display($row['expire_at'])) ?></small></td><td><?php if(can_manage_content($viewer)): ?><div class="action-links"><a class="button button-small button-outline" href="<?= e(app_url('admin',['edit_news'=>(int)$row['id']])) ?>">Edit</a><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_news_status"><input type="hidden" name="news_id" value="<?= (int)$row['id'] ?>"><select name="status"><option value="published">Terbitkan</option><option value="draft">Jadikan draf</option><option value="archived">Arsipkan</option></select><button class="button button-small button-outline" type="submit">Ubah</button></form></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>Belum ada berita atau pengumuman dalam pengelolaan.</p><?php endif; ?></section>
  <section class="panel"><h2>Pengaduan warga</h2><?php if($adminComplaints): ?><div class="table-wrap"><table><thead><tr><th>Kode</th><th>Pelapor</th><th>Judul</th><th>Status</th><th>Waktu</th></tr></thead><tbody><?php foreach($adminComplaints as $row): ?><tr><td><?= e($row['tracking_code']) ?></td><td><?= e($row['reporter_name']) ?></td><td><?= e($row['title']) ?></td><td><span class="status-pill <?= e(status_class($row['status'])) ?>"><?= e(status_text($row['status'])) ?></span></td><td><?= e(date('d M Y',strtotime($row['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>Belum ada pengaduan masuk.</p><?php endif; ?></section>
  <?php endif; ?>

<?php elseif (in_array($page, ['rt-data','rw-rekap','dokumen','perubahan','admin-users','admin-wilayah','admin-site','admin-services','admin-directory','admin-budget','laporan','notifikasi','umkm','peta','transparansi','kontak'], true)): ?>
  <?php require __DIR__ . '/app/module_views.php'; ?>
<?php else: ?>
  <section class="page-hero"><span class="eyebrow">DESA DIGITAL</span><h1>Halaman tidak ditemukan</h1><p>Alamat yang kamu buka tidak tersedia.</p><a class="button button-primary" href="<?= e(app_url('home')) ?>">Kembali ke beranda</a></section>
<?php endif; ?>
</main>

<footer class="site-footer"><div class="footer-main"><a class="brand footer-brand" href="<?= e(app_url('home')) ?>"><span class="brand-mark" aria-hidden="true">◆</span><span><strong>DESA</strong> DIGITAL</span></a><p>Layanan desa, kini lebih dekat.<br>Informasi publik dan pelayanan warga dalam satu tempat.</p><nav aria-label="Navigasi footer"><a href="<?= e(app_url('profil')) ?>">Profil Desa</a><a href="<?= e(app_url('layanan')) ?>">Layanan Surat</a><a href="<?= e(app_url('pengaduan')) ?>">Pengaduan</a><a href="<?= e(app_url('track')) ?>">Lacak Pengajuan</a></nav></div><div class="footer-bottom"><span>© <?= date('Y') ?> DESA DIGITAL</span><span>Pelayanan publik yang mudah, cepat, dan transparan.</span></div></footer>

<nav class="mobile-drawer" id="mobile-drawer" hidden aria-label="Menu tambahan">
  <a href="<?= e(app_url('profil')) ?>">Profil Desa</a><a href="<?= e(app_url('umkm')) ?>">UMKM & Wisata</a><a href="<?= e(app_url('peta')) ?>">Peta Desa</a><a href="<?= e(app_url('transparansi')) ?>">Transparansi Anggaran</a><a href="<?= e(app_url('kontak')) ?>">Kontak</a>
</nav>
<?php if ($viewer): ?><nav class="mobile-bottom-nav" aria-label="Navigasi mobile"><a href="<?= e(app_url('home')) ?>"><span>⌂</span>Beranda</a><a href="<?= e(app_url('layanan')) ?>"><span>▤</span>Layanan</a><a href="<?= e(app_url('berita')) ?>"><span>▦</span>Berita</a><a href="<?= e(app_url('pengaduan')) ?>"><span>⚑</span>Pengaduan</a><a href="<?= e(app_url('dashboard')) ?>"><span>♙</span>Profil</a></nav><?php else: ?><nav class="mobile-bottom-nav" aria-label="Navigasi mobile"><a href="<?= e(app_url('home')) ?>"><span>⌂</span>Beranda</a><a href="<?= e(app_url('layanan')) ?>"><span>▤</span>Layanan</a><a href="<?= e(app_url('berita')) ?>"><span>▦</span>Berita</a><a href="<?= e(app_url('pengaduan')) ?>"><span>⚑</span>Pengaduan</a><a href="<?= e(app_url('login')) ?>"><span>♙</span>Masuk</a></nav><?php endif; ?>

<script src="assets/app.js" defer></script>
</body>
</html>
