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
    if (($user['role'] ?? '') === 'rt') return (int)($user['rt_id'] ?? 0) === $rtId;
    if (($user['role'] ?? '') === 'rw') {
        $stmt = db()->prepare('SELECT id FROM rt_units WHERE id=? AND rw_id=? AND is_active=1');
        $stmt->execute([$rtId, (int)($user['rw_id'] ?? 0)]);
        return (bool)$stmt->fetchColumn();
    }
    return in_array(($user['role'] ?? ''), ['petugas','admin_desa','kepala_desa'], true);
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

function module_rt_options(): array {
    return db()->query('SELECT rt.id,rt.number_code,rw.number_code AS rw_code,d.name AS dusun_name
        FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id
        WHERE rt.is_active=1 AND rw.is_active=1 ORDER BY d.name,rw.number_code,rt.number_code')->fetchAll();
}

function module_audit_actor(array $user, string $action, string $entity, int|string|null $id, ?array $before=null, ?array $after=null): void {
    audit_log((int)$user['id'], $action, $entity, $id === null ? null : (string)$id, $before, $after);
}

function module_create_rt_account(array $user): never {
    if (($user['role'] ?? '') !== 'admin_desa') throw new RuntimeException('Hanya Admin Desa yang boleh membuat akun RT.');
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
            case 'module_create_dusun': {
                $user = module_require_role(['admin_desa']);
                $name = trim((string)($_POST['name'] ?? ''));
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                if (mb_strlen($name)<2 || mb_strlen($name)>100 || !preg_match('/^[A-Z0-9-]{2,20}$/',$code)) throw new RuntimeException('Nama dusun atau kode tidak valid.');
                $stmt=$pdo->prepare('INSERT INTO dusun (name,code) VALUES (?,?)');
                $stmt->execute([$name,$code]); $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','dusun',$id,null,['name'=>$name,'code'=>$code]);
                flash('Dusun berhasil ditambahkan.'); redirect_to('admin-wilayah');
            }
            case 'module_create_rw': {
                $user = module_require_role(['admin_desa']);
                $dusunId = filter_input(INPUT_POST,'dusun_id',FILTER_VALIDATE_INT);
                $code = trim((string)($_POST['number_code'] ?? ''));
                if (!$dusunId || !preg_match('/^[A-Za-z0-9-]{1,10}$/',$code)) throw new RuntimeException('Pilih dusun dan isi nomor RW.');
                $stmt=$pdo->prepare('INSERT INTO rw_units (dusun_id,number_code) VALUES (?,?)');
                $stmt->execute([$dusunId,$code]); $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'create','rw_units',$id,null,['dusun_id'=>$dusunId,'number_code'=>$code]);
                flash('RW berhasil ditambahkan.'); redirect_to('admin-wilayah');
            }
            case 'module_create_rt_account':
                module_create_rt_account(module_require_role(['admin_desa']));
            case 'module_update_rt_user': {
                $user = module_require_role(['admin_desa']);
                $id=filter_input(INPUT_POST,'user_id',FILTER_VALIDATE_INT);
                $operation=(string)($_POST['operation'] ?? '');
                $stmt=$pdo->prepare("SELECT id,username,full_name,role,rt_id,rw_id,is_active,session_version FROM app_users WHERE id=? AND role IN ('rt','rw','petugas','kepala_desa') LIMIT 1");
                $stmt->execute([$id]); $before=$stmt->fetch();
                if (!$before) throw new RuntimeException('Akun wilayah/petugas tidak ditemukan.');
                $entityType=match($before['role']) { 'rt'=>'rt_account','rw'=>'rw_account','petugas'=>'petugas_account','kepala_desa'=>'kepala_desa_account', default=>'managed_account' };
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
            case 'module_create_staff_account': {
                $user = module_require_role(['admin_desa']);
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
                $user = module_require_role(['admin_desa']);
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
                $user = module_require_role(['admin_desa']);
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
            case 'module_save_resident_family': {
                $user = module_require_role(['rt','admin_desa']);
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
                $user = module_require_role(['rt','admin_desa']);
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
                $user = module_require_role(['petugas','admin_desa']);
                $kind=(string)($_POST['kind'] ?? 'resident');
                $id=filter_input(INPUT_POST,'record_id',FILTER_VALIDATE_INT);
                $decision=(string)($_POST['decision'] ?? '');
                if (!in_array($kind,['resident','family'],true) || !$id || !in_array($decision,['approve','archive'],true)) throw new RuntimeException('Keputusan pemeriksaan tidak valid.');
                if ($kind==='resident') {
                    $stmt=$pdo->prepare("SELECT id,family_id,rt_id,resident_status FROM residents WHERE id=? AND resident_status='pending_review' LIMIT 1"); $stmt->execute([$id]); $row=$stmt->fetch();
                    if (!$row) throw new RuntimeException('Data penduduk tidak ditemukan atau sudah diperiksa.');
                    $new=$decision==='approve'?'active':'archived';
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE residents SET resident_status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$new,(int)$user['id'],$id]);
                    if ($decision==='approve') {
                        $pdo->prepare("UPDATE family_records SET status='active',reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='pending_review'")->execute([(int)$user['id'],(int)$row['family_id']]);
                    }
                    module_audit_actor($user,$decision,'residents',$id,['status'=>$row['resident_status']],['status'=>$new]);
                    $pdo->commit();
                } else {
                    $stmt=$pdo->prepare("SELECT id,rt_id,status FROM family_records WHERE id=? AND status='pending_review' LIMIT 1"); $stmt->execute([$id]); $row=$stmt->fetch();
                    if (!$row) throw new RuntimeException('Data keluarga tidak ditemukan atau sudah diperiksa.');
                    $new=$decision==='approve'?'active':'archived';
                    $pdo->prepare('UPDATE family_records SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$new,(int)$user['id'],$id]);
                    module_audit_actor($user,$decision,'family_records',$id,['status'=>$row['status']],['status'=>$new]);
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
                $stmt=$pdo->prepare("INSERT INTO resident_change_requests (resident_id,family_id,source_rt_id,target_rt_id,change_type,proposed_data,reason,status,submitted_by) VALUES (?,?,?,?,?,?,?,'submitted',?)");
                $stmt->execute([(int)$resident['id'],(int)$resident['family_id'],(int)$resident['rt_id'],$targetRt,$type,json_encode($proposed,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$reason,(int)$user['id']]);
                $id=(int)$pdo->lastInsertId();
                module_audit_actor($user,'submit_change','resident_change_requests',$id,null,['resident_id'=>(int)$resident['id'],'type'=>$type,'source_rt_id'=>(int)$resident['rt_id'],'target_rt_id'=>$targetRt]);
                flash('Usulan perubahan dikirim ke antrean pemeriksaan. Data resmi belum diubah.');
                redirect_to('perubahan');
            }
            case 'module_resubmit_change': {
                $user = module_require_role(['rt']);
                $id=filter_input(INPUT_POST,'change_id',FILTER_VALIDATE_INT);
                $reason=trim((string)($_POST['reason'] ?? ''));
                if(!$id || mb_strlen($reason)<8 || mb_strlen($reason)>1000) throw new RuntimeException('Usulan dan alasan revisi belum valid.');
                $stmt=$pdo->prepare("SELECT cr.*,r.rt_id AS resident_rt_id FROM resident_change_requests cr LEFT JOIN residents r ON r.id=cr.resident_id WHERE cr.id=? AND cr.status='revision_requested' LIMIT 1");
                $stmt->execute([$id]); $request=$stmt->fetch();
                if(!$request || (int)$request['source_rt_id']!==(int)($user['rt_id'] ?? 0) || (int)$request['submitted_by']!==(int)$user['id']) throw new RuntimeException('Usulan revisi tidak ditemukan atau bukan milik wilayah akun ini.');
                $data=json_decode((string)$request['proposed_data'],true) ?: [];
                foreach(['full_name','birth_date','gender','phone','address'] as $key) {
                    if(array_key_exists($key,$_POST)) $data[$key]=trim((string)$_POST[$key]);
                }
                $type=(string)$request['change_type'];
                if($type==='move_rt') {
                    $target=filter_input(INPUT_POST,'target_rt_id',FILTER_VALIDATE_INT);
                    if($target) {
                        if($target===(int)$request['source_rt_id']) throw new RuntimeException('RT tujuan harus berbeda dari RT asal.');
                        $check=$pdo->prepare('SELECT id FROM rt_units WHERE id=? AND is_active=1');$check->execute([$target]);
                        if(!$check->fetchColumn()) throw new RuntimeException('RT tujuan tidak tersedia.');
                        $request['target_rt_id']=$target;
                    }
                    if(!(int)$request['target_rt_id']) throw new RuntimeException('Pilih RT tujuan untuk usulan pindah.');
                }
                if(isset($data['gender']) && !in_array($data['gender'],['L','P','unknown'],true)) throw new RuntimeException('Jenis kelamin tidak valid.');
                $stmt=$pdo->prepare("UPDATE resident_change_requests SET proposed_data=?,target_rt_id=?,reason=?,status='submitted',review_note=NULL,reviewed_by=NULL,reviewed_at=NULL,updated_at=NOW() WHERE id=? AND status='revision_requested'");
                $stmt->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$request['target_rt_id'], $reason,(int)$id]);
                module_audit_actor($user,'resubmit_change','resident_change_requests',(int)$id,['status'=>'revision_requested'],['status'=>'submitted']);
                flash('Usulan revisi dikirim kembali dengan nomor yang sama.');
                redirect_to('perubahan');
            }
            case 'module_review_change': {
                $user = module_require_role(['petugas','admin_desa']);
                $id=filter_input(INPUT_POST,'change_id',FILTER_VALIDATE_INT);
                $decision=(string)($_POST['decision'] ?? '');
                $note=trim((string)($_POST['review_note'] ?? ''));
                if (!$id || !in_array($decision,['approve','revision_requested','reject'],true)) throw new RuntimeException('Keputusan tidak valid.');
                if ($decision==='approve' && ($user['role'] ?? '')!=='admin_desa') throw new RuntimeException('Persetujuan akhir usulan perubahan hanya oleh Admin Desa.');
                if ($decision==='reject' && ($user['role'] ?? '')!=='admin_desa') throw new RuntimeException('Penolakan akhir usulan hanya oleh Admin Desa.');
                if ($decision!=='approve' && mb_strlen($note)<4) throw new RuntimeException('Isi alasan untuk meminta revisi atau menolak.');
                $stmt=$pdo->prepare("SELECT * FROM resident_change_requests WHERE id=? AND status IN ('submitted','in_review','revision_requested') LIMIT 1"); $stmt->execute([$id]); $request=$stmt->fetch();
                if (!$request) throw new RuntimeException('Usulan tidak ditemukan atau sudah selesai.');
                $newStatus=['approve'=>'approved','revision_requested'=>'revision_requested','reject'=>'rejected'][$decision];
                $pdo->beginTransaction();
                if ($decision==='approve') {
                    $data=json_decode((string)$request['proposed_data'],true) ?: [];
                    $resident=$request['resident_id'] ? module_resident_scope($user,(int)$request['resident_id']) : null;
                    if ($request['change_type']==='move_rt') {
                        $target=(int)$request['target_rt_id'];
                        if (!$target) throw new RuntimeException('RT tujuan kosong.');
                        $familyId=(int)$request['family_id'];
                        $pdo->prepare('UPDATE family_records SET rt_id=? WHERE id=?')->execute([$target,$familyId]);
                        $pdo->prepare('UPDATE residents SET rt_id=? WHERE family_id=?')->execute([$target,$familyId]);
                        $pdo->prepare('UPDATE resident_documents SET rt_id=? WHERE family_id=?')->execute([$target,$familyId]);
                        $pdo->prepare('UPDATE resident_documents SET rt_id=? WHERE resident_id IN (SELECT id FROM residents WHERE family_id=?)')->execute([$target,$familyId]);
                    } elseif ($request['change_type']==='deceased' && $resident) {
                        $pdo->prepare("UPDATE residents SET resident_status='deceased',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(int)$user['id'],(int)$resident['id']]);
                    } elseif ($resident) {
                        $allowed=['full_name','birth_date','gender','phone'];
                        $sets=[]; $values=[];
                        foreach($allowed as $key) if(array_key_exists($key,$data)) {$sets[]="{$key}=?";$values[]=$data[$key]!==''?$data[$key]:null;}
                        if($sets){$values[]=(int)$resident['id'];$pdo->prepare('UPDATE residents SET '.implode(',',$sets).',reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute(array_merge(array_slice($values,0,-1),[(int)$user['id'],end($values)]));}
                        if (isset($data['address'])) $pdo->prepare('UPDATE family_records SET address=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$data['address'],(int)$user['id'],(int)$request['family_id']]);
                    }
                }
                $pdo->prepare('UPDATE resident_change_requests SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$newStatus,$note!==''?$note:null,(int)$user['id'],(int)$id]);
                module_audit_actor($user,'review_change','resident_change_requests',$id,['status'=>$request['status']],['status'=>$newStatus,'review_note'=>$note]);
                $pdo->commit();
                flash('Keputusan usulan tersimpan.'); redirect_to('perubahan');
            }
            case 'module_upload_document': {
                $user = module_require_role(['rt','petugas','admin_desa']);
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
                $user = module_require_role(['petugas','admin_desa']);
                $id=filter_input(INPUT_POST,'document_id',FILTER_VALIDATE_INT);
                $status=(string)($_POST['status'] ?? '');
                $note=trim((string)($_POST['review_note'] ?? ''));
                if (!$id || !in_array($status,['approved','revision_requested','rejected','archived'],true)) throw new RuntimeException('Status dokumen tidak valid.');
                if ($status!=='approved' && mb_strlen($note)<4) throw new RuntimeException('Tuliskan alasan untuk dokumen yang perlu diperbaiki/ditolak/diarsipkan.');
                $stmt=$pdo->prepare('SELECT id,rt_id,status,document_type,sha256 FROM resident_documents WHERE id=? LIMIT 1'); $stmt->execute([$id]); $before=$stmt->fetch();
                if (!$before) throw new RuntimeException('Dokumen tidak ditemukan.');
                $pdo->prepare('UPDATE resident_documents SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$status,$note!==''?$note:null,(int)$user['id'],$id]);
                module_audit_actor($user,'review_document','resident_documents',$id,['status'=>$before['status']],['status'=>$status,'note'=>$note]);
                flash('Status dokumen diperbarui.'); redirect_to('dokumen');
            }
            case 'module_publish_site_revision': {
                $user = module_require_role(['admin_desa']);
                $id=filter_input(INPUT_POST,'revision_id',FILTER_VALIDATE_INT);
                if(!$id) throw new RuntimeException('Draf konten tidak valid.');
                $stmt=$pdo->prepare("SELECT id,content_key,content_value,status FROM site_content_revisions WHERE id=? AND status='draft' LIMIT 1");
                $stmt->execute([$id]);$revision=$stmt->fetch();
                if(!$revision) throw new RuntimeException('Draf tidak ditemukan atau sudah diproses.');
                $key=(string)$revision['content_key'];$value=(string)$revision['content_value'];
                if($key==='map_public_url' && $value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https')) throw new RuntimeException('Tautan peta draf tidak valid. Simpan ulang menggunakan URL HTTPS.');
                if($key==='hero_image' && !($value==='assets/village-hero.svg' || preg_match('#^assets/uploads/[a-f0-9]{36}\\.(jpg|png|webp)$#',$value))) throw new RuntimeException('Foto hero draf tidak menunjuk ke berkas gambar yang diizinkan.');
                $pdo->beginTransaction();
                $stmt=$pdo->prepare('SELECT id,content_value,value_type FROM site_content WHERE content_key=? LIMIT 1');
                $stmt->execute([$key]);$current=$stmt->fetch();
                $type=$key==='hero_image'?'image':'text';
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
                $user = module_require_role(['admin_desa']);
                $id=filter_input(INPUT_POST,'version_id',FILTER_VALIDATE_INT);
                if(!$id) throw new RuntimeException('Versi konten tidak valid.');
                $stmt=$pdo->prepare('SELECT v.id,v.content_id,v.content_value,sc.content_key,sc.value_type FROM site_content_versions v JOIN site_content sc ON sc.id=v.content_id WHERE v.id=? LIMIT 1');
                $stmt->execute([$id]);$version=$stmt->fetch();
                if(!$version) throw new RuntimeException('Versi lama tidak ditemukan.');
                $key=(string)$version['content_key'];$value=(string)$version['content_value'];
                if($key==='map_public_url' && $value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https')) throw new RuntimeException('Versi lama berisi tautan peta yang tidak valid. Pulihkan melalui editor setelah memperbaikinya.');
                if($key==='hero_image' && !($value==='assets/village-hero.svg' || preg_match('#^assets/uploads/[a-f0-9]{36}\\.(jpg|png|webp)$#',$value))) throw new RuntimeException('Versi foto tidak menunjuk ke berkas gambar yang diizinkan.');
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
                $user = module_require_role(['admin_desa']);
                $fields=[
                    'village_name'=>['label'=>'Nama desa','max'=>160],
                    'hero_title_line1'=>['label'=>'Judul hero baris pertama','max'=>100],
                    'hero_title_prefix'=>['label'=>'Kata sebelum judul sorotan','max'=>50],
                    'hero_title_highlight'=>['label'=>'Judul hero sorotan','max'=>100],
                    'hero_subtitle'=>['label'=>'Deskripsi hero','max'=>500],
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
                ];
                $values=[];
                foreach($fields as $key=>$meta) {
                    if (!array_key_exists($key, $_POST['content'] ?? [])) continue;
                    $value=trim((string)($_POST['content'][$key] ?? ''));
                    if(mb_strlen($value)>$meta['max']) throw new RuntimeException($meta['label'].' terlalu panjang.');
                    $values[$key]=$value;
                }
                if (isset($values['map_public_url']) && $values['map_public_url'] !== '') {
                    $mapUrl = $values['map_public_url'];
                    if (!filter_var($mapUrl, FILTER_VALIDATE_URL) || parse_url($mapUrl, PHP_URL_SCHEME) !== 'https') {
                        throw new RuntimeException('Tautan peta harus berupa URL HTTPS yang valid.');
                    }
                }
                $image=upload_public_image('hero_image');
                if($image) $values['hero_image']=$image;
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
                            $pdo->prepare("UPDATE site_content SET content_value=?,value_type=?,status='published',updated_by=? WHERE id=?")->execute([$value,$key==='hero_image'?'image':'text',(int)$user['id'],(int)$old['id']]);
                        } else {
                            $pdo->prepare("INSERT INTO site_content (content_key,content_value,value_type,status,updated_by) VALUES (?,?,?,'published',?)")->execute([$key,$value,$key==='hero_image'?'image':'text',(int)$user['id']]);
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
                $user = module_require_role(['admin_desa']);
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
                $user = module_require_role(['petugas','admin_desa']);
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
                $user = module_require_role(['admin_desa']);
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
            'module_save_resident_family','module_add_resident' => 'rt-data',
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
    $pdo=db();
    $role=(string)($user['role'] ?? '');
    if(in_array($kind,['residents','families'],true)) {
        if(!in_array($role,['rt','rw','petugas','admin_desa'],true)) { http_response_code(403); exit('Akses ekspor tidak diizinkan.'); }
        $where='';$params=[];
        if($role==='rt') { $where=' WHERE r.rt_id=?'; $params[]=(int)$user['rt_id']; }
        elseif($role==='rw') { $where=' WHERE rt.rw_id=?'; $params[]=(int)$user['rw_id']; }
        if($kind==='residents') {
            $sql="SELECT r.full_name,CONCAT('************',RIGHT(r.nik,4)) AS nik_masked,r.gender,r.resident_status,d.name AS dusun_name,rw.number_code AS rw_code,rt.number_code AS rt_code,r.created_at FROM residents r JOIN rt_units rt ON rt.id=r.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id".$where." ORDER BY d.name,rw.number_code,rt.number_code,r.full_name";
            $stmt=$pdo->prepare($sql);$stmt->execute($params);
            $rows=$stmt->fetchAll();
            $headers=['Nama','NIK (disamarkan)','Jenis kelamin','Status','Dusun','RW','RT','Tanggal input'];
        } else {
            $sql="SELECT f.id,d.name AS dusun_name,rw.number_code AS rw_code,rt.number_code AS rt_code,f.status,COUNT(r.id) AS jumlah_anggota,f.created_at FROM family_records f JOIN rt_units rt ON rt.id=f.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id LEFT JOIN residents r ON r.family_id=f.id".$where." GROUP BY f.id,d.name,rw.number_code,rt.number_code,f.status,f.created_at ORDER BY d.name,rw.number_code,rt.number_code,f.id";
            $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
            $headers=['ID internal keluarga','Dusun','RW','RT','Status','Jumlah anggota','Tanggal input'];
        }
    } elseif(in_array($kind,['requests','complaints'],true)) {
        if(!in_array($role,['petugas','admin_desa','kepala_desa'],true)) { http_response_code(403); exit('Ekspor surat/pengaduan hanya untuk petugas berwenang.'); }
        if($kind==='requests') {
            $rows=$pdo->query('SELECT sr.tracking_code,st.name AS service_name,sr.subject,sr.status,sr.public_status_note,sr.created_at,sr.updated_at FROM service_requests sr JOIN service_types st ON st.id=sr.service_type_id ORDER BY sr.created_at DESC')->fetchAll();
            $headers=['Kode pelacakan','Jenis layanan','Subjek','Status','Catatan publik','Dibuat','Diperbarui'];
        } else {
            $rows=$pdo->query('SELECT tracking_code,title,category,status,public_response,created_at,updated_at FROM complaints ORDER BY created_at DESC')->fetchAll();
            $headers=['Kode pelacakan','Judul','Kategori','Status','Tanggapan publik','Dibuat','Diperbarui'];
        }
    } else { http_response_code(400); exit('Jenis ekspor tidak dikenali.'); }

    module_audit_actor($user,'export','report_'.$kind,null,null,['kind'=>$kind,'row_count'=>count($rows),'reason'=>$reason]);
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
        if(!in_array(($user['role'] ?? ''),['rt','petugas','admin_desa'],true)) { http_response_code(403); exit('Akses dokumen ditolak.'); }
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
