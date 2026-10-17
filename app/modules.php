<?php
declare(strict_types=1);

/**
 * Server-side modules for territory, residents, document review, CMS and exports.
 * Every POST route is namespaced with module_ to keep the public form dispatcher separate.
 */
function module_require_role(array $roles): array {
    return require_roles($roles);
}

function module_is_allowed_rt(array $user, int $rtId): bool {
    if ($rtId < 1) return false;
    $role=(string)($user['role'] ?? '');
    if ($role === 'rt') {
        if ((int)($user['rt_id'] ?? 0) !== $rtId) return false;
        $stmt=db()->prepare('SELECT rt.id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.is_active=1 AND rw.is_active=1 LIMIT 1');
        $stmt->execute([$rtId]);
        return (bool)$stmt->fetchColumn();
    }
    if ($role === 'rw') {
        $stmt = db()->prepare('SELECT rt.id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.rw_id=? AND rt.is_active=1 AND rw.is_active=1 LIMIT 1');
        $stmt->execute([$rtId, (int)($user['rw_id'] ?? 0)]);
        return (bool)$stmt->fetchColumn();
    }
    if (in_array($role, ['petugas','admin_desa','owner','kepala_desa'], true)) {
        $stmt=db()->prepare('SELECT rt.id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.is_active=1 AND rw.is_active=1 LIMIT 1');
        $stmt->execute([$rtId]);
        return (bool)$stmt->fetchColumn();
    }
    return false;
}

function module_resident_scope(array $user, int $residentId): array {
    $stmt = db()->prepare('SELECT r.*, f.family_number, f.address, f.status AS family_status, f.rt_id AS family_rt_id
        FROM residents r JOIN family_records f ON f.id=r.family_id WHERE r.id=? LIMIT 1');
    $stmt->execute([$residentId]);
    $resident = $stmt->fetch();
    if (!$resident || !module_is_allowed_rt($user, (int)$resident['rt_id'])) {
        throw new RuntimeException('Data tidak ditemukan atau berada di luar wilayah tugas akun ini.');
    }
    return $resident;
}

/**
 * Return an opaque, keyed fingerprint of the source resident/family state.
 * PII is hashed before the snapshot is stored on a change request. Call with
 * $forUpdate=true only inside a transaction; household row locks prevent a
 * simultaneous edit from racing the approval check.
 */
function module_change_snapshot(int $residentId, int $familyId, string $changeType, bool $forUpdate = false): array {
    if ($residentId < 1 || $familyId < 1) throw new RuntimeException('Data sumber usulan tidak valid.');
    $pdo = db();
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT id,family_number_hash,rt_id,address,status FROM family_records WHERE id=? LIMIT 1' . $lock);
    $stmt->execute([$familyId]);
    $family = $stmt->fetch();
    if (!$family) throw new RuntimeException('Data keluarga sumber tidak ditemukan.');

    $stmt = $pdo->prepare('SELECT id,family_id,rt_id,nik_hash,full_name,birth_date,gender,phone,relationship_to_head,resident_status FROM residents WHERE family_id=? ORDER BY id' . $lock);
    $stmt->execute([$familyId]);
    $members = $stmt->fetchAll();
    $resident = null;
    foreach ($members as $member) if ((int)$member['id'] === $residentId) { $resident = $member; break; }
    if (!$resident) throw new RuntimeException('Data penduduk sumber tidak ditemukan dalam keluarga yang ditentukan.');

    $key = private_identifier_key('change-snapshot');
    $encode = static fn(array $data): string => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $snapshot = [
        'version' => 1,
        'resident_id' => $residentId,
        'family_id' => $familyId,
        'resident_rt_id' => (int)$resident['rt_id'],
        'family_rt_id' => (int)$family['rt_id'],
        'resident_digest' => hash_hmac('sha256', $encode($resident), $key),
        'family_digest' => hash_hmac('sha256', $encode($family), $key),
    ];
    // Detects concurrent household changes (including a newly added family member)
    // for corrections as well as whole-household RT moves.
    $snapshot['household_digest'] = hash_hmac('sha256', $encode(array_values($members)), $key);
    return $snapshot;
}

function module_change_snapshot_matches(array $request): bool {
    $source = json_decode((string)($request['source_snapshot'] ?? ''), true);
    if (!is_array($source)
        || (int)($source['version'] ?? 0) !== 1
        || (int)($source['resident_id'] ?? 0) !== (int)($request['resident_id'] ?? 0)
        || (int)($source['family_id'] ?? 0) !== (int)($request['family_id'] ?? 0)
        || empty($source['resident_digest'])
        || empty($source['family_digest'])) return false;

    $current = module_change_snapshot(
        (int)$request['resident_id'],
        (int)$request['family_id'],
        (string)$request['change_type'],
        true
    );
    if (!hash_equals((string)$source['resident_digest'], (string)$current['resident_digest'])
        || !hash_equals((string)$source['family_digest'], (string)$current['family_digest'])) return false;
    if (!isset($source['household_digest'])
        || !hash_equals((string)$source['household_digest'], (string)($current['household_digest'] ?? ''))) return false;
    return true;
}

function module_upload_private_file(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Pilih file yang akan diunggah terlebih dahulu.');
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('File unggahan tidak valid.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran dokumen maksimal 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format dokumen harus PDF, JPG, PNG, atau WebP.');
    }
    $directory = dirname(__DIR__) . '/storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Penyimpanan dokumen privat tidak tersedia.');
    }
    $key = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $key)) {
        throw new RuntimeException('Dokumen gagal disimpan.');
    }
    return [
        'key' => $key,
        'name' => mb_substr(basename((string)($file['name'] ?? 'dokumen')), 0, 190),
        'mime' => $mime,
        'size' => $size,
        'sha256' => hash_file('sha256', $directory . '/' . $key),
    ];
}

function module_normalize_import_header(string $header): string {
    $header = preg_replace('/^\\xEF\\xBB\\xBF/', '', trim($header)) ?? trim($header);
    $value = strtolower($header);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    $value = trim($value, '_');
    $aliases = [
        'kk'=>'family_number','no_kk'=>'family_number','nomor_kk'=>'family_number','no_kartu_keluarga'=>'family_number','nomor_kartu_keluarga'=>'family_number','family_card_number'=>'family_number',
        'no_nik'=>'nik','nomor_nik'=>'nik','nomor_induk_kependudukan'=>'nik',
        'nama'=>'full_name','nama_lengkap'=>'full_name','name'=>'full_name',
        'tanggal_lahir'=>'birth_date','tgl_lahir'=>'birth_date','date_of_birth'=>'birth_date',
        'jenis_kelamin'=>'gender','kelamin'=>'gender',
        'alamat'=>'address','alamat_lengkap'=>'address',
        'no_hp'=>'phone','nomor_hp'=>'phone','telepon'=>'phone','nomor_telepon'=>'phone',
        'hubungan'=>'relationship_to_head','hubungan_keluarga'=>'relationship_to_head'
    ];
    return $aliases[$value] ?? $value;
}

function module_parse_import_date(string $value, int $line): ?string {
    $value=trim($value);
    if($value==='') return null;
    foreach(['!Y-m-d','!d/m/Y','!d-m-Y'] as $format) {
        $date=DateTimeImmutable::createFromFormat($format,$value,new DateTimeZone('Asia/Jakarta'));
        $errors=DateTimeImmutable::getLastErrors();
        if($date && (!is_array($errors) || ($errors['warning_count']===0 && $errors['error_count']===0))) {
            return $date->format('Y-m-d');
        }
    }
    throw new RuntimeException("Baris {$line}: tanggal lahir harus YYYY-MM-DD atau DD/MM/YYYY.");
}

function module_read_resident_csv(string $path): array {
    $handle=fopen($path,'rb');
    if(!$handle) throw new RuntimeException('File CSV tidak dapat dibaca.');
    try {
        $first=fgets($handle);
        if($first===false) throw new RuntimeException('File CSV kosong.');
        $delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
        rewind($handle);
        $rawHeaders=fgetcsv($handle,0,$delimiter,'"','');
        if(!$rawHeaders || count($rawHeaders)<3) throw new RuntimeException('Header CSV tidak valid. Unduh template untuk melihat kolom wajib.');
        $headers=[];
        foreach($rawHeaders as $header) $headers[]=module_normalize_import_header((string)$header);
        $required=['family_number','nik','full_name','address'];
        foreach($required as $name) if(!in_array($name,$headers,true)) throw new RuntimeException('Kolom wajib tidak ditemukan: '.$name.'. Gunakan header nomor_kk; nik; nama_lengkap; alamat.');
        $rows=[];$errors=[];$line=1;$seenNiks=[];
        while(($fields=fgetcsv($handle,0,$delimiter,'"',''))!==false) {
            $line++;
            if($line>1001) throw new RuntimeException('Maksimal 1.000 baris warga per impor. Bagi file menjadi beberapa bagian.');
            if(count($fields)===1 && trim((string)$fields[0])==='') continue;
            if(count($fields)!==count($headers)) { $errors[]="Baris {$line}: jumlah kolom tidak sesuai header."; continue; }
            $data=array_combine($headers,$fields);
            $data=array_map(static fn($value)=>trim((string)$value),$data);
            $family=preg_replace('/\\D/','',$data['family_number']??'') ?? '';
            $nik=preg_replace('/\\D/','',$data['nik']??'') ?? '';
            $name=trim((string)($data['full_name']??''));
            $address=trim((string)($data['address']??''));
            if(!preg_match('/^[0-9]{16}$/',$family)) { $errors[]="Baris {$line}: nomor KK harus 16 digit."; continue; }
            if(!preg_match('/^[0-9]{16}$/',$nik)) { $errors[]="Baris {$line}: NIK harus 16 digit."; continue; }
            if(mb_strlen($name)<3 || mb_strlen($name)>160) { $errors[]="Baris {$line}: nama tidak valid."; continue; }
            if(mb_strlen($address)<5 || mb_strlen($address)>1000) { $errors[]="Baris {$line}: alamat tidak valid."; continue; }
            if(isset($seenNiks[$nik])) { $errors[]="Baris {$line}: NIK duplikat di dalam file impor (lihat juga baris {$seenNiks[$nik]})."; continue; }
            $seenNiks[$nik]=$line;
            $gender=strtoupper(trim((string)($data['gender']??'unknown')));
            if(in_array($gender,['LAKI-LAKI','LAKI LAKI','LAKI','PRIA','MALE'],true)) $gender='L';
            if(in_array($gender,['PEREMPUAN','WANITA','FEMALE'],true)) $gender='P';
            if(!in_array($gender,['L','P','UNKNOWN',''],true)) { $errors[]="Baris {$line}: jenis kelamin gunakan L, P, atau kosong."; continue; }
            if($gender==='') $gender='unknown';
            if($gender==='UNKNOWN') $gender='unknown';
            try { $birth=module_parse_import_date((string)($data['birth_date']??''),$line); }
            catch(RuntimeException $e) { $errors[]=$e->getMessage(); continue; }
            $rows[]=[
                'line'=>$line,'family_number'=>$family,'nik'=>$nik,'full_name'=>$name,
                'address'=>$address,'birth_date'=>$birth,'gender'=>$gender,
                'phone'=>mb_substr(trim((string)($data['phone']??'')),0,30),
                'relationship_to_head'=>mb_substr(trim((string)($data['relationship_to_head']??'')),0,60),
            ];
        }
        if(!$rows && !$errors) throw new RuntimeException('File CSV tidak memiliki baris data.');
        return ['rows'=>$rows,'errors'=>$errors];
    } finally {
        fclose($handle);
    }
}

function module_rt_options(): array {
    return db()->query('SELECT rt.id,rt.number_code,rw.number_code AS rw_code,d.name AS dusun_name
        FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id
        WHERE rt.is_active=1 AND rw.is_active=1 ORDER BY d.name,rw.number_code,rt.number_code')->fetchAll();
}

function module_audit_actor(array $user, string $action, string $entity, int|string|null $id, ?array $before=null, ?array $after=null): void {
    audit_log((int)$user['id'], $action, $entity, $id === null ? null : (string)$id, $before, $after);
}

function module_create_rt_account(array $user): never {
    if (!in_array(($user['role'] ?? ''), ['admin_desa','owner'], true)) throw new RuntimeException('Hanya Admin Desa atau Owner yang boleh membuat akun RT.');
    $rwId = filter_input(INPUT_POST, 'rw_id', FILTER_VALIDATE_INT);
    $rtCode = trim((string)($_POST['rt_code'] ?? ''));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $name = trim((string)($_POST['full_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!$rwId || !preg_match('/^[A-Za-z0-9-]{1,10}$/', $rtCode)) throw new RuntimeException('Pilih RW dan isi nomor RT dengan benar.');
    if (!preg_match('/^[a-z0-9._-]{4,80}$/', $username)) throw new RuntimeException('Username harus 4–80 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
    if (mb_strlen($name) < 3 || mb_strlen($name) > 160 || strlen($password) < 14) throw new RuntimeException('Nama wajib diisi dan kata sandi awal minimal 14 karakter.');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT rw.id,rw.number_code,d.name AS dusun_name FROM rw_units rw LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE rw.id=? AND rw.is_active=1');
    $stmt->execute([$rwId]);
    $rw = $stmt->fetch();
    if (!$rw) throw new RuntimeException('Wilayah RW tidak tersedia.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM rt_units WHERE rw_id=? AND number_code=? LIMIT 1');
        $stmt->execute([$rwId,$rtCode]);
        $rtId = (int)($stmt->fetchColumn() ?: 0);
        if (!$rtId) {
            $stmt = $pdo->prepare('INSERT INTO rt_units (rw_id,number_code) VALUES (?,?)');
            $stmt->execute([$rwId,$rtCode]);
            $rtId = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("SELECT id FROM app_users WHERE role='rt' AND rt_id=? AND is_active=1 LIMIT 1");
            $stmt->execute([$rtId]);
            if ($stmt->fetchColumn()) throw new RuntimeException('RT ini sudah memiliki akun aktif. Nonaktifkan akun lama jika sedang pergantian pengurus.');
        }
        $stmt = $pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role,rt_id,rw_id,created_by,must_change_password) VALUES (?,?,?,'rt',?,?,?,1)");
        $stmt->execute([$username,$name,password_hash($password,PASSWORD_DEFAULT),$rtId,$rwId,(int)$user['id']]);
        $accountId = (int)$pdo->lastInsertId();
        module_audit_actor($user,'create','rt_account',$accountId,null,['rt_id'=>$rtId,'rw_id'=>$rwId,'username'=>$username]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    flash("Akun Ketua RT {$rtCode} berhasil dibuat. Minta pengurus mengganti kata sandi setelah masuk.");
    redirect_to('admin-users');
}

function module_site_image_keys(): array {
    return ['hero_image','logo_image','login_image','register_image','news_fallback_image'];
}
function module_site_image_value_allowed(string $key,string $value): bool {
    if(!in_array($key,module_site_image_keys(),true)) return false;
    if($value==='') return true;
    $defaults=['hero_image'=>['assets/village-hero.svg','assets/village-hero.webp'],'logo_image'=>[],
        'login_image'=>['assets/village-hero.svg','assets/village-hero.webp'],'register_image'=>['assets/village-hero.svg','assets/village-hero.webp'],
        'news_fallback_image'=>['assets/village-hero.svg','assets/village-hero.webp']];
    return in_array($value,$defaults[$key],true)||(bool)preg_match('#^assets/uploads/[a-f0-9]{36}\\.(jpg|png|webp)$#',$value);
}
function module_upload_public_pdf(string $field): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file=$_FILES[$field];
    if(($file['error'] ?? UPLOAD_ERR_OK)!==UPLOAD_ERR_OK || (int)$file['size']>10*1024*1024) throw new RuntimeException('PDF gagal diunggah atau melebihi 10 MB.');
    if(!is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('File PDF tidak valid.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if($mime!=='application/pdf') throw new RuntimeException('Dokumen transparansi harus berupa PDF yang valid.');
    $dir=dirname(__DIR__).'/assets/uploads';
    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Penyimpanan media publik tidak tersedia.');
    $name=bin2hex(random_bytes(18)).'.pdf';
    if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('PDF gagal disimpan.');
    return 'assets/uploads/'.$name;
}

function module_csv_safe(string $value): string {
    $value = str_replace(["\0","\r"], '', $value);
    if (preg_match('/^[\t ]*[=+@-]/', $value)) return "'" . $value;
    return $value;
}

function module_handle_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $action = $_POST['action'] ?? '';
    if (!is_string($action) || !str_starts_with($action, 'module_')) return;
    verify_csrf();
    $pdo = null;
    try {
        $pdo = db();
        $user = require_login();
        switch ($action) {
            case 'module_mark_notification_read': {
                $user=module_require_role(['warga','rt','rw','petugas','admin_desa','owner','kepala_desa']);
                $notificationId=filter_input(INPUT_POST,'notification_id',FILTER_VALIDATE_INT);
                if(!$notificationId) throw new RuntimeException('Notifikasi tidak valid.');
                $stmt=$pdo->prepare('UPDATE user_notifications SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL');
                $stmt->execute([(int)$notificationId,(int)$user['id']]);
                $wasUpdated=$stmt->rowCount()>0;
                if($wasUpdated) module_audit_actor($user,'read','user_notifications',(int)$notificationId,null,['read'=>true]);
                $returnPage=(string)($_POST['return_page'] ?? 'notifikasi');
                if(!in_array($returnPage,['notifikasi','dashboard'],true)) $returnPage='notifikasi';
                flash($wasUpdated?'Notifikasi ditandai sudah dibaca.':'Notifikasi sudah dibaca atau tidak tersedia.');
                redirect_to($returnPage);
            }
            case 'module_create_dusun': {
                $user = module_require_role(['admin_desa','owner']);
                $name = trim((string)($_POST['name'] ?? ''));
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                if (mb_strlen($name)<2 || mb_strlen($name)>100 || !preg_match('/^[A-Z0-9-]{2,20}$/',$code)) throw new RuntimeException('Nama dusun atau kode tidak valid.');
                $stmt=$pdo->prepare('INSERT INTO dusun (name,code) VALUES (?,?)');
                $stmt->execute([$name,$code]); $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','dusun',$id,null,['name'=>$name,'code'=>$code]);
                flash('Dusun berhasil ditambahkan.'); redirect_to('admin-wilayah');
            }
            case 'module_create_rw': {
                $user = module_require_role(['admin_desa','owner']);
                $dusunId = filter_input(INPUT_POST,'dusun_id',FILTER_VALIDATE_INT);
                $code = trim((string)($_POST['number_code'] ?? ''));
                if (!$dusunId || !preg_match('/^[A-Za-z0-9-]{1,10}$/',$code)) throw new RuntimeException('Pilih dusun dan isi nomor RW.');
                $stmt=$pdo->prepare('INSERT INTO rw_units (dusun_id,number_code) VALUES (?,?)');
                $stmt->execute([$dusunId,$code]); $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','rw_units',$id,null,['dusun_id'=>$dusunId,'number_code'=>$code]);
                flash('RW berhasil ditambahkan.'); redirect_to('admin-wilayah');
            }
            case 'module_create_rt_account':
                module_create_rt_account(module_require_role(['admin_desa','owner']));
            case 'module_update_rt_user': {
                $user = module_require_role(['admin_desa','owner']);
                $id=filter_input(INPUT_POST,'user_id',FILTER_VALIDATE_INT);
                $operation=(string)($_POST['operation'] ?? '');
                $managedAccountRoles = ($user['role'] ?? '') === 'owner' ? "'rt','rw','petugas','kepala_desa','admin_desa'" : "'rt','rw','petugas','kepala_desa'";
                $stmt=$pdo->prepare("SELECT id,username,full_name,role,rt_id,rw_id,is_active,session_version FROM app_users WHERE id=? AND role IN ({$managedAccountRoles}) LIMIT 1");
                $stmt->execute([$id]); $before=$stmt->fetch();
                if (!$before) throw new RuntimeException('Akun wilayah/petugas tidak ditemukan.');
                $entityType=match($before['role']) { 'rt'=>'rt_account','rw'=>'rw_account','petugas'=>'petugas_account','kepala_desa'=>'kepala_desa_account','admin_desa'=>'admin_account', default=>'managed_account' };
                if ($operation==='deactivate' || $operation==='activate') {
                    $active=$operation==='activate'?1:0;
                    $pdo->prepare('UPDATE app_users SET is_active=?,session_version=session_version+1 WHERE id=?')->execute([$active,$id]);
                    module_audit_actor($user,$operation,$entityType,(int)$id,['is_active'=>(int)$before['is_active']],['is_active'=>$active]);
                    flash($active?'Akun wilayah diaktifkan kembali.':'Akun wilayah dinonaktifkan; sesi lama tidak berlaku lagi.');
                } elseif ($operation==='reset_password') {
                    $password=(string)($_POST['new_password'] ?? '');
                    if (strlen($password)<14) throw new RuntimeException('Kata sandi baru minimal 14 karakter.');
                    $pdo->prepare('UPDATE app_users SET password_hash=?,must_change_password=1,session_version=session_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
                    module_audit_actor($user,'reset_password',$entityType,(int)$id,['session_version'=>(int)$before['session_version']],['session_version'=>(int)$before['session_version']+1]);
                    flash('Kata sandi direset dan sesi sebelumnya dicabut.');
                } elseif ($operation==='update_identity') {
                    $username=strtolower(trim((string)($_POST['username'] ?? '')));
                    $fullName=trim((string)($_POST['full_name'] ?? ''));
                    if(!preg_match('/^[a-z0-9._-]{4,80}$/',$username) || mb_strlen($fullName)<3 || mb_strlen($fullName)>160) throw new RuntimeException('Username atau nama akun tidak valid.');
                    $pdo->prepare('UPDATE app_users SET username=?,full_name=? WHERE id=?')->execute([$username,$fullName,(int)$id]);
                    module_audit_actor($user,'update_identity',$entityType,(int)$id,['username'=>$before['username'],'full_name'=>$before['full_name']],['username'=>$username,'full_name'=>$fullName]);
                    flash('Identitas akun diperbarui.');
                } else throw new RuntimeException('Tindakan akun tidak dikenali.');
                redirect_to('admin-users');
            }
            case 'module_create_admin_account': {
                $user = module_require_role(['owner']);
                $username=strtolower(trim((string)($_POST['username'] ?? '')));
                $name=trim((string)($_POST['full_name'] ?? ''));
                $email=trim((string)($_POST['email'] ?? ''));
                $password=(string)($_POST['password'] ?? '');
                if(!preg_match('/^[a-z0-9._-]{4,80}$/',$username)) throw new RuntimeException('Username harus 4–80 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
                if(mb_strlen($name)<3 || mb_strlen($name)>160 || strlen($password)<20) throw new RuntimeException('Nama wajib diisi dan kata sandi awal minimal 20 karakter.');
                if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email tidak valid.');
                $stmt=$pdo->prepare("INSERT INTO app_users (username,email,full_name,password_hash,role,created_by,must_change_password) VALUES (?,?,?,?,'admin_desa',?,1)");
                $stmt->execute([$username,$email!==''?$email:null,$name,password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
                $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','admin_account',$id,null,['username'=>$username,'role'=>'admin_desa']);
                flash('Akun Admin Desa berhasil dibuat. Pengguna wajib mengganti kata sandi pada login pertama.');
                redirect_to('admin-users');
            }
            case 'module_create_staff_account': {
                $user = module_require_role(['admin_desa','owner']);
                $role=(string)($_POST['staff_role'] ?? '');
                $username=strtolower(trim((string)($_POST['username'] ?? '')));
                $name=trim((string)($_POST['full_name'] ?? ''));
                $email=trim((string)($_POST['email'] ?? ''));
                $password=(string)($_POST['password'] ?? '');
                if(!in_array($role,['petugas','kepala_desa'],true)) throw new RuntimeException('Peran petugas tidak valid.');
                if(!preg_match('/^[a-z0-9._-]{4,80}$/',$username)) throw new RuntimeException('Username harus 4–80 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
                if(mb_strlen($name)<3 || mb_strlen($name)>160 || strlen($password)<14) throw new RuntimeException('Nama wajib diisi dan kata sandi awal minimal 14 karakter.');
                if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email tidak valid.');
                $stmt=$pdo->prepare('INSERT INTO app_users (username,email,full_name,password_hash,role,created_by,must_change_password) VALUES (?,?,?,?,?,?,1)');
                $stmt->execute([$username,$email!==''?$email:null,$name,password_hash($password,PASSWORD_DEFAULT),$role,(int)$user['id']]);
                $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','staff_account',$id,null,['username'=>$username,'role'=>$role]);
                flash('Akun '.role_label($role).' berhasil dibuat. Pengguna wajib mengganti kata sandi pada login pertama.');
                redirect_to('admin-users');
            }
            case 'module_reassign_rt_account': {
                $user = module_require_role(['admin_desa','owner']);
                $id=filter_input(INPUT_POST,'user_id',FILTER_VALIDATE_INT);
                $rtId=filter_input(INPUT_POST,'rt_id',FILTER_VALIDATE_INT);
                if(!$id || !$rtId) throw new RuntimeException('Pilih akun dan wilayah RT yang valid.');
                $stmt=$pdo->prepare("SELECT id,username,full_name,role,rt_id,rw_id,is_active,session_version FROM app_users WHERE id=? AND role='rt' LIMIT 1");
                $stmt->execute([$id]);$before=$stmt->fetch();
                if(!$before) throw new RuntimeException('Akun RT tidak ditemukan.');
                $stmt=$pdo->prepare('SELECT rt.id,rt.rw_id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.is_active=1 AND rw.is_active=1 LIMIT 1');
                $stmt->execute([$rtId]);$target=$stmt->fetch();
                if(!$target) throw new RuntimeException('Wilayah RT tujuan tidak aktif.');
                $stmt=$pdo->prepare("SELECT id FROM app_users WHERE role='rt' AND rt_id=? AND is_active=1 AND id<>? LIMIT 1");
                $stmt->execute([$rtId,$id]);
                if($stmt->fetchColumn()) throw new RuntimeException('Wilayah tujuan sudah memiliki akun RT aktif. Nonaktifkan atau pindahkan akun yang lama dahulu.');
                $pdo->prepare('UPDATE app_users SET rt_id=?,rw_id=?,session_version=session_version+1,must_change_password=1 WHERE id=?')
                    ->execute([$rtId,(int)$target['rw_id'],(int)$id]);
                module_audit_actor($user,'reassign','rt_account',(int)$id,['rt_id'=>(int)$before['rt_id'],'rw_id'=>(int)$before['rw_id']],['rt_id'=>$rtId,'rw_id'=>(int)$target['rw_id']]);
                flash('Penugasan wilayah akun RT diperbarui. Sesi lama dicabut dan pengguna wajib mengganti kata sandi.');
                redirect_to('admin-users');
            }
            case 'module_create_rw_account': {
                $user = module_require_role(['admin_desa','owner']);
                $rwId=filter_input(INPUT_POST,'rw_id',FILTER_VALIDATE_INT);
                $username=strtolower(trim((string)($_POST['username'] ?? '')));
                $name=trim((string)($_POST['full_name'] ?? ''));
                $password=(string)($_POST['password'] ?? '');
                if (!$rwId || !preg_match('/^[a-z0-9._-]{4,80}$/',$username) || mb_strlen($name)<3 || strlen($password)<14) throw new RuntimeException('Periksa wilayah, username, nama, dan kata sandi minimal 14 karakter.');
                $stmt=$pdo->prepare('SELECT id FROM rw_units WHERE id=? AND is_active=1'); $stmt->execute([$rwId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Wilayah RW tidak tersedia.');
                $stmt=$pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role,rw_id,created_by,must_change_password) VALUES (?,?,?,'rw',?,?,1)");
                $stmt->execute([$username,$name,password_hash($password,PASSWORD_DEFAULT),$rwId,(int)$user['id']]); $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','rw_account',$id,null,['rw_id'=>$rwId,'username'=>$username]);
                flash('Akun RW berhasil dibuat.'); redirect_to('admin-users');
            }
            case 'module_import_residents': {
                $user = module_require_role(['rt','admin_desa','owner']);
                $rtId = ($user['role']==='rt') ? (int)($user['rt_id'] ?? 0) : (int)(filter_input(INPUT_POST,'rt_id',FILTER_VALIDATE_INT) ?: 0);
                if(!module_is_allowed_rt($user,$rtId)) throw new RuntimeException('Pilih wilayah RT yang valid.');
                $file=$_FILES['import_csv'] ?? [];
                if(($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Pilih file CSV yang akan diimpor.');
                if((int)($file['size'] ?? 0)<1 || (int)$file['size']>5*1024*1024) throw new RuntimeException('Ukuran CSV maksimal 5 MB.');
                if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv') throw new RuntimeException('File impor harus memiliki ekstensi .csv. Simpan Excel sebagai CSV UTF-8 terlebih dahulu.');
                $parsed=module_read_resident_csv((string)$file['tmp_name']);
                $rows=$parsed['rows'];$errors=$parsed['errors'];
                if(count($rows)>1000) throw new RuntimeException('Maksimal 1.000 baris warga per impor. Bagi file menjadi beberapa bagian.');
                $stmt=$pdo->prepare('SELECT id FROM residents WHERE nik_hash=? LIMIT 1');
                $validRows=[];
                foreach($rows as $row) {
                    $nikHash = private_identifier_hash((string)$row['nik']);
                    $stmt->execute([$nikHash]);
                    if($stmt->fetchColumn()) $errors[]="Baris {$row['line']}: NIK sudah terdaftar atau diarsipkan. Admin harus memeriksa kemungkinan data ganda/pindah.";
                    else $validRows[]=$row;
                }
                $groups=[];
                foreach($validRows as $row) $groups[$row['family_number']][]=$row;
                $familiesCreated=0;$residentsCreated=0;
                $pdo->beginTransaction();
                try {
                    foreach($groups as $familyNumber=>$familyRows) {
                        $addresses=array_unique(array_map(static fn($row)=>$row['address'],$familyRows));
                        if(count($addresses)>1) {
                            foreach($familyRows as $row) $errors[]="Baris {$row['line']}: alamat dalam satu nomor KK berbeda. Perbaiki file dan impor kembali.";
                            continue;
                        }
                        $familyHash = private_identifier_hash((string)$familyNumber);
                        $stmt=$pdo->prepare('SELECT id,rt_id,status,address,head_resident_id FROM family_records WHERE family_number_hash=? LIMIT 1');
                        $stmt->execute([$familyHash]);$family=$stmt->fetch();
                        if($family) {
                            if((int)$family['rt_id']!==$rtId || $family['status']==='archived') {
                                foreach($familyRows as $row) $errors[]="Baris {$row['line']}: nomor KK sudah ada di catatan lain/arsip. Minta Admin Desa memeriksa, jangan membuat KK kedua.";
                                continue;
                            }
                            $familyId=(int)$family['id'];
                            if(trim((string)$family['address'])!==trim((string)$familyRows[0]['address'])) {
                                foreach($familyRows as $row) $errors[]="Baris {$row['line']}: alamat berbeda dari data keluarga yang ada. Ajukan koreksi resmi terlebih dahulu.";
                                continue;
                            }
                        } else {
                            $familyCipher = encrypt_private_identifier((string)$familyNumber);
                            $familyMasked = mask_private_identifier((string)$familyNumber);
                            $stmt=$pdo->prepare("INSERT INTO family_records (family_number,family_number_cipher,family_number_hash,rt_id,address,status,created_by) VALUES (?,?,?,?,?,'pending_review',?)");
                            $stmt->execute([$familyMasked,$familyCipher,$familyHash,$rtId,$familyRows[0]['address'],(int)$user['id']]);
                            $familyId=(int)$pdo->lastInsertId();$familiesCreated++;
                            $family=['id'=>$familyId,'rt_id'=>$rtId,'status'=>'pending_review','address'=>$familyRows[0]['address'],'head_resident_id'=>null];
                        }
                        foreach($familyRows as $row) {
                            $nikHash = private_identifier_hash((string)$row['nik']);
                            $stmt=$pdo->prepare('SELECT id FROM residents WHERE nik_hash=? LIMIT 1');
                            $stmt->execute([$nikHash]);
                            if($stmt->fetchColumn()) { $errors[]="Baris {$row['line']}: NIK baru saja tercatat oleh proses lain; baris dilewati."; continue; }
                            $relation=$row['relationship_to_head'];
                            if((int)($family['head_resident_id'] ?? 0)>0 && $relation==='') $relation='Anggota keluarga';
                            $nikCipher = encrypt_private_identifier((string)$row['nik']);
                            $nikMasked = mask_private_identifier((string)$row['nik']);
                            $stmt=$pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,birth_date,gender,phone,relationship_to_head,resident_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?, 'pending_review',?)");
                            $stmt->execute([$familyId,$rtId,$nikMasked,$nikCipher,$nikHash,$row['full_name'],$row['birth_date'],$row['gender'],$row['phone']!==''?$row['phone']:null,$relation!==''?$relation:null,(int)$user['id']]);
                            $residentId=(int)$pdo->lastInsertId();$residentsCreated++;
                            if(!$family || (int)($family['head_resident_id'] ?? 0)===0) {
                                $pdo->prepare('UPDATE family_records SET head_resident_id=? WHERE id=? AND head_resident_id IS NULL')->execute([$residentId,$familyId]);
                                $family['head_resident_id']=$residentId;
                            }
                            module_audit_actor($user,'import_create','residents',$residentId,null,['family_id'=>$familyId,'rt_id'=>$rtId,'nik_last4'=>substr($row['nik'],-4),'status'=>'pending_review','source_line'=>$row['line']]);
                        }
                    }
                    $pdo->commit();
                } catch(Throwable $e) {
                    if($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                module_audit_actor($user,'import_csv','resident_import',null,null,['rt_id'=>$rtId,'families_created'=>$familiesCreated,'residents_created'=>$residentsCreated,'error_count'=>count($errors)]);
                $message="Impor selesai: {$residentsCreated} warga dimasukkan sebagai menunggu pemeriksaan; {$familiesCreated} keluarga baru dibuat.";
                if($errors) $message.=" Masalah: ".implode(' ' ,array_slice($errors,0,15)).(count($errors)>15?' Dan '.(count($errors)-15).' masalah lainnya.':'');
                flash($message,$errors?'warn':'success');
                redirect_to('rt-data');
            }
            case 'module_save_resident_family': {
                $user = module_require_role(['rt','admin_desa','owner']);
                $rtId = ($user['role']==='rt') ? (int)($user['rt_id'] ?? 0) : (int)(filter_input(INPUT_POST,'rt_id',FILTER_VALIDATE_INT) ?: 0);
                if (!module_is_allowed_rt($user,$rtId)) throw new RuntimeException('Wilayah RT tidak valid atau bukan wilayah tugas akun.');
                $familyNo=trim((string)($_POST['family_number'] ?? ''));
                $address=trim((string)($_POST['address'] ?? ''));
                $nik=preg_replace('/\D/','',(string)($_POST['nik'] ?? ''));
                $name=trim((string)($_POST['full_name'] ?? ''));
                $birth=trim((string)($_POST['birth_date'] ?? ''));
                $gender=(string)($_POST['gender'] ?? 'unknown');
                $phone=trim((string)($_POST['phone'] ?? ''));
                if (!preg_match('/^[0-9]{16}$/',$nik)) throw new RuntimeException('NIK harus terdiri dari 16 digit angka.');
                if (!preg_match('/^[0-9]{16}$/',$familyNo)) throw new RuntimeException('Nomor KK harus terdiri dari 16 digit angka.');
                if (mb_strlen($name)<3 || mb_strlen($name)>160 || mb_strlen($address)<5) throw new RuntimeException('Nama atau alamat belum valid.');
                if (!in_array($gender,['L','P','unknown'],true)) throw new RuntimeException('Pilihan jenis kelamin tidak valid.');
                if ($birth!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$birth)) throw new RuntimeException('Tanggal lahir tidak valid.');
                $familyHash = private_identifier_hash($familyNo);
                $familyCipher = encrypt_private_identifier($familyNo);
                $familyMasked = mask_private_identifier($familyNo);
                $nikHash = private_identifier_hash($nik);
                $nikCipher = encrypt_private_identifier($nik);
                $nikMasked = mask_private_identifier($nik);
                $stmt=$pdo->prepare('SELECT id FROM family_records WHERE family_number_hash=? LIMIT 1');
                $stmt->execute([$familyHash]);
                if ($stmt->fetchColumn()) throw new RuntimeException('Nomor KK sudah ada dalam sistem atau arsip. Minta Admin Desa memeriksa data keluarga yang sudah tercatat.');
                $pdo->beginTransaction();
                try {
                    $stmt=$pdo->prepare('SELECT id FROM residents WHERE nik_hash=? LIMIT 1'); $stmt->execute([$nikHash]);
                    if ($stmt->fetchColumn()) throw new RuntimeException('NIK ini sudah ada di sistem (termasuk arsip). Jangan masukkan ulang; minta Admin Desa memeriksa kemungkinan data ganda atau pindah RT.');
                    $stmt=$pdo->prepare("INSERT INTO family_records (family_number,family_number_cipher,family_number_hash,rt_id,address,status,created_by) VALUES (?,?,?,?,?,'pending_review',?)");
                    $stmt->execute([$familyMasked,$familyCipher,$familyHash,$rtId,$address,(int)$user['id']]); $familyId=(int)$pdo->lastInsertId();
                    $stmt=$pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,birth_date,gender,phone,resident_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'pending_review',?)");
                    $stmt->execute([$familyId,$rtId,$nikMasked,$nikCipher,$nikHash,$name,$birth!==''?$birth:null,$gender,$phone!==''?$phone:null,(int)$user['id']]); $residentId=(int)$pdo->lastInsertId();
                    $pdo->prepare('UPDATE family_records SET head_resident_id=? WHERE id=?')->execute([$residentId,$familyId]);
                    module_audit_actor($user,'create','family_records',$familyId,null,['rt_id'=>$rtId,'status'=>'pending_review']);
                    module_audit_actor($user,'create','residents',$residentId,null,['family_id'=>$familyId,'rt_id'=>$rtId,'nik_last4'=>substr($nik,-4),'status'=>'pending_review']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                flash('Data keluarga dan kepala keluarga disimpan sebagai menunggu pemeriksaan. Data belum dianggap resmi.');
                redirect_to('rt-data');
            }
            case 'module_add_resident': {
                $user = module_require_role(['rt','admin_desa','owner']);
                $familyId=filter_input(INPUT_POST,'family_id',FILTER_VALIDATE_INT);
                $stmt=$pdo->prepare('SELECT id,rt_id,status FROM family_records WHERE id=? LIMIT 1'); $stmt->execute([$familyId]); $family=$stmt->fetch();
                if (!$family || !module_is_allowed_rt($user,(int)$family['rt_id'])) throw new RuntimeException('Keluarga tidak ditemukan atau di luar wilayah tugas.');
                $nik=preg_replace('/\D/','',(string)($_POST['nik'] ?? ''));
                $name=trim((string)($_POST['full_name'] ?? ''));
                $birth=trim((string)($_POST['birth_date'] ?? ''));
                $gender=(string)($_POST['gender'] ?? 'unknown');
                $relation=trim((string)($_POST['relationship_to_head'] ?? ''));
                if (!preg_match('/^[0-9]{16}$/',$nik) || mb_strlen($name)<3 || !in_array($gender,['L','P','unknown'],true)) throw new RuntimeException('NIK, nama, atau jenis kelamin belum valid.');
                if ($birth!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$birth)) throw new RuntimeException('Tanggal lahir tidak valid.');
                $nikHash = private_identifier_hash($nik);
                $nikCipher = encrypt_private_identifier($nik);
                $nikMasked = mask_private_identifier($nik);
                $stmt=$pdo->prepare('SELECT id FROM residents WHERE nik_hash=? LIMIT 1'); $stmt->execute([$nikHash]);
                if ($stmt->fetchColumn()) throw new RuntimeException('NIK telah tercatat. Hubungi admin untuk pemeriksaan, jangan membuat data ganda.');
                $stmt=$pdo->prepare("INSERT INTO residents (family_id,rt_id,nik,nik_cipher,nik_hash,full_name,birth_date,gender,relationship_to_head,resident_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'pending_review',?)");
                $stmt->execute([$familyId,(int)$family['rt_id'],$nikMasked,$nikCipher,$nikHash,$name,$birth!==''?$birth:null,$gender,$relation!==''?$relation:null,(int)$user['id']]);
                $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','residents',$id,null,['family_id'=>$familyId,'rt_id'=>(int)$family['rt_id'],'nik_last4'=>substr($nik,-4),'status'=>'pending_review']);
                flash('Anggota keluarga ditambahkan sebagai menunggu pemeriksaan.'); redirect_to('rt-data');
            }
            case 'module_review_resident': {
                $user = module_require_role(['petugas','admin_desa','owner']);
                $kind=(string)($_POST['kind'] ?? 'resident');
                $id=filter_input(INPUT_POST,'record_id',FILTER_VALIDATE_INT);
                $decision=(string)($_POST['decision'] ?? '');
                if (!in_array($kind,['resident','family'],true) || !$id || !in_array($decision,['approve','archive'],true)) throw new RuntimeException('Keputusan pemeriksaan tidak valid.');

                $pdo->beginTransaction();
                try {
                    if ($kind==='resident') {
                        $stmt=$pdo->prepare("SELECT id,family_id,rt_id,resident_status FROM residents WHERE id=? AND resident_status='pending_review' LIMIT 1 FOR UPDATE");
                        $stmt->execute([$id]); $row=$stmt->fetch();
                        if (!$row) throw new RuntimeException('Data penduduk tidak ditemukan atau sudah diperiksa.');
                        if (!module_is_allowed_rt($user,(int)$row['rt_id'])) throw new RuntimeException('Data penduduk berada di luar wilayah tugas akun ini.');
                        $new=$decision==='approve'?'active':'archived';
                        $update=$pdo->prepare("UPDATE residents SET resident_status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND resident_status='pending_review'");
                        $update->execute([$new,(int)$user['id'],$id]);
                        if ($update->rowCount()!==1) throw new RuntimeException('Status penduduk berubah saat diproses. Muat ulang halaman.');
                        if ($decision==='approve') {
                            $pdo->prepare("UPDATE family_records SET status='active',reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='pending_review'")->execute([(int)$user['id'],(int)$row['family_id']]);
                        }
                        module_audit_actor($user,$decision,'residents',$id,['status'=>$row['resident_status']],['status'=>$new]);
                    } else {
                        $stmt=$pdo->prepare("SELECT id,rt_id,status FROM family_records WHERE id=? AND status='pending_review' LIMIT 1 FOR UPDATE");
                        $stmt->execute([$id]); $row=$stmt->fetch();
                        if (!$row) throw new RuntimeException('Data keluarga tidak ditemukan atau sudah diperiksa.');
                        if (!module_is_allowed_rt($user,(int)$row['rt_id'])) throw new RuntimeException('Data keluarga berada di luar wilayah tugas akun ini.');
                        $new=$decision==='approve'?'active':'archived';
                        $update=$pdo->prepare("UPDATE family_records SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='pending_review'");
                        $update->execute([$new,(int)$user['id'],$id]);
                        if ($update->rowCount()!==1) throw new RuntimeException('Status keluarga berubah saat diproses. Muat ulang halaman.');
                        module_audit_actor($user,$decision,'family_records',$id,['status'=>$row['status']],['status'=>$new]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                flash($decision==='approve'?'Data disahkan.':'Data diarsipkan dari antrean.');
                redirect_to('perubahan');
            }
            case 'module_submit_change': {
                $user = module_require_role(['rt']);
                $residentId=filter_input(INPUT_POST,'resident_id',FILTER_VALIDATE_INT);
                $resident=module_resident_scope($user,(int)$residentId);
                $type=(string)($_POST['change_type'] ?? 'update');
                $reason=trim((string)($_POST['reason'] ?? ''));
                if (!in_array($type,['update','move_rt','deceased'],true) || mb_strlen($reason)<8 || mb_strlen($reason)>1000) throw new RuntimeException('Jenis usulan atau alasan belum valid.');
                $proposed=[];
                foreach (['full_name','birth_date','gender','phone','address'] as $key) {
                    if (array_key_exists($key,$_POST)) $proposed[$key]=trim((string)$_POST[$key]);
                }
                if (isset($proposed['gender']) && !in_array($proposed['gender'],['L','P','unknown'],true)) throw new RuntimeException('Jenis kelamin tidak valid.');
                $targetRt=null;
                if ($type==='move_rt') {
                    $targetRt=filter_input(INPUT_POST,'target_rt_id',FILTER_VALIDATE_INT);
                    if (!$targetRt || $targetRt===(int)$resident['rt_id']) throw new RuntimeException('Pilih RT tujuan yang berbeda.');
                    $stmt=$pdo->prepare('SELECT id FROM rt_units WHERE id=? AND is_active=1'); $stmt->execute([$targetRt]);
                    if (!$stmt->fetchColumn()) throw new RuntimeException('RT tujuan tidak tersedia.');
                }
                if ($type==='deceased') $proposed=[];
                $pdo->beginTransaction();
                try {
                    $snapshot = module_change_snapshot((int)$resident['id'],(int)$resident['family_id'],$type,true);
                    if ((int)$snapshot['resident_rt_id'] !== (int)($user['rt_id'] ?? 0)
                        || (int)$snapshot['family_rt_id'] !== (int)($user['rt_id'] ?? 0)) {
                        throw new RuntimeException('Wilayah warga/keluarga berubah saat usulan dibuat. Muat ulang halaman dan minta Admin Desa memeriksa data.');
                    }
                    $stmt=$pdo->prepare("INSERT INTO resident_change_requests (resident_id,family_id,source_rt_id,target_rt_id,change_type,proposed_data,source_snapshot,reason,status,submitted_by) VALUES (?,?,?,?,?,?,?,?, 'submitted',?)");
                    $stmt->execute([(int)$resident['id'],(int)$resident['family_id'],(int)$resident['rt_id'],$targetRt,$type,json_encode($proposed,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$reason,(int)$user['id']]);
                    $id=(int)$pdo->lastInsertId();
                    module_audit_actor($user,'submit_change','resident_change_requests',$id,null,['resident_id'=>(int)$resident['id'],'type'=>$type,'source_rt_id'=>(int)$resident['rt_id'],'target_rt_id'=>$targetRt,'snapshot_version'=>1]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                flash('Usulan perubahan dikirim ke antrean pemeriksaan. Data resmi belum diubah.');
                redirect_to('perubahan');
            }
            case 'module_resubmit_change': {
                $user = module_require_role(['rt']);
                $id=filter_input(INPUT_POST,'change_id',FILTER_VALIDATE_INT);
                $reason=trim((string)($_POST['reason'] ?? ''));
                if(!$id || mb_strlen($reason)<8 || mb_strlen($reason)>1000) throw new RuntimeException('Usulan dan alasan revisi belum valid.');
                $pdo->beginTransaction();
                try {
                    $stmt=$pdo->prepare("SELECT cr.*,r.rt_id AS resident_rt_id FROM resident_change_requests cr LEFT JOIN residents r ON r.id=cr.resident_id WHERE cr.id=? AND cr.status='revision_requested' LIMIT 1 FOR UPDATE");
                    $stmt->execute([$id]); $request=$stmt->fetch();
                    if(!$request || (int)$request['source_rt_id']!==(int)($user['rt_id'] ?? 0) || (int)$request['submitted_by']!==(int)$user['id']) {
                        throw new RuntimeException('Usulan revisi tidak ditemukan atau bukan milik wilayah akun ini.');
                    }
                    if (str_starts_with((string)($request['review_note'] ?? ''), '[KONFLIK]')
                        && (string)($_POST['confirm_current_data'] ?? '') !== '1') {
                        throw new RuntimeException('Usulan mengalami konflik data. Baca data terkini, centang konfirmasi setelah membandingkan, lalu kirim revisi.');
                    }
                    $data=json_decode((string)$request['proposed_data'],true) ?: [];
                    foreach(['full_name','birth_date','gender','phone','address'] as $key) {
                        if(array_key_exists($key,$_POST)) $data[$key]=trim((string)$_POST[$key]);
                    }
                    $type=(string)$request['change_type'];
                    if($type==='move_rt') {
                        $target=filter_input(INPUT_POST,'target_rt_id',FILTER_VALIDATE_INT);
                        if($target) {
                            if($target===(int)$request['source_rt_id']) throw new RuntimeException('RT tujuan harus berbeda dari RT asal.');
                            $check=$pdo->prepare('SELECT rt.id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.is_active=1 AND rw.is_active=1');
                            $check->execute([$target]);
                            if(!$check->fetchColumn()) throw new RuntimeException('RT/RW tujuan tidak tersedia atau sudah dinonaktifkan.');
                            $request['target_rt_id']=$target;
                        }
                        if(!(int)$request['target_rt_id']) throw new RuntimeException('Pilih RT tujuan untuk usulan pindah.');
                    }
                    if(isset($data['gender']) && !in_array($data['gender'],['L','P','unknown'],true)) throw new RuntimeException('Jenis kelamin tidak valid.');
                    if (!empty($data['birth_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$data['birth_date'])) throw new RuntimeException('Tanggal lahir tidak valid.');
                    $snapshot=module_change_snapshot((int)$request['resident_id'],(int)$request['family_id'],$type,true);
                    if ((int)$snapshot['resident_rt_id'] !== (int)($user['rt_id'] ?? 0)
                        || (int)$snapshot['family_rt_id'] !== (int)($user['rt_id'] ?? 0)) {
                        throw new RuntimeException('Wilayah warga/keluarga tidak lagi sesuai akun RT ini. Minta Admin Desa memeriksa usulan; jangan buat catatan baru.');
                    }
                    $stmt=$pdo->prepare("UPDATE resident_change_requests SET proposed_data=?,source_snapshot=?,target_rt_id=?,reason=?,status='submitted',review_note=NULL,reviewed_by=NULL,reviewed_at=NULL,updated_at=NOW() WHERE id=? AND status='revision_requested'");
                    $stmt->execute([
                        json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                        json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                        $request['target_rt_id'],
                        $reason,
                        (int)$id
                    ]);
                    if($stmt->rowCount()!==1) throw new RuntimeException('Status usulan berubah saat revisi. Muat ulang halaman sebelum mencoba kembali.');
                    module_audit_actor($user,'resubmit_change','resident_change_requests',(int)$id,['status'=>'revision_requested'],['status'=>'submitted','snapshot_version'=>1]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                flash('Usulan revisi dikirim kembali dengan nomor yang sama. Data sumber dicatat ulang untuk pemeriksaan konflik.');
                redirect_to('perubahan');
            }
            case 'module_review_change': {
                $user = module_require_role(['petugas','admin_desa','owner']);
                $id=filter_input(INPUT_POST,'change_id',FILTER_VALIDATE_INT);
                $decision=(string)($_POST['decision'] ?? '');
                $note=trim((string)($_POST['review_note'] ?? ''));
                if (!$id || !in_array($decision,['approve','revision_requested','reject'],true)) throw new RuntimeException('Keputusan tidak valid.');
                if ($decision==='approve' && !in_array(($user['role'] ?? ''), ['admin_desa','owner'], true)) throw new RuntimeException('Persetujuan akhir usulan perubahan hanya oleh Admin Desa.');
                if ($decision==='reject' && !in_array(($user['role'] ?? ''), ['admin_desa','owner'], true)) throw new RuntimeException('Penolakan akhir usulan hanya oleh Admin Desa.');
                if ($decision!=='approve' && mb_strlen($note)<4) throw new RuntimeException('Isi alasan untuk meminta revisi atau menolak.');

                $pdo->beginTransaction();
                try {
                    $stmt=$pdo->prepare("SELECT * FROM resident_change_requests WHERE id=? AND status IN ('submitted','in_review','revision_requested','conflict') LIMIT 1 FOR UPDATE");
                    $stmt->execute([$id]); $request=$stmt->fetch();
                    if (!$request) throw new RuntimeException('Usulan tidak ditemukan atau sudah selesai.');
                    $wasConflict = (string)$request['status'] === 'conflict';
                    if ($wasConflict && $decision==='approve') {
                        throw new RuntimeException('Usulan berstatus konflik tidak dapat langsung disetujui. Bandingkan data terkini lalu minta RT mengirim revisi, atau tolak dengan alasan.');
                    }

                    if ($decision==='approve' && !module_change_snapshot_matches($request)) {
                        $conflictNote='[KONFLIK] Data penduduk atau keluarga berubah setelah usulan dikirim. Sistem menahan persetujuan agar tidak menimpa data yang lebih baru. Admin Desa harus membandingkan data terkini, kemudian meminta RT mengirim revisi pada usulan yang sama.';
                        $pdo->prepare("UPDATE resident_change_requests SET status='conflict',review_note=?,reviewed_by=?,reviewed_at=NOW(),updated_at=NOW() WHERE id=?")
                            ->execute([$conflictNote,(int)$user['id'],(int)$id]);
                        module_audit_actor($user,'conflict_detected','resident_change_requests',(int)$id,['status'=>$request['status']],['status'=>'conflict','reason'=>'source_snapshot_mismatch']);
                        $pdo->commit();
                        flash('Persetujuan ditahan: data sumber berubah setelah usulan dibuat. Usulan ditandai konflik; tidak ada data resmi yang ditimpa.','warning');
                        redirect_to('perubahan');
                    }

                    $data=json_decode((string)$request['proposed_data'],true) ?: [];
                    if ($decision==='approve') {
                        $resident=$request['resident_id'] ? module_resident_scope($user,(int)$request['resident_id']) : null;
                        if (!$resident || (int)$resident['family_id'] !== (int)$request['family_id']) {
                            throw new RuntimeException('Data sumber tidak lagi cocok dengan usulan ini. Persetujuan dibatalkan.');
                        }
                        if ($request['change_type']==='move_rt') {
                            $target=(int)$request['target_rt_id'];
                            if (!$target) throw new RuntimeException('RT tujuan kosong.');
                            $targetCheck=$pdo->prepare('SELECT rt.id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.is_active=1 AND rw.is_active=1 LIMIT 1 FOR UPDATE');
                            $targetCheck->execute([$target]);
                            if (!$targetCheck->fetchColumn()) {
                                $conflictNote='[KONFLIK] RT/RW tujuan sudah tidak aktif atau berubah. Admin Desa harus meminta RT memilih wilayah tujuan yang masih aktif.';
                                $pdo->prepare("UPDATE resident_change_requests SET status='conflict',review_note=?,reviewed_by=?,reviewed_at=NOW(),updated_at=NOW() WHERE id=?")
                                    ->execute([$conflictNote,(int)$user['id'],(int)$id]);
                                module_audit_actor($user,'conflict_detected','resident_change_requests',(int)$id,['status'=>$request['status']],['status'=>'conflict','reason'=>'inactive_target_rt']);
                                $pdo->commit();
                                flash('Persetujuan pindah ditahan karena RT/RW tujuan tidak aktif. Usulan ditandai konflik.','warning');
                                redirect_to('perubahan');
                            }
                            $familyId=(int)$request['family_id'];
                            $pdo->prepare('UPDATE family_records SET rt_id=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$target,(int)$user['id'],$familyId]);
                            $pdo->prepare('UPDATE residents SET rt_id=?,reviewed_by=?,reviewed_at=NOW() WHERE family_id=?')->execute([$target,(int)$user['id'],$familyId]);
                            $pdo->prepare('UPDATE resident_documents SET rt_id=? WHERE family_id=?')->execute([$target,$familyId]);
                            $pdo->prepare('UPDATE resident_documents SET rt_id=? WHERE resident_id IN (SELECT id FROM residents WHERE family_id=?)')->execute([$target,$familyId]);
                            $targetRtUsers=$pdo->prepare("SELECT id FROM app_users WHERE role='rt' AND rt_id=? AND is_active=1 ORDER BY id");
                            $targetRtUsers->execute([$target]);
                            $targetRecipients=$targetRtUsers->fetchAll(PDO::FETCH_COLUMN);
                            $notify=$pdo->prepare("INSERT INTO user_notifications (user_id,title,message,notification_type,entity_type,entity_id) VALUES (?,?,?,'data_change','resident_change_request',?)");
                            foreach($targetRecipients as $recipientId) {
                                $notify->execute([(int)$recipientId,'Perpindahan data diterima','Ada keluarga/warga baru di wilayah RT kamu setelah usulan pindah disetujui. Periksa menu Data Warga dan Dokumen Warga untuk menindaklanjuti kelengkapan dokumen.',(string)$id]);
                            }
                            if (!$targetRecipients) {
                                $targetRwStmt=$pdo->prepare('SELECT rw_id FROM rt_units WHERE id=? LIMIT 1');
                                $targetRwStmt->execute([$target]);
                                $targetRwId=(int)$targetRwStmt->fetchColumn();
                                if($targetRwId>0) {
                                    $rwUsers=$pdo->prepare("SELECT id FROM app_users WHERE role='rw' AND rw_id=? AND is_active=1");
                                    $rwUsers->execute([$targetRwId]);
                                    $notifyRw=$pdo->prepare("INSERT INTO user_notifications (user_id,title,message,notification_type,entity_type,entity_id) VALUES (?,?,?,'data_change','resident_change_request',?)");
                                    foreach($rwUsers->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
                                        $notifyRw->execute([(int)$recipientId,'Perpindahan data menunggu pengurus RT','Data keluarga/warga telah dipindahkan ke wilayah RT yang belum memiliki akun Ketua RT aktif. Pastikan pengurus RT ditugaskan dan data warga diperiksa.',(string)$id]);
                                    }
                                }
                            }
                        } elseif ($request['change_type']==='deceased') {
                            $pdo->prepare("UPDATE residents SET resident_status='deceased',reviewed_by=?,reviewed_at=NOW() WHERE id=?")
                                ->execute([(int)$user['id'],(int)$resident['id']]);
                        } elseif ($request['change_type']==='update') {
                            $allowed=['full_name','birth_date','gender','phone'];
                            $sets=[]; $values=[];
                            foreach($allowed as $key) {
                                if(array_key_exists($key,$data)) {
                                    if($key==='full_name' && (mb_strlen(trim((string)$data[$key]))<3 || mb_strlen((string)$data[$key])>160)) throw new RuntimeException('Nama yang diusulkan belum valid.');
                                    if($key==='gender' && !in_array($data[$key],['L','P','unknown'],true)) throw new RuntimeException('Jenis kelamin yang diusulkan belum valid.');
                                    if($key==='birth_date' && $data[$key]!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$data[$key])) throw new RuntimeException('Tanggal lahir yang diusulkan belum valid.');
                                    $sets[]="{$key}=?";
                                    $values[]=$data[$key]!==''?$data[$key]:null;
                                }
                            }
                            if($sets) {
                                $values[]=(int)$resident['id'];
                                $pdo->prepare('UPDATE residents SET '.implode(',',$sets).',reviewed_by=?,reviewed_at=NOW() WHERE id=?')
                                    ->execute(array_merge(array_slice($values,0,-1),[(int)$user['id'],end($values)]));
                            }
                            if (array_key_exists('address',$data)) {
                                $address=trim((string)$data['address']);
                                if(mb_strlen($address)<5 || mb_strlen($address)>1000) throw new RuntimeException('Alamat yang diusulkan belum valid.');
                                $pdo->prepare('UPDATE family_records SET address=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')
                                    ->execute([$address,(int)$user['id'],(int)$request['family_id']]);
                            }
                        } else {
                            throw new RuntimeException('Jenis usulan ini belum didukung untuk persetujuan otomatis. Minta revisi atau pemeriksaan khusus.');
                        }
                    }

                    $newStatus=['approve'=>'approved','revision_requested'=>'revision_requested','reject'=>'rejected'][$decision];
                    $savedNote=$note;
                    if($decision==='revision_requested' && $wasConflict) {
                        $savedNote='[KONFLIK] ' . $note;
                    }
                    $pdo->prepare('UPDATE resident_change_requests SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW(),updated_at=NOW() WHERE id=?')
                        ->execute([$newStatus,$savedNote!==' ' && $savedNote!==''?$savedNote:null,(int)$user['id'],(int)$id]);
                    module_audit_actor($user,$decision==='approve'?'approve_change':($decision==='reject'?'reject_change':'request_change_revision'),
                        'resident_change_requests',(int)$id,['status'=>$request['status']],['status'=>$newStatus,'review_note'=>$savedNote,'snapshot_checked'=>$decision==='approve']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                flash($decision==='approve'?'Usulan disetujui dan data resmi diperbarui secara tercatat.':($decision==='reject'?'Usulan ditolak dengan alasan tercatat.':'RT diminta memperbaiki usulan pada nomor yang sama.'));
                redirect_to('perubahan');
            }
            case 'module_upload_document': {
                $user = module_require_role(['rt','petugas','admin_desa','owner']);
                $residentId=filter_input(INPUT_POST,'resident_id',FILTER_VALIDATE_INT);
                $resident=module_resident_scope($user,(int)$residentId);
                $type=(string)($_POST['document_type'] ?? '');
                if (!in_array($type,['ktp','kk','akta','surat_pendukung','lainnya'],true)) throw new RuntimeException('Jenis dokumen tidak valid.');
                $file=module_upload_private_file($_FILES['document'] ?? []);
                try {
                    $stmt=$pdo->prepare("INSERT INTO resident_documents (resident_id,family_id,rt_id,document_type,storage_key,original_name,mime_type,size_bytes,sha256,status,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,'pending',?)");
                    $stmt->execute([(int)$resident['id'],(int)$resident['family_id'],(int)$resident['rt_id'],$type,$file['key'],$file['name'],$file['mime'],$file['size'],$file['sha256'],(int)$user['id']]);
                    $id=(int)$pdo->lastInsertId();
                    module_audit_actor($user,'upload','resident_documents',$id,null,['resident_id'=>(int)$resident['id'],'type'=>$type,'bytes'=>$file['size'],'sha256'=>$file['sha256']]);
                } catch(Throwable $e) {
                    @unlink(dirname(__DIR__).'/storage/uploads/'.$file['key']);
                    throw $e;
                }
                flash('Dokumen berhasil diunggah ke penyimpanan privat dan menunggu pemeriksaan.');
                redirect_to('dokumen');
            }
            case 'module_review_document': {
                $user = module_require_role(['petugas','admin_desa','owner']);
                $id=filter_input(INPUT_POST,'document_id',FILTER_VALIDATE_INT);
                $status=(string)($_POST['status'] ?? '');
                $note=trim((string)($_POST['review_note'] ?? ''));
                if (!$id || !in_array($status,['approved','revision_requested','rejected','archived'],true)) throw new RuntimeException('Status dokumen tidak valid.');
                if ($status!=='approved' && mb_strlen($note)<4) throw new RuntimeException('Tuliskan alasan untuk dokumen yang perlu diperbaiki/ditolak/diarsipkan.');

                $pdo->beginTransaction();
                try {
                    // Lock the row so two reviewers cannot silently overwrite each other's decision.
                    $stmt=$pdo->prepare('SELECT id,rt_id,status,document_type,sha256 FROM resident_documents WHERE id=? LIMIT 1 FOR UPDATE');
                    $stmt->execute([$id]);
                    $before=$stmt->fetch();
                    if (!$before) throw new RuntimeException('Dokumen tidak ditemukan.');
                    if (!module_is_allowed_rt($user,(int)$before['rt_id'])) throw new RuntimeException('Dokumen berada di luar wilayah tugas akun ini.');
                    if (!module_document_status_transition_allowed((string)$before['status'],$status)) {
                        throw new RuntimeException('Perubahan status dokumen tidak diizinkan dari status saat ini. Muat ulang halaman dan periksa status terbaru.');
                    }
                    $update=$pdo->prepare('UPDATE resident_documents SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status=?');
                    $update->execute([$status,$note!==''?$note:null,(int)$user['id'],$id,(string)$before['status']]);
                    if ($update->rowCount()!==1) throw new RuntimeException('Status dokumen berubah saat diproses. Muat ulang halaman sebelum mencoba kembali.');
                    module_audit_actor($user,'review_document','resident_documents',$id,['status'=>$before['status']],['status'=>$status,'note'=>$note]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                flash('Status dokumen diperbarui.'); redirect_to('dokumen');
            }
            case 'module_publish_site_revision': {
                $user = module_require_role(['admin_desa','owner']);
                $id=filter_input(INPUT_POST,'revision_id',FILTER_VALIDATE_INT);
                if(!$id) throw new RuntimeException('Draf konten tidak valid.');
                $stmt=$pdo->prepare("SELECT id,content_key,content_value,status FROM site_content_revisions WHERE id=? AND status='draft' LIMIT 1");
                $stmt->execute([$id]);$revision=$stmt->fetch();
                if(!$revision) throw new RuntimeException('Draf tidak ditemukan atau sudah diproses.');
                $key=(string)$revision['content_key'];$value=(string)$revision['content_value'];
                if($key==='map_public_url' && $value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https')) throw new RuntimeException('Tautan peta draf tidak valid. Simpan ulang menggunakan URL HTTPS.');
                if(in_array($key,module_site_image_keys(),true) && !module_site_image_value_allowed($key,$value)) throw new RuntimeException('Gambar draf tidak menunjuk ke berkas gambar yang diizinkan.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare('SELECT id,content_value,value_type FROM site_content WHERE content_key=? LIMIT 1');
                $stmt->execute([$key]);$current=$stmt->fetch();
                $type=in_array($key,module_site_image_keys(),true)?'image':'text';
                if($current) {
                    $pdo->prepare('INSERT INTO site_content_versions (content_id,content_value,editor_id) VALUES (?,?,?)')->execute([(int)$current['id'],(string)$current['content_value'],(int)$user['id']]);
                    $pdo->prepare("UPDATE site_content SET content_value=?,value_type=?,status='published',updated_by=? WHERE id=?")->execute([$value,$type,(int)$user['id'],(int)$current['id']]);
                } else {
                    $pdo->prepare("INSERT INTO site_content (content_key,content_value,value_type,status,updated_by) VALUES (?,?,?,'published',?)")->execute([$key,$value,$type,(int)$user['id']]);
                }
                $pdo->prepare("UPDATE site_content_revisions SET status='archived' WHERE content_key=? AND status='published'")->execute([$key]);
                $pdo->prepare("UPDATE site_content_revisions SET status='published',published_at=NOW() WHERE id=? AND status='draft'")->execute([(int)$id]);
                module_audit_actor($user,'publish_draft','site_content_revisions',(int)$id,['status'=>'draft'],['status'=>'published','content_key'=>$key]);
                $pdo->commit();
                flash('Draf '. $key .' berhasil diterbitkan ke website publik.');
                redirect_to('admin-site');
            }
            case 'module_restore_site_version': {
                $user = module_require_role(['admin_desa','owner']);
                $id=filter_input(INPUT_POST,'version_id',FILTER_VALIDATE_INT);
                if(!$id) throw new RuntimeException('Versi konten tidak valid.');
                $stmt=$pdo->prepare('SELECT v.id,v.content_id,v.content_value,sc.content_key,sc.value_type FROM site_content_versions v JOIN site_content sc ON sc.id=v.content_id WHERE v.id=? LIMIT 1');
                $stmt->execute([$id]);$version=$stmt->fetch();
                if(!$version) throw new RuntimeException('Versi lama tidak ditemukan.');
                $key=(string)$version['content_key'];$value=(string)$version['content_value'];
                if($key==='map_public_url' && $value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https')) throw new RuntimeException('Versi lama berisi tautan peta yang tidak valid. Pulihkan melalui editor setelah memperbaikinya.');
                if(in_array($key,module_site_image_keys(),true) && !module_site_image_value_allowed($key,$value)) throw new RuntimeException('Versi gambar tidak menunjuk ke berkas gambar yang diizinkan.');
                $pdo->beginTransaction();
                $currentStmt=$pdo->prepare('SELECT content_value FROM site_content WHERE id=? LIMIT 1');
                $currentStmt->execute([(int)$version['content_id']]);$currentValue=(string)$currentStmt->fetchColumn();
                $pdo->prepare('INSERT INTO site_content_versions (content_id,content_value,editor_id) VALUES (?,?,?)')->execute([(int)$version['content_id'],$currentValue,(int)$user['id']]);
                $pdo->prepare("UPDATE site_content SET content_value=?,status='published',updated_by=? WHERE id=?")->execute([$value,(int)$user['id'],(int)$version['content_id']]);
                $pdo->prepare("UPDATE site_content_revisions SET status='archived' WHERE content_key=? AND status='published'")->execute([$key]);
                $pdo->prepare("INSERT INTO site_content_revisions (content_key,content_value,status,editor_id,published_at) VALUES (?,?,'published',?,NOW())")->execute([$key,$value,(int)$user['id']]);
                module_audit_actor($user,'restore_version','site_content_versions',(int)$id,['content_key'=>$key],['restored_version_id'=>(int)$id]);
                $pdo->commit();
                flash('Versi lama untuk '. $key .' berhasil dipulihkan dan diterbitkan.');
                redirect_to('admin-site');
            }
            case 'module_save_site': {
                $user = module_require_role(['admin_desa','owner']);
                $fields=[
                    'village_name'=>['label'=>'Nama desa','max'=>160],
                    'village_subdistrict'=>['label'=>'Kecamatan','max'=>120],
                    'village_regency'=>['label'=>'Kabupaten/Kota','max'=>120],
                    'village_province'=>['label'=>'Provinsi','max'=>120],
                    'village_head_name'=>['label'=>'Nama kepala desa/pejabat penandatangan','max'=>160],
                    'village_head_title'=>['label'=>'Jabatan penandatangan','max'=>120],
                    'hero_title_line1'=>['label'=>'Judul hero baris pertama','max'=>100],
                    'hero_title_prefix'=>['label'=>'Kata sebelum judul sorotan','max'=>50],
                    'hero_title_highlight'=>['label'=>'Judul hero sorotan','max'=>100],
                    'hero_subtitle'=>['label'=>'Deskripsi hero','max'=>500],
                    'hero_focus_x'=>['label'=>'Fokus foto horizontal (0–100)','max'=>3],
                    'hero_focus_y'=>['label'=>'Fokus foto vertikal (0–100)','max'=>3],
                    'hero_image_alt'=>['label'=>'Teks alternatif foto hero','max'=>255],
                    'welcome_title'=>['label'=>'Judul sambutan','max'=>160],
                    'welcome_text'=>['label'=>'Isi sambutan','max'=>300],
                    'profil_sejarah'=>['label'=>'Profil dan sejarah desa','max'=>20000],
                    'profil_potensi'=>['label'=>'Potensi desa','max'=>20000],
                    'umkm_intro'=>['label'=>'Pengantar UMKM dan wisata','max'=>1000],
                    'map_public_url'=>['label'=>'Tautan peta HTTPS','max'=>1000],
                    'transparansi_intro'=>['label'=>'Pengantar transparansi anggaran','max'=>2000],
                    'contact_intro'=>['label'=>'Petunjuk kontak layanan','max'=>1000],
                    'office_address'=>['label'=>'Alamat kantor desa','max'=>500],
                    'office_phone'=>['label'=>'Nomor kontak kantor','max'=>80],
                    'stat_area'=>['label'=>'Luas wilayah','max'=>60],
                    'stat_dusun'=>['label'=>'Jumlah dusun','max'=>30],
                    'meta_description'=>['label'=>'Deskripsi SEO','max'=>300],'village_brand_prefix'=>['label'=>'Awalan logo','max'=>40],
                    'nav_home'=>['label'=>'Menu Beranda','max'=>60],'nav_services'=>['label'=>'Menu Layanan','max'=>80],'nav_news'=>['label'=>'Menu Berita','max'=>100],
                    'nav_complaints'=>['label'=>'Menu Pengaduan','max'=>80],'nav_profile'=>['label'=>'Menu Profil','max'=>80],'nav_more'=>['label'=>'Menu Lainnya','max'=>60],
                    'nav_umkm'=>['label'=>'Menu UMKM','max'=>80],'nav_map'=>['label'=>'Menu Peta','max'=>60],'nav_budget'=>['label'=>'Menu Transparansi','max'=>100],
                    'nav_contact'=>['label'=>'Menu Kontak','max'=>60],'nav_search_aria'=>['label'=>'Aksesibilitas pencarian','max'=>100],'nav_login'=>['label'=>'Tombol masuk','max'=>60],
                    'news_fallback_alt'=>['label'=>'Teks alternatif gambar pengganti berita/UMKM','max'=>150],
                    'news_read_label'=>['label'=>'Label aksesibilitas tautan baca berita','max'=>80],
                    'nav_open_menu_aria'=>['label'=>'Label buka menu HP','max'=>100],
                    'nav_close_menu_aria'=>['label'=>'Label tutup menu HP','max'=>100],
                    'nav_main_aria'=>['label'=>'Label aksesibilitas navigasi utama','max'=>100],
                    'nav_mobile_drawer_aria'=>['label'=>'Label aksesibilitas menu tambahan HP','max'=>100],
                    'home_service_strip_aria'=>['label'=>'Label aksesibilitas layanan utama','max'=>100],
                    'mobile_nav_aria'=>['label'=>'Label aksesibilitas navigasi bawah HP','max'=>100],
                    'footer_summary'=>['label'=>'Ringkasan footer','max'=>500],'footer_tagline'=>['label'=>'Slogan footer','max'=>500],'footer_services'=>['label'=>'Tagline footer','max'=>500],
                    'home_eyebrow'=>['label'=>'Label hero','max'=>100],'hero_cta_apply'=>['label'=>'Tombol surat','max'=>80],'hero_cta_track'=>['label'=>'Tombol lacak','max'=>80],
                    'benefit_easy'=>['label'=>'Keunggulan 1','max'=>100],'benefit_fast'=>['label'=>'Keunggulan 2','max'=>100],'benefit_transparent'=>['label'=>'Keunggulan 3','max'=>100],'benefit_all'=>['label'=>'Keunggulan 4','max'=>100],
                    'service_card_service_title'=>['label'=>'Judul kartu layanan','max'=>120],'service_card_service_text'=>['label'=>'Deskripsi kartu layanan','max'=>400],
                    'service_card_complaint_title'=>['label'=>'Judul kartu pengaduan','max'=>120],'service_card_complaint_text'=>['label'=>'Deskripsi kartu pengaduan','max'=>400],
                    'service_card_info_title'=>['label'=>'Judul kartu informasi','max'=>120],'service_card_info_text'=>['label'=>'Deskripsi kartu informasi','max'=>400],
                    'home_tracking_title'=>['label'=>'Judul pelacakan','max'=>120],'home_tracking_subtitle'=>['label'=>'Deskripsi pelacakan','max'=>300],'tracking_placeholder'=>['label'=>'Placeholder pelacakan','max'=>120],
                    'home_news_title'=>['label'=>'Judul berita beranda','max'=>120],'home_news_subtitle'=>['label'=>'Deskripsi berita beranda','max'=>500],'news_see_all'=>['label'=>'Lihat semua berita','max'=>80],
                    'home_no_news_title'=>['label'=>'Judul berita kosong','max'=>200],'home_no_news_text'=>['label'=>'Teks berita kosong','max'=>500],
                    'stat_residents_label'=>['label'=>'Label penduduk','max'=>80],'stat_families_label'=>['label'=>'Label KK','max'=>80],'stat_area_label'=>['label'=>'Label wilayah','max'=>80],
                    'stat_dusun_label'=>['label'=>'Label dusun','max'=>80],'stats_aria_label'=>['label'=>'Aksesibilitas statistik','max'=>120],
                    'services_eyebrow'=>['label'=>'Label layanan','max'=>80],'services_title'=>['label'=>'Judul layanan','max'=>120],'services_intro'=>['label'=>'Deskripsi layanan','max'=>800],'services_list_title'=>['label'=>'Judul daftar layanan','max'=>120],
                    'complaints_eyebrow'=>['label'=>'Label pengaduan','max'=>80],'complaints_intro'=>['label'=>'Deskripsi pengaduan','max'=>800],'complaints_form_title'=>['label'=>'Judul formulir pengaduan','max'=>120],
                    'complaints_login_text'=>['label'=>'Pesan belum masuk','max'=>500],'complaints_wrong_role_text'=>['label'=>'Pesan akun bukan warga','max'=>500],
                    'complaints_title_label'=>['label'=>'Label judul laporan','max'=>100],'complaints_category_label'=>['label'=>'Label kategori','max'=>100],
                    'complaints_detail_label'=>['label'=>'Label detail laporan','max'=>100],'complaints_submit_label'=>['label'=>'Tombol kirim pengaduan','max'=>100],
                    'tracking_eyebrow'=>['label'=>'Label pelacakan','max'=>80],'tracking_title'=>['label'=>'Judul pelacakan','max'=>120],'tracking_intro'=>['label'=>'Deskripsi pelacakan','max'=>800],
                    'tracking_check_button'=>['label'=>'Tombol periksa','max'=>60],'tracking_not_found'=>['label'=>'Pesan kode tidak ditemukan','max'=>300],
                    'news_eyebrow'=>['label'=>'Label berita','max'=>80],'news_title'=>['label'=>'Judul berita','max'=>120],'news_intro'=>['label'=>'Deskripsi berita','max'=>800],
                    'news_empty_title'=>['label'=>'Judul berita kosong','max'=>200],'news_empty_text'=>['label'=>'Teks berita kosong','max'=>500],
                    'article_notfound_title'=>['label'=>'Judul artikel kosong','max'=>120],'article_notfound_intro'=>['label'=>'Deskripsi artikel kosong','max'=>500],'article_back_label'=>['label'=>'Kembali ke berita','max'=>80],
                    'profile_eyebrow'=>['label'=>'Label profil','max'=>80],'profile_title'=>['label'=>'Judul profil','max'=>120],'profile_intro'=>['label'=>'Deskripsi profil','max'=>800],
                    'profile_history_title'=>['label'=>'Judul sejarah','max'=>120],'profile_potential_title'=>['label'=>'Judul potensi','max'=>120],'profile_contact_title'=>['label'=>'Judul kontak profil','max'=>120],
                    'profile_contact_text'=>['label'=>'Teks kontak profil','max'=>500],'profile_history_fallback'=>['label'=>'Teks sejarah bawaan','max'=>500],'profile_potential_fallback'=>['label'=>'Teks potensi bawaan','max'=>500],
                    'login_eyebrow'=>['label'=>'Label masuk','max'=>80],'login_title_prefix'=>['label'=>'Awalan judul masuk','max'=>100],'login_title_highlight'=>['label'=>'Sorotan judul masuk','max'=>120],
                    'login_intro'=>['label'=>'Deskripsi masuk','max'=>500],'login_form_title'=>['label'=>'Judul formulir masuk','max'=>120],'login_submit_label'=>['label'=>'Tombol masuk','max'=>80],
                    'login_register_prompt'=>['label'=>'Ajakan daftar','max'=>160],'register_eyebrow'=>['label'=>'Label daftar','max'=>80],'register_title_prefix'=>['label'=>'Awalan judul daftar','max'=>100],
                    'register_title_highlight'=>['label'=>'Sorotan judul daftar','max'=>120],'register_intro'=>['label'=>'Deskripsi daftar','max'=>500],'register_form_title'=>['label'=>'Judul formulir daftar','max'=>120],
                    'register_submit_label'=>['label'=>'Tombol daftar','max'=>80],'register_login_prompt'=>['label'=>'Ajakan masuk','max'=>160],'register_password_hint'=>['label'=>'Petunjuk sandi','max'=>120],
                    'register_link_label'=>['label'=>'Tautan daftar','max'=>80],'login_link_label'=>['label'=>'Tautan masuk','max'=>80],
                    'page_title_home'=>['label'=>'Judul tab beranda','max'=>120],'page_title_services'=>['label'=>'Judul tab layanan','max'=>120],
                    'page_title_complaints'=>['label'=>'Judul tab pengaduan','max'=>120],'page_title_track'=>['label'=>'Judul tab pelacakan','max'=>120],
                    'page_title_news'=>['label'=>'Judul tab berita','max'=>120],'page_title_article'=>['label'=>'Judul tab artikel','max'=>120],
                    'page_title_profile'=>['label'=>'Judul tab profil','max'=>120],'page_title_login'=>['label'=>'Judul tab masuk','max'=>120],
                    'page_title_register'=>['label'=>'Judul tab pendaftaran','max'=>120],
                    'umkm_title'=>['label'=>'Judul UMKM','max'=>120],'umkm_empty_title'=>['label'=>'Judul UMKM kosong','max'=>200],'umkm_empty_text'=>['label'=>'Deskripsi UMKM kosong','max'=>500],
                    'map_title'=>['label'=>'Judul peta','max'=>120],'map_intro'=>['label'=>'Deskripsi peta','max'=>800],'map_location_title'=>['label'=>'Judul lokasi peta','max'=>120],
                    'map_link_text'=>['label'=>'Tombol peta','max'=>100],'map_empty_text'=>['label'=>'Deskripsi peta kosong','max'=>500],
                    'transparansi_title'=>['label'=>'Judul transparansi','max'=>120],'transparansi_empty_title'=>['label'=>'Judul laporan kosong','max'=>200],
                    'transparansi_empty_text'=>['label'=>'Deskripsi laporan kosong','max'=>500],'contact_title'=>['label'=>'Judul kontak','max'=>120],
                    'office_address_title'=>['label'=>'Judul alamat kantor','max'=>120],'contact_phone_title'=>['label'=>'Judul telepon/WA','max'=>120],
                    'contact_info_title'=>['label'=>'Judul informasi kontak','max'=>120],'page_unavailable_title'=>['label'=>'Judul 404','max'=>120],
                    'page_unavailable_text'=>['label'=>'Deskripsi 404','max'=>500],'page_home_button'=>['label'=>'Tombol beranda','max'=>80],
                    'services_eta_prefix'=>['label'=>'Awalan estimasi layanan','max'=>120],
                    'services_eta_suffix'=>['label'=>'Akhiran estimasi layanan','max'=>120],
                    'services_apply_button'=>['label'=>'Tombol ajukan layanan','max'=>120],
                    'services_login_for_apply'=>['label'=>'Teks masuk untuk mengajukan','max'=>120],
                    'services_empty_text'=>['label'=>'Pesan saat belum ada layanan','max'=>400],
                    'service_apply_form_title'=>['label'=>'Judul formulir pengajuan','max'=>120],
                    'service_apply_type_label'=>['label'=>'Label jenis layanan','max'=>120],
                    'service_apply_subject_label'=>['label'=>'Label keperluan/subjek','max'=>120],
                    'service_apply_details_label'=>['label'=>'Label keterangan tambahan','max'=>120],
                    'service_apply_attachments_label'=>['label'=>'Label lampiran persyaratan','max'=>200],
                    'service_apply_attachment_hint'=>['label'=>'Petunjuk format lampiran','max'=>300],
                    'service_apply_submit_button'=>['label'=>'Tombol kirim pengajuan','max'=>120],
                    'complaint_category_umum'=>['label'=>'Kategori pengaduan umum','max'=>80],
                    'complaint_category_infrastruktur'=>['label'=>'Kategori pengaduan infrastruktur','max'=>80],
                    'complaint_category_pelayanan'=>['label'=>'Kategori pengaduan pelayanan','max'=>80],
                    'complaint_category_lingkungan'=>['label'=>'Kategori pengaduan lingkungan','max'=>80],
                    'complaint_category_sosial'=>['label'=>'Kategori pengaduan sosial','max'=>80],
                    'tracking_public_note_fallback'=>['label'=>'Pesan saat belum ada catatan pelacakan','max'=>300],
                    'tracking_code_label'=>['label'=>'Label kode pelacakan','max'=>80],
                    'tracking_sent_label'=>['label'=>'Label tanggal pengajuan','max'=>80],
                    'tracking_last_update_label'=>['label'=>'Label pembaruan terakhir','max'=>80],
                    'login_instructions'=>['label'=>'Petunjuk formulir masuk','max'=>400],
                    'login_username_label'=>['label'=>'Label username masuk','max'=>100],
                    'login_password_label'=>['label'=>'Label kata sandi masuk','max'=>100],
                    'register_name_label'=>['label'=>'Label nama lengkap','max'=>100],
                    'register_username_label'=>['label'=>'Label username pendaftaran','max'=>100],
                    'register_email_label'=>['label'=>'Label email pendaftaran','max'=>100],
                    'register_email_hint'=>['label'=>'Petunjuk email opsional','max'=>100],
                    'register_password_label'=>['label'=>'Label kata sandi pendaftaran','max'=>100],
                    'article_body_back_label'=>['label'=>'Tautan kembali dari artikel','max'=>120],
                    'directory_location_label'=>['label'=>'Label lokasi UMKM/wisata','max'=>80],
                    'directory_contact_label'=>['label'=>'Label kontak UMKM/wisata','max'=>80],
                    'map_public_link_intro'=>['label'=>'Pengantar tautan peta','max'=>500],
                    'budget_amount_label'=>['label'=>'Label nilai ringkasan anggaran','max'=>80],
                    'budget_pdf_link_label'=>['label'=>'Tautan dokumen PDF anggaran','max'=>80],
                    'mobile_nav_home'=>['label'=>'Label navigasi HP beranda','max'=>60],
                    'mobile_nav_services'=>['label'=>'Label navigasi HP layanan','max'=>60],
                    'mobile_nav_news'=>['label'=>'Label navigasi HP berita','max'=>60],
                    'mobile_nav_complaints'=>['label'=>'Label navigasi HP pengaduan','max'=>60],
                    'mobile_nav_profile'=>['label'=>'Label navigasi HP profil','max'=>60],
                    'mobile_nav_login'=>['label'=>'Label navigasi HP masuk','max'=>60],
                    'status_submitted'=>['label'=>'Status diajukan','max'=>80],
                    'status_in_review'=>['label'=>'Status sedang diperiksa','max'=>80],
                    'status_revision_requested'=>['label'=>'Status perlu perbaikan','max'=>80],
                    'status_approved'=>['label'=>'Status disetujui','max'=>80],
                    'status_rejected'=>['label'=>'Status ditolak','max'=>80],
                    'status_ready'=>['label'=>'Status siap diambil','max'=>80],
                    'status_completed'=>['label'=>'Status selesai','max'=>80],
                    'status_cancelled'=>['label'=>'Status dibatalkan','max'=>80],
                    'status_received'=>['label'=>'Status diterima','max'=>80],
                    'status_in_progress'=>['label'=>'Status ditindaklanjuti','max'=>80],
                    'status_waiting_reporter'=>['label'=>'Status menunggu keterangan','max'=>80],
                    'status_conflict'=>['label'=>'Status konflik data','max'=>80],
                    'status_resolved'=>['label'=>'Status tuntas','max'=>80],
                    'status_published'=>['label'=>'Status terbit','max'=>80],
                    'status_draft'=>['label'=>'Status draf','max'=>80],
                    'status_archived'=>['label'=>'Status arsip','max'=>80],
                ];
                $values=[];
                foreach($fields as $key=>$meta) {
                    if (!array_key_exists($key, $_POST['content'] ?? [])) continue;
                    $value=trim((string)($_POST['content'][$key] ?? ''));
                    if(mb_strlen($value)>$meta['max']) throw new RuntimeException($meta['label'].' terlalu panjang.');
                    if (in_array($key,['hero_focus_x','hero_focus_y'],true) && (!ctype_digit($value) || (int)$value<0 || (int)$value>100)) {
                        throw new RuntimeException($meta['label'].' harus berupa angka 0 sampai 100.');
                    }
                    $values[$key]=$value;
                }
                if (isset($values['map_public_url']) && $values['map_public_url'] !== '') {
                    $mapUrl = $values['map_public_url'];
                    if (!filter_var($mapUrl, FILTER_VALIDATE_URL) || parse_url($mapUrl, PHP_URL_SCHEME) !== 'https') {
                        throw new RuntimeException('Tautan peta harus berupa URL HTTPS yang valid.');
                    }
                }
                $resetImages=$_POST['reset_site_image'] ?? [];
                if(!is_array($resetImages)) throw new RuntimeException('Pilihan reset gambar tidak valid.');
                foreach(module_site_image_keys() as $imageKey) {
                    $image=upload_public_image($imageKey);
                    if($image) {
                        $values[$imageKey]=$image;
                    } elseif(isset($resetImages[$imageKey])) {
                        if((string)$resetImages[$imageKey]!=='1') throw new RuntimeException('Pilihan reset gambar tidak valid.');
                        $values[$imageKey]='';
                    }
                }
                $operation=(string)($_POST['operation'] ?? 'draft');
                if(!in_array($operation,['draft','publish'],true)) throw new RuntimeException('Pilihan simpan tidak valid.');
                $pdo->beginTransaction();
                foreach($values as $key=>$value) {
                    $pdo->prepare("INSERT INTO site_content_revisions (content_key,content_value,status,editor_id,published_at) VALUES (?,?,?, ?, CASE WHEN ?='published' THEN NOW() ELSE NULL END)")
                       ->execute([$key,$value,$operation==='publish'?'published':'draft',(int)$user['id'],$operation]);
                    if($operation==='publish') {
                        $stmt=$pdo->prepare('SELECT id,content_value FROM site_content WHERE content_key=? LIMIT 1');
                        $stmt->execute([$key]);$old=$stmt->fetch();
                        if($old) {
                            $pdo->prepare('INSERT INTO site_content_versions (content_id,content_value,editor_id) VALUES (?,?,?)')->execute([(int)$old['id'],(string)$old['content_value'],(int)$user['id']]);
                            $pdo->prepare("UPDATE site_content SET content_value=?,value_type=?,status='published',updated_by=? WHERE id=?")->execute([$value,in_array($key,module_site_image_keys(),true)?'image':'text',(int)$user['id'],(int)$old['id']]);
                        } else {
                            $pdo->prepare("INSERT INTO site_content (content_key,content_value,value_type,status,updated_by) VALUES (?,?,?,'published',?)")->execute([$key,$value,in_array($key,module_site_image_keys(),true)?'image':'text',(int)$user['id']]);
                        }
                        $pdo->prepare("UPDATE site_content_revisions SET status='archived' WHERE content_key=? AND status='draft'")->execute([$key]);
                    }
                }
                module_audit_actor($user,$operation==='publish'?'publish_content':'draft_content','site_content',null,null,['keys'=>array_keys($values),'operation'=>$operation]);
                $pdo->commit();
                flash($operation==='publish'?'Perubahan tampilan website diterbitkan.':'Draf tampilan tersimpan. Tampilan publik tidak berubah hingga diterbitkan.');
                redirect_to('admin-site');
            }
            case 'module_save_service': {
                $user = module_require_role(['admin_desa','owner']);
                $name=trim((string)($_POST['name'] ?? ''));
                $slug=trim((string)($_POST['slug'] ?? ''));
                $description=trim((string)($_POST['description'] ?? ''));
                $requirementsText=trim((string)($_POST['requirements'] ?? ''));
                $days=filter_input(INPUT_POST,'estimated_days',FILTER_VALIDATE_INT);
                if(mb_strlen($name)<4 || mb_strlen($name)>160) throw new RuntimeException('Nama jenis layanan harus 4–160 karakter.');
                if(!preg_match('/^[a-z0-9-]{3,160}$/',$slug)) $slug=safe_slug($name);
                if(mb_strlen($description)>4000 || ($days!==false && $days!==null && ($days<0||$days>365))) throw new RuntimeException('Deskripsi atau estimasi hari tidak valid.');
                $requirements=array_values(array_filter(array_map('trim',preg_split('/[\r\n,;]+/',$requirementsText) ?: []),fn($v)=>$v!==''));
                if(count($requirements)>40) throw new RuntimeException('Maksimal 40 persyaratan.');
                $id=filter_input(INPUT_POST,'service_id',FILTER_VALIDATE_INT);
                $published=isset($_POST['is_published'])?1:0;
                if($id) {
                    $beforeStmt=$pdo->prepare('SELECT id,name,slug,is_published FROM service_types WHERE id=?');$beforeStmt->execute([$id]);$before=$beforeStmt->fetch();
                    if(!$before) throw new RuntimeException('Jenis layanan tidak ditemukan.');
                    $stmt=$pdo->prepare('UPDATE service_types SET name=?,slug=?,description=?,requirements=?,estimated_days=?,is_published=? WHERE id=?');
                    $stmt->execute([$name,$slug,$description,json_encode($requirements,JSON_UNESCAPED_UNICODE),$days ?: null,$published,$id]);
                } else {
                    $stmt=$pdo->prepare('INSERT INTO service_types (name,slug,description,requirements,estimated_days,is_published) VALUES (?,?,?,?,?,?)');
                    $stmt->execute([$name,$slug,$description,json_encode($requirements,JSON_UNESCAPED_UNICODE),$days ?: null,$published]);$id=(int)$pdo->lastInsertId();$before=null;
                }
                module_audit_actor($user,'save','service_types',$id,$before ?? null,['name'=>$name,'slug'=>$slug,'is_published'=>$published]);
                flash('Jenis layanan disimpan.'); redirect_to('admin-services');
            }
            case 'module_save_directory': {
                $user = module_require_role(['petugas','admin_desa','owner']);
                $id=filter_input(INPUT_POST,'item_id',FILTER_VALIDATE_INT);
                $kind=(string)($_POST['kind'] ?? 'umkm');
                $title=trim((string)($_POST['title'] ?? ''));
                $slug=trim((string)($_POST['slug'] ?? ''));
                $excerpt=trim((string)($_POST['excerpt'] ?? ''));
                $description=trim((string)($_POST['description'] ?? ''));
                $address=trim((string)($_POST['address'] ?? ''));
                $contact=trim((string)($_POST['contact'] ?? ''));
                $status=(string)($_POST['status'] ?? 'draft');
                if(!in_array($kind,['umkm','wisata'],true) || !in_array($status,['draft','published','archived'],true)) throw new RuntimeException('Jenis atau status konten tidak valid.');
                if(mb_strlen($title)<3 || mb_strlen($title)>220 || mb_strlen($description)<5 || mb_strlen($description)>30000) throw new RuntimeException('Judul atau deskripsi konten belum valid.');
                if(!preg_match('/^[a-z0-9-]{3,220}$/',$slug)) $slug=safe_slug($title).'-'.bin2hex(random_bytes(3));
                $image=upload_public_image('image');
                if($id) {
                    $stmt=$pdo->prepare('SELECT id,title,status,image_key FROM public_directory_items WHERE id=? LIMIT 1');$stmt->execute([$id]);$before=$stmt->fetch();
                    if(!$before) throw new RuntimeException('Konten tidak ditemukan.');
                    $stmt=$pdo->prepare('UPDATE public_directory_items SET kind=?,title=?,slug=?,excerpt=?,description=?,address=?,contact=?,image_key=COALESCE(?,image_key),alt_text=?,status=? WHERE id=?');
                    $stmt->execute([$kind,$title,$slug,mb_substr($excerpt,0,500),$description,mb_substr($address,0,500),mb_substr($contact,0,160),$image,trim((string)($_POST['alt_text'] ?? '')) ?: $title,$status,$id]);
                } else {
                    $stmt=$pdo->prepare('INSERT INTO public_directory_items (kind,title,slug,excerpt,description,address,contact,image_key,alt_text,status,author_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$kind,$title,$slug,mb_substr($excerpt,0,500),$description,mb_substr($address,0,500),mb_substr($contact,0,160),$image,trim((string)($_POST['alt_text'] ?? '')) ?: $title,$status,(int)$user['id']]);
                    $id=(int)$pdo->lastInsertId();$before=null;
                }
                module_audit_actor($user,'save','public_directory_items',$id,$before ?? null,['kind'=>$kind,'title'=>$title,'status'=>$status]);
                flash($status==='published'?'Konten berhasil diterbitkan.':'Konten tersimpan sebagai '.($status==='draft'?'draf':'arsip').'.');
                redirect_to($kind==='wisata'?'admin-directory':'admin-directory');
            }
            case 'module_save_budget': {
                $user = module_require_role(['admin_desa','owner']);
                $id=filter_input(INPUT_POST,'report_id',FILTER_VALIDATE_INT);
                $year=filter_input(INPUT_POST,'fiscal_year',FILTER_VALIDATE_INT);
                $title=trim((string)($_POST['title'] ?? ''));
                $summary=trim((string)($_POST['summary'] ?? ''));
                $amountText=trim((string)($_POST['amount'] ?? ''));
                $amount=null;
                if($amountText!=='') {
                    $normal=str_replace(['.',' '],['',''],$amountText);
                    $normal=str_replace(',','.',$normal);
                    if(!is_numeric($normal) || (float)$normal<0) throw new RuntimeException('Nilai anggaran tidak valid.');
                    $amount=number_format((float)$normal,2,'.','');
                }
                $status=(string)($_POST['status'] ?? 'draft');
                if(!$year || $year<2000 || $year>2100 || mb_strlen($title)<4 || mb_strlen($title)>220 || mb_strlen($summary)<5 || mb_strlen($summary)>20000 || !in_array($status,['draft','published','archived'],true)) throw new RuntimeException('Tahun, judul, ringkasan, atau status belum valid.');
                $pdf=module_upload_public_pdf('report_pdf');
                if($id) {
                    $stmt=$pdo->prepare('SELECT id,title,status,document_key FROM budget_reports WHERE id=? LIMIT 1');$stmt->execute([$id]);$before=$stmt->fetch();
                    if(!$before) throw new RuntimeException('Laporan tidak ditemukan.');
                    $stmt=$pdo->prepare("UPDATE budget_reports SET fiscal_year=?,title=?,summary=?,amount=?,document_key=COALESCE(?,document_key),status=?,published_by=CASE WHEN ?='published' THEN ? ELSE published_by END,published_at=CASE WHEN ?='published' THEN NOW() ELSE published_at END WHERE id=?");
                    $stmt->execute([$year,$title,$summary,$amount,$pdf,$status,$status,(int)$user['id'],$status,$id]);
                } else {
                    $stmt=$pdo->prepare("INSERT INTO budget_reports (fiscal_year,title,summary,amount,document_key,status,published_by,published_at) VALUES (?,?,?,?,?,?,CASE WHEN ?='published' THEN ? ELSE NULL END,CASE WHEN ?='published' THEN NOW() ELSE NULL END)");
                    $stmt->execute([$year,$title,$summary,$amount,$pdf,$status,$status,(int)$user['id'],$status]);$id=(int)$pdo->lastInsertId();$before=null;
                }
                module_audit_actor($user,'save','budget_reports',$id,$before ?? null,['year'=>$year,'title'=>$title,'status'=>$status]);
                flash($status==='published'?'Laporan transparansi diterbitkan.':'Laporan transparansi disimpan sebagai draf/arsip.');
                redirect_to('admin-budget');
            }
            default:
                throw new RuntimeException('Aksi modul tidak dikenal.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception instanceof PDOException) {
            error_log('DESA DIGITAL module operation failed.');
            if ((string)$exception->getCode()==='23000') $message='Data bentrok dengan catatan yang sudah ada. Periksa duplikasi dan data wilayah.';
            else $message='Perubahan belum tersimpan. Periksa data dan ulangi; detail database disembunyikan demi keamanan.';
        } else {
            $message=$exception->getMessage();
        }
        flash($message,'error');
        $fallback = match ($action) {
            'module_create_rt_account','module_update_rt_user','module_create_rw_account','module_create_staff_account','module_reassign_rt_account' => 'admin-users',
            'module_create_dusun','module_create_rw' => 'admin-wilayah',
            'module_save_resident_family','module_add_resident','module_import_residents' => 'rt-data',
            'module_submit_change','module_resubmit_change','module_review_change','module_review_resident' => 'perubahan',
            'module_upload_document','module_review_document' => 'dokumen',
            'module_save_site','module_publish_site_revision','module_restore_site_version' => 'admin-site',
            'module_save_service' => 'admin-services',
            'module_save_directory' => 'admin-directory',
            'module_save_budget' => 'admin-budget',
            default => 'dashboard',
        };
        redirect_to($fallback);
    }
}

function module_serve_export(): void {
    if ((string)($_GET['page'] ?? '') !== 'module-export') return;
    $user=require_login();
    $kind=(string)($_GET['kind'] ?? '');
    $reason=trim((string)($_GET['reason'] ?? ''));
    if(mb_strlen($reason)<4 || mb_strlen($reason)>300) { http_response_code(400); exit('Isi alasan ekspor minimal 4 karakter. Kembali ke menu laporan.'); }
    $parseDate = static function(string $value): ?string {
        $value=trim($value);
        if($value==='') return null;
        if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$value)) throw new RuntimeException('Format tanggal ekspor tidak valid.');
        [$year,$month,$day]=array_map('intval',explode('-',$value));
        if(!checkdate($month,$day,$year)) throw new RuntimeException('Tanggal ekspor tidak valid.');
        return $value;
    };
    try {
        $dateFrom=$parseDate((string)($_GET['from'] ?? ''));
        $dateTo=$parseDate((string)($_GET['to'] ?? ''));
        if($dateFrom && $dateTo && $dateFrom>$dateTo) throw new RuntimeException('Tanggal awal ekspor harus sama dengan atau sebelum tanggal akhir.');
        $statusFilter=trim((string)($_GET['status'] ?? ''));
    } catch (RuntimeException $e) {
        http_response_code(400); exit(e($e->getMessage()));
    }
    $pdo=db();
    $role=(string)($user['role'] ?? '');
    $rows=[];$headers=[];
    $allowedResident=['draft','pending_review','active','moved','deceased','archived'];
    $allowedFamily=['draft','pending_review','active','moved','archived'];
    $allowedRequest=['submitted','in_review','revision_requested','approved','rejected','ready','completed','cancelled'];
    $allowedComplaint=['received','in_review','in_progress','waiting_reporter','resolved','rejected'];
    if(in_array($kind,['residents','families'],true)) {
        if(!in_array($role,['rt','rw','petugas','admin_desa','owner'],true)) { http_response_code(403); exit('Akses ekspor tidak diizinkan.'); }
        $whereParts=[];$params=[];
        if($role==='rt') { $whereParts[]='rt.id=?'; $params[]=(int)$user['rt_id']; }
        elseif($role==='rw') { $whereParts[]='rt.rw_id=?'; $params[]=(int)$user['rw_id']; }
        if($kind==='residents') {
            if($statusFilter!=='' && !in_array($statusFilter,$allowedResident,true)) { http_response_code(400); exit('Status penduduk tidak valid.'); }
            if($statusFilter!=='') { $whereParts[]='r.resident_status=?';$params[]=$statusFilter; }
            if($dateFrom) {$whereParts[]='r.created_at >= ?';$params[]=$dateFrom.' 00:00:00';}
            if($dateTo) {$whereParts[]='r.created_at < DATE_ADD(?, INTERVAL 1 DAY)';$params[]=$dateTo.' 00:00:00';}
            $sql="SELECT r.full_name,CONCAT('************',RIGHT(r.nik,4)) AS nik_masked,r.gender,r.resident_status,d.name AS dusun_name,rw.number_code AS rw_code,rt.number_code AS rt_code,r.created_at FROM residents r JOIN rt_units rt ON rt.id=r.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id".($whereParts?' WHERE '.implode(' AND ',$whereParts):'')." ORDER BY d.name,rw.number_code,rt.number_code,r.full_name";
            $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
            $headers=['Nama','NIK (disamarkan)','Jenis kelamin','Status','Dusun','RW','RT','Tanggal input'];
        } else {
            if($statusFilter!=='' && !in_array($statusFilter,$allowedFamily,true)) { http_response_code(400); exit('Status keluarga tidak valid.'); }
            if($statusFilter!=='') { $whereParts[]='f.status=?';$params[]=$statusFilter; }
            if($dateFrom) {$whereParts[]='f.created_at >= ?';$params[]=$dateFrom.' 00:00:00';}
            if($dateTo) {$whereParts[]='f.created_at < DATE_ADD(?, INTERVAL 1 DAY)';$params[]=$dateTo.' 00:00:00';}
            $sql="SELECT f.id,d.name AS dusun_name,rw.number_code AS rw_code,rt.number_code AS rt_code,f.status,COUNT(r.id) AS jumlah_anggota,f.created_at FROM family_records f JOIN rt_units rt ON rt.id=f.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id LEFT JOIN residents r ON r.family_id=f.id".($whereParts?' WHERE '.implode(' AND ',$whereParts):'')." GROUP BY f.id,d.name,rw.number_code,rt.number_code,f.status,f.created_at ORDER BY d.name,rw.number_code,rt.number_code,f.id";
            $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
            $headers=['ID internal keluarga','Dusun','RW','RT','Status','Jumlah anggota','Tanggal input'];
        }
    } elseif(in_array($kind,['requests','complaints'],true)) {
        if(!in_array($role,['petugas','admin_desa','owner','kepala_desa'],true)) { http_response_code(403); exit('Ekspor surat/pengaduan hanya untuk petugas berwenang.'); }
        $whereParts=[];$params=[];
        if($dateFrom) {$whereParts[]='created_at >= ?';$params[]=$dateFrom.' 00:00:00';}
        if($dateTo) {$whereParts[]='created_at < DATE_ADD(?, INTERVAL 1 DAY)';$params[]=$dateTo.' 00:00:00';}
        if($kind==='requests') {
            if($statusFilter!=='' && !in_array($statusFilter,$allowedRequest,true)) { http_response_code(400); exit('Status pengajuan tidak valid.'); }
            if($statusFilter!=='') {$whereParts[]='sr.status=?';$params[]=$statusFilter;}
            // Qualify all date fields to avoid ambiguity in joins.
            $dateParts=[];$dateParams=[];
            if($dateFrom) {$dateParts[]='sr.created_at >= ?';$dateParams[]=$dateFrom.' 00:00:00';}
            if($dateTo) {$dateParts[]='sr.created_at < DATE_ADD(?, INTERVAL 1 DAY)';$dateParams[]=$dateTo.' 00:00:00';}
            $whereParts=$dateParts;$params=$dateParams;
            if($statusFilter!=='') {$whereParts[]='sr.status=?';$params[]=$statusFilter;}
            $sql='SELECT sr.tracking_code,st.name AS service_name,sr.subject,sr.status,sr.public_status_note,sr.created_at,sr.updated_at FROM service_requests sr JOIN service_types st ON st.id=sr.service_type_id'.($whereParts?' WHERE '.implode(' AND ',$whereParts):'').' ORDER BY sr.created_at DESC';
            $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
            $headers=['Kode pelacakan','Jenis layanan','Subjek','Status','Catatan publik','Dibuat','Diperbarui'];
        } else {
            if($statusFilter!=='' && !in_array($statusFilter,$allowedComplaint,true)) { http_response_code(400); exit('Status pengaduan tidak valid.'); }
            $dateParts=[];$dateParams=[];
            if($dateFrom) {$dateParts[]='c.created_at >= ?';$dateParams[]=$dateFrom.' 00:00:00';}
            if($dateTo) {$dateParts[]='c.created_at < DATE_ADD(?, INTERVAL 1 DAY)';$dateParams[]=$dateTo.' 00:00:00';}
            if($statusFilter!=='') {$dateParts[]='c.status=?';$dateParams[]=$statusFilter;}
            $sql='SELECT c.tracking_code,c.title,c.category,c.status,c.public_response,c.created_at,c.updated_at FROM complaints c'.($dateParts?' WHERE '.implode(' AND ',$dateParts):'').' ORDER BY c.created_at DESC';
            $stmt=$pdo->prepare($sql);$stmt->execute($dateParams);$rows=$stmt->fetchAll();
            $headers=['Kode pelacakan','Judul','Kategori','Status','Tanggapan publik','Dibuat','Diperbarui'];
        }
    } else { http_response_code(400); exit('Jenis ekspor tidak dikenali.'); }

    module_audit_actor($user,'export','report_'.$kind,null,null,['kind'=>$kind,'row_count'=>count($rows),'reason'=>$reason,'date_from'=>$dateFrom,'date_to'=>$dateTo,'status'=>$statusFilter]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="desa-digital-'.$kind.'-'.date('Ymd-His').'.csv"');
    header('Cache-Control: private, no-store, max-age=0');
    $out=fopen('php://output','wb');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,$headers,';','"','\\');
    foreach($rows as $row) {
        $line=[];
        foreach(array_values($row) as $value) {
            if($value===null) $value='';
            elseif(is_array($value) || is_object($value)) $value=json_encode($value,JSON_UNESCAPED_UNICODE);
            else $value=(string)$value;
            $line[]=module_csv_safe($value);
        }
        fputcsv($out,$line,';','"','\\');
    }
    fclose($out);
    exit;
}

function module_serve_private_document(): void {
    if ((string)($_GET['page'] ?? '') !== 'private-document') return;
    $user = require_login();
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if (!$id) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }
    try {
        $stmt=db()->prepare('SELECT d.*,r.rt_id AS resident_rt_id FROM resident_documents d LEFT JOIN residents r ON r.id=d.resident_id WHERE d.id=? LIMIT 1');
        $stmt->execute([$id]);$doc=$stmt->fetch();
        if(!$doc || !module_is_allowed_rt($user,(int)$doc['rt_id'])) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }
        if(!in_array(($user['role'] ?? ''),['rt','petugas','admin_desa','owner'],true)) { http_response_code(403); exit('Akses dokumen ditolak.'); }
        if(($user['role'] ?? '')==='rt' && (int)$doc['uploaded_by']!==(int)$user['id'] && (int)($user['rt_id'] ?? 0)!==(int)$doc['rt_id']) { http_response_code(403); exit('Akses dokumen ditolak.'); }
        $key=(string)$doc['storage_key'];
        if(!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|pdf)$/',$key)) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }
        $path=dirname(__DIR__).'/storage/uploads/'.$key;
        if(!is_file($path)) { http_response_code(404); exit('File dokumen tidak ditemukan pada penyimpanan privat.'); }
        module_audit_actor($user,'download','resident_documents',(int)$id,['status'=>$doc['status']]);
        header('Content-Type: '.$doc['mime_type']);
        header('Content-Length: '.(string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="dokumen-warga-'.$id.'.'.pathinfo($key,PATHINFO_EXTENSION).'"');
        header('Cache-Control: private, no-store, max-age=0');
        readfile($path);
        exit;
    } catch(Throwable $e) {
        error_log('Private document request failed.');
        http_response_code(500); exit('Dokumen belum dapat diakses.');
    }
}

function module_resident_review_decision_allowed(string $status, string $decision): bool {
    return $status === 'pending_review' && in_array($decision, ['approve', 'archive'], true);
}

function module_document_status_transition_allowed(string $from, string $to): bool {
    $transitions = [
        'pending' => ['approved', 'revision_requested', 'rejected', 'archived'],
        'revision_requested' => ['approved', 'revision_requested', 'rejected', 'archived'],
        'approved' => ['archived'],
        'rejected' => ['archived'],
        'archived' => [],
    ];
    return in_array($to, $transitions[$from] ?? [], true);
}

function module_service_status_transition_allowed(string $from, string $to): bool {
    $transitions = [
        'submitted' => ['in_review', 'revision_requested', 'rejected'],
        'in_review' => ['in_review', 'revision_requested', 'approved', 'rejected'],
        'revision_requested' => ['in_review', 'revision_requested', 'rejected'],
        'approved' => ['ready'],
        'ready' => ['completed'],
    ];
    return in_array($to, $transitions[$from] ?? [], true);
}

function module_can_access_service_file(array $user, array $file): bool {
    $role = (string)($user['role'] ?? '');
    if ($role === 'warga') {
        return (int)($user['id'] ?? 0) > 0
            && (int)($file['applicant_id'] ?? 0) === (int)$user['id'];
    }
    if ($role === 'petugas') {
        // Unassigned requests remain in the staff queue; once assigned, only
        // the assigned officer may open private attachments for that request.
        $assignedTo = (int)($file['assigned_to'] ?? 0);
        return $assignedTo === 0 || $assignedTo === (int)($user['id'] ?? 0);
    }
    return in_array($role, ['admin_desa','owner','kepala_desa'], true);
}

function module_serve_private_service_file(): void {
    if ((string)($_GET['page'] ?? '') !== 'service-file') return;
    $user = require_login();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) { http_response_code(404); exit('Lampiran tidak ditemukan.'); }
    try {
        $stmt = db()->prepare(
            'SELECT f.*, sr.applicant_id, sr.assigned_to, sr.status AS request_status
             FROM service_request_files f
             JOIN service_requests sr ON sr.id=f.request_id
             WHERE f.id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $file = $stmt->fetch();
        if (!$file || !module_can_access_service_file($user, $file)) {
            http_response_code(404);
            exit('Lampiran tidak ditemukan.');
        }
        $key = (string)$file['storage_key'];
        if (!preg_match('/^[a-f0-9]{48}\\.(jpg|png|webp|pdf)$/', $key)) {
            http_response_code(404);
            exit('Lampiran tidak ditemukan.');
        }
        $path = dirname(__DIR__) . '/storage/uploads/' . $key;
        if (!is_file($path)) {
            http_response_code(404);
            exit('File lampiran tidak ditemukan pada penyimpanan privat.');
        }
        $mime = (string)$file['mime_type'];
        if (!in_array($mime, ['image/jpeg','image/png','image/webp','application/pdf'], true)) {
            http_response_code(404);
            exit('Format lampiran tidak diizinkan.');
        }
        module_audit_actor($user, 'download', 'service_request_files', (int)$id, [
            'request_status' => (string)$file['request_status']
        ]);
        $extension = pathinfo($key, PATHINFO_EXTENSION);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="lampiran-permohonan-' . (int)$id . '.' . $extension . '"');
        header('Cache-Control: private, no-store, max-age=0');
        readfile($path);
        exit;
    } catch (Throwable $e) {
        error_log('Private service attachment request failed.');
        http_response_code(500);
        exit('Lampiran belum dapat diakses.');
    }
}

function module_service_letter_text_escape(string $text): string {
    $text = preg_replace('/[\\x00-\\x1F\\x7F]/u', ' ', $text) ?? '';
    $converted = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) : false;
    if ($converted !== false) $text = $converted;
    $text = preg_replace('/[^\\x20-\\x7E\\x80-\\xFF]/', '?', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
}

function module_build_service_letter_pdf(array $request, array $site): string {
    $pages = [];
    $commands = [];
    $y = 790;

    $startPage = function () use (&$commands, &$y): void {
        $commands = ['0.02 0.34 0.25 RG 2 w 42 810 m 553 810 l S'];
        $y = 790;
    };
    $finishPage = function () use (&$pages, &$commands): void {
        if ($commands) $pages[] = implode("\n", $commands);
    };
    $writeLine = function (string $text, float $size = 10.5, bool $bold = false, string $align = 'left', float $gap = 16) use (&$commands, &$y, &$startPage, &$finishPage): void {
        if ($y < 80) {
            $finishPage();
            $startPage();
        }
        $text = trim(preg_replace('/[\\r\\n\\t]+/u', ' ', $text) ?? '');
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) : false;
        $plain = $converted !== false ? $converted : $text;
        $escaped = module_service_letter_text_escape($text);
        $width = strlen($plain) * $size * 0.48;
        $x = 52.0;
        if ($align === 'center') $x = max(42.0, (595.0 - $width) / 2.0);
        if ($align === 'right') $x = max(42.0, 545.0 - $width);
        $font = $bold ? 'F2' : 'F1';
        $commands[] = sprintf('BT /%s %.1f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $font, $size, $x, $y, $escaped);
        $y -= $gap;
    };
    $drawRule = function () use (&$commands, &$y): void {
        $commands[] = sprintf('0.35 G 0.7 w 48 %.2f m 547 %.2f l S', $y, $y);
        $y -= 18;
    };
    $writeParagraph = function (string $text, float $size = 10.5, float $gap = 15) use (&$writeLine): void {
        $text = trim($text);
        if ($text === '') return;
        foreach (explode("\n", wordwrap($text, 84, "\n", true)) as $line) {
            $writeLine($line, $size, false, 'left', $gap);
        }
    };

    $startPage();
    $village = trim((string)($site['village_name'] ?? ''));
    $address = trim((string)($site['office_address'] ?? ''));
    $subdistrict = trim((string)($site['village_subdistrict'] ?? ''));
    $regency = trim((string)($site['village_regency'] ?? ''));
    $province = trim((string)($site['village_province'] ?? ''));
    $headName = trim((string)($site['village_head_name'] ?? ''));
    $headTitle = trim((string)($site['village_head_title'] ?? '')) ?: 'Kepala Desa';
    $officePhone = trim((string)($site['office_phone'] ?? ''));

    $writeLine('PEMERINTAH DESA ' . mb_strtoupper($village), 14, true, 'center', 20);
    $area = array_values(array_filter([
        $subdistrict !== '' ? 'KECAMATAN ' . mb_strtoupper($subdistrict) : '',
        $regency !== '' ? 'KABUPATEN/KOTA ' . mb_strtoupper($regency) : '',
        $province !== '' ? mb_strtoupper($province) : '',
    ], static fn(string $part): bool => $part !== ''));
    if ($area) $writeLine(implode('  |  ', $area), 8.5, false, 'center', 14);
    if ($address !== '') $writeLine($address, 9, false, 'center', 13);
    if ($officePhone !== '') $writeLine('Kontak kantor: ' . $officePhone, 9, false, 'center', 14);
    $drawRule();

    $serviceName = trim((string)($request['service_name'] ?? 'Layanan Desa'));
    $writeLine(mb_strtoupper($serviceName), 13, true, 'center', 20);
    $writeLine('Nomor: ' . trim((string)($request['letter_number'] ?? '')), 10, true, 'center', 22);
    $drawRule();

    $approvedDate = !empty($request['approved_at']) ? date('d-m-Y', strtotime((string)$request['approved_at'])) : date('d-m-Y');
    $writeParagraph('Berdasarkan pemeriksaan dan persetujuan pejabat desa, berikut adalah ringkasan surat layanan yang diterbitkan pada ' . $approvedDate . '.', 10.5, 17);
    $writeLine('Nama pemohon: ' . trim((string)($request['applicant_name'] ?? '')), 11, true, 'left', 18);
    $writeLine('Jenis layanan: ' . $serviceName, 10.5, false, 'left', 17);
    $writeParagraph('Perihal: ' . trim((string)($request['subject'] ?? '')), 10.5, 17);
    $writeLine('Kode pelacakan: ' . trim((string)($request['tracking_code'] ?? '')), 9, false, 'left', 16);
    $writeLine('Keterangan permohonan:', 10.5, true, 'left', 16);

    $requestData = json_decode((string)($request['request_data'] ?? '{}'), true);
    $details = is_array($requestData) ? trim((string)($requestData['details'] ?? '')) : '';
    if ($details !== '') $writeParagraph($details, 10, 14);
    else $writeLine('(Tidak ada keterangan tambahan.)', 10, false, 'left', 15);

    $writeParagraph('Surat layanan ini diterbitkan dengan nomor di atas berdasarkan informasi permohonan dan status persetujuan yang tercatat dalam sistem layanan desa.', 10, 16);
    $writeParagraph('Format isi surat ini adalah templat umum. Pastikan substansi, persyaratan, dan dasar hukum disesuaikan dengan jenis layanan serta SOP pemerintah desa sebelum dipakai sebagai surat resmi.', 8.5, 14);

    if ($y < 145) {
        $finishPage();
        $startPage();
    } else {
        $y -= 8;
    }
    $place = $regency !== '' ? $regency : $village;
    $issueDate = !empty($request['letter_issued_at']) ? date('d-m-Y', strtotime((string)$request['letter_issued_at'])) : date('d-m-Y');
    $writeLine($place . ', ' . $issueDate, 10, false, 'right', 17);
    $writeLine('Mengetahui,', 10, false, 'right', 16);
    $writeLine($headTitle, 10, false, 'right', 42);
    $writeLine($headName, 11, true, 'right', 15);
    $writeLine('Diterbitkan melalui Portal DESA DIGITAL', 8, false, 'center', 12);
    $finishPage();

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $pageCount = count($pages);
    for ($i = 0; $i < $pageCount; $i++) {
        $pageObject = 5 + ($i * 2);
        $streamObject = $pageObject + 1;
        $kids[] = $pageObject . ' 0 R';
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $streamObject . ' 0 R >>';
        $stream = $pages[$i];
        $objects[$streamObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    $maxId = max(array_keys($objects));
    for ($id = 1; $id <= $maxId; $id++) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    return $pdf;
}

function module_can_access_service_letter(array $user, array $request): bool {
    $role = (string)($user['role'] ?? '');
    if ($role === 'warga') {
        return (int)($user['id'] ?? 0) > 0
            && (int)($request['applicant_id'] ?? 0) === (int)$user['id'];
    }
    return in_array($role, ['petugas', 'admin_desa', 'owner', 'kepala_desa'], true);
}

function module_serve_service_letter_pdf(): void {
    if ((string)($_GET['page'] ?? '') !== 'service-letter-pdf') return;
    $user = require_login();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) { http_response_code(404); exit('Surat tidak ditemukan.'); }
    try {
        $stmt = db()->prepare(
            'SELECT sr.*, st.name AS service_name, applicant.full_name AS applicant_name
             FROM service_requests sr
             JOIN service_types st ON st.id=sr.service_type_id
             JOIN app_users applicant ON applicant.id=sr.applicant_id
             WHERE sr.id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request || !module_can_access_service_letter($user, $request)) {
            http_response_code(404);
            exit('Surat tidak ditemukan.');
        }
        if (!in_array((string)$request['status'], ['approved','ready','completed'], true) || trim((string)($request['letter_number'] ?? '')) === '') {
            http_response_code(404);
            exit('Surat belum disetujui atau nomor surat belum diterbitkan.');
        }
        $site = json_decode((string)($request['letter_snapshot'] ?? ''), true);
        if (!is_array($site)) {
            http_response_code(409);
            exit('Snapshot kop surat tidak tersedia. Hubungi admin desa untuk pemeriksaan.');
        }
        foreach (['village_name','office_address','village_regency','village_province','village_head_name'] as $required) {
            if (trim((string)($site[$required] ?? '')) === '') {
                http_response_code(409);
                exit('Snapshot kop surat belum lengkap. Hubungi admin desa untuk pemeriksaan.');
            }
        }
        $pdf = module_build_service_letter_pdf($request, $site);
        module_audit_actor($user, 'download_letter_pdf', 'service_requests', (int)$id, ['letter_number' => (string)$request['letter_number']]);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Disposition: attachment; filename="surat-layanan-' . (int)$id . '.pdf"');
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        error_log('Service letter PDF generation failed.');
        http_response_code(500);
        exit('PDF surat belum dapat dibuat.');
    }
}

/**
 * Download a scoped resident register and, optionally, approved KTP/KK files.
 * Full identifiers are decrypted only for the explicitly authorized village admin.
 */
function module_serve_admin_resident_bundle(): void {
    if ((string)($_GET['page'] ?? '') !== 'admin-resident-export') return;
    // Let the initial GET render the filter form; only requests carrying export
    // parameters enter the file-download handler.
    if (!array_key_exists('bundle', $_GET) && !array_key_exists('reason', $_GET)) return;
    $user = require_login();
    if (!in_array((string)($user['role'] ?? ''), ['admin_desa','owner'], true)) {
        http_response_code(403);
        exit('Ekspor lengkap hanya tersedia untuk Admin Desa.');
    }
    $reason = trim((string)($_GET['reason'] ?? ''));
    if (mb_strlen($reason) < 4 || mb_strlen($reason) > 300) {
        http_response_code(400);
        exit('Alasan ekspor wajib diisi minimal 4 karakter.');
    }
    $scope = (string)($_GET['scope'] ?? 'all');
    if (!in_array($scope, ['all','dusun','rw','rt'], true)) {
        http_response_code(400);
        exit('Filter wilayah tidak valid.');
    }
    $scopeId = filter_var($_GET['scope_id'] ?? '', FILTER_VALIDATE_INT);
    if ($scope === 'all') $scopeId = null;
    elseif (!$scopeId || $scopeId < 1) {
        http_response_code(400);
        exit('Pilih wilayah untuk filter ekspor.');
    }
    $bundle = (string)($_GET['bundle'] ?? 'data');
    if (!in_array($bundle, ['data','data_documents'], true)) {
        http_response_code(400);
        exit('Jenis paket ekspor tidak valid.');
    }
    if ($bundle === 'data_documents' && !class_exists('ZipArchive')) {
        http_response_code(503);
        exit('Fitur ZIP belum tersedia di server. Aktifkan ekstensi PHP ZIP lalu coba kembali.');
    }

    $pdo = db();
    $where = '';
    $params = [];
    if ($scope !== 'all') {
        // Validate the ID against the selected active region table before building the export query.
        $scopeChecks = [
            'dusun' => ['SELECT id FROM dusun WHERE id=? AND is_active=1', ' WHERE d.id=?'],
            'rw' => ['SELECT id FROM rw_units WHERE id=? AND is_active=1', ' WHERE rw.id=?'],
            'rt' => ['SELECT rt.id FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id WHERE rt.id=? AND rt.is_active=1 AND rw.is_active=1', ' WHERE rt.id=?'],
        ];
        [$checkSql, $where] = $scopeChecks[$scope];
        $check = $pdo->prepare($checkSql);
        $check->execute([(int)$scopeId]);
        if (!$check->fetchColumn()) {
            http_response_code(400);
            exit('ID wilayah tidak cocok dengan filter atau wilayah sudah tidak aktif.');
        }
        $params[] = (int)$scopeId;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT r.id AS resident_id,r.family_id,r.rt_id,r.full_name,r.nik_cipher,r.birth_date,r.gender,
                    r.relationship_to_head,r.phone,r.resident_status,
                    f.family_number_cipher,f.address,f.status AS family_status,
                    d.name AS dusun_name,rw.number_code AS rw_code,rt.number_code AS rt_code
             FROM residents r
             JOIN family_records f ON f.id=r.family_id
             JOIN rt_units rt ON rt.id=r.rt_id
             JOIN rw_units rw ON rw.id=rt.rw_id
             LEFT JOIN dusun d ON d.id=rw.dusun_id{$where}
             ORDER BY d.name,rw.number_code,rt.number_code,f.id,r.full_name
             LIMIT 10001"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (count($rows) > 10000) {
            http_response_code(413);
            exit('Hasil lebih dari 10.000 penduduk. Pilih wilayah yang lebih kecil.');
        }

        $csvPath = tempnam(sys_get_temp_dir(), 'desa-export-');
        if ($csvPath === false) throw new RuntimeException('Tidak dapat membuat berkas ekspor sementara.');
        $csv = fopen($csvPath, 'wb');
        if (!$csv) { @unlink($csvPath); throw new RuntimeException('Tidak dapat menulis data ekspor.'); }
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, ['Nama lengkap','NIK','Nomor KK','Tanggal lahir','Jenis kelamin','Hubungan keluarga','Telepon','Alamat','Status penduduk','Status keluarga','Dusun','RW','RT','ID penduduk internal','ID keluarga internal'], ';', '"', '\\');

        foreach ($rows as $row) {
            $nik = decrypt_private_identifier((string)$row['nik_cipher']);
            $kk = decrypt_private_identifier((string)$row['family_number_cipher']);
            if ($nik === null || $kk === null) {
                fclose($csv); @unlink($csvPath);
                throw new RuntimeException('Ada identitas terenkripsi yang tidak dapat dibuka. Ekspor dihentikan agar data tidak keliru.');
            }
            $values = [
                $row['full_name'], $nik, $kk, $row['birth_date'], $row['gender'],
                $row['relationship_to_head'], $row['phone'], $row['address'],
                $row['resident_status'], $row['family_status'], $row['dusun_name'],
                $row['rw_code'], $row['rt_code'], $row['resident_id'], $row['family_id']
            ];
            fputcsv($csv, array_map(static fn($v) => module_csv_safe($v === null ? '' : (string)$v), $values), ';', '"', '\\');
        }
        fclose($csv);

        $documentCount = 0;
        $zipPath = null;
        if ($bundle === 'data_documents') {
            $zipPath = tempnam(sys_get_temp_dir(), 'desa-bundle-');
            if ($zipPath === false) { @unlink($csvPath); throw new RuntimeException('Tidak dapat membuat paket ZIP.'); }
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @unlink($csvPath); @unlink($zipPath);
                throw new RuntimeException('Paket ZIP tidak dapat dibuat.');
            }
            $zip->addFile($csvPath, 'data-penduduk.csv');
            $zip->addFromString('README.txt', "Paket data pribadi Desa Digital. Hanya untuk penggunaan administrasi berwenang.\nDokumen yang disertakan berstatus disetujui. Jaga kerahasiaan dan hapus salinan sesuai kebijakan desa.\n");
            $ids = array_map(static fn($r) => (int)$r['resident_id'], $rows);
            $familyIds = array_values(array_unique(array_map(static fn($r) => (int)$r['family_id'], $rows)));
            $rtIds = array_values(array_unique(array_map(static fn($r) => (int)$r['rt_id'], $rows)));
            if ($ids || $familyIds) {
                $idPlaceholders = $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
                $familyPlaceholders = $familyIds ? implode(',', array_fill(0, count($familyIds), '?')) : 'NULL';
                $rtPlaceholders = $rtIds ? implode(',', array_fill(0, count($rtIds), '?')) : 'NULL';
                $docSql = "SELECT id,resident_id,family_id,document_type,storage_key,original_name,mime_type
                           FROM resident_documents
                           WHERE status='approved' AND document_type IN ('ktp','kk')
                           AND rt_id IN ({$rtPlaceholders})
                           AND ((resident_id IN ({$idPlaceholders}) AND document_type='ktp')
                                OR (family_id IN ({$familyPlaceholders}) AND document_type='kk'))
                           ORDER BY document_type,id";
                $docStmt = $pdo->prepare($docSql);
                $docStmt->execute(array_merge($rtIds, $ids, $familyIds));
                $usedNames = [];
                while ($doc = $docStmt->fetch()) {
                    $key = (string)$doc['storage_key'];
                    if (!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|pdf)$/', $key)) continue;
                    $source = dirname(__DIR__) . '/storage/uploads/' . $key;
                    if (!is_file($source)) continue;
                    $type = (string)$doc['document_type'];
                    $entityId = (int)($doc['resident_id'] ?: $doc['family_id']);
                    $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string)$doc['original_name'], PATHINFO_FILENAME)) ?: 'dokumen';
                    $entry = 'dokumen/' . $type . '-' . $entityId . '-' . substr(hash('sha256', $key), 0, 10) . '-' . substr($base, 0, 60) . '.' . $extension;
                    if (isset($usedNames[$entry])) $entry = 'dokumen/' . $type . '-' . (int)$doc['id'] . '.' . $extension;
                    $usedNames[$entry] = true;
                    if ($zip->addFile($source, $entry)) $documentCount++;
                }
            }
            $zip->addFromString('daftar-dokumen.txt', "Jumlah dokumen yang dimasukkan: {$documentCount}\nHanya KTP/KK berstatus disetujui dan berkas yang tersedia di penyimpanan privat yang dimasukkan.\n");
            $zip->close();
        }

        module_audit_actor($user, 'export', 'resident_private_bundle', null, null, [
            'scope' => $scope, 'scope_id' => $scopeId, 'bundle' => $bundle,
            'resident_count' => count($rows), 'document_count' => $documentCount, 'reason' => $reason
        ]);
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        if ($bundle === 'data_documents' && $zipPath !== null) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="desa-digital-data-penduduk-' . date('Ymd-His') . '.zip"');
            header('Content-Length: ' . (string)filesize($zipPath));
            readfile($zipPath);
            @unlink($zipPath);
        } else {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="desa-digital-data-penduduk-' . date('Ymd-His') . '.csv"');
            header('Content-Length: ' . (string)filesize($csvPath));
            readfile($csvPath);
        }
        @unlink($csvPath);
        exit;
    } catch (Throwable $e) {
        error_log('Admin resident bundle export failed.');
        http_response_code(500);
        exit('Ekspor belum berhasil. Periksa konfigurasi server dan log operasional.');
    }
}
