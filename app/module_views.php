<?php
declare(strict_types=1);

$mvPage = (string)($_GET['page'] ?? '');
$mvUser = current_user();
$mvAllowedRoles = [
    'rt-data' => ['rt','admin_desa','petugas'],
    'rw-rekap' => ['rw','admin_desa','petugas'],
    'dokumen' => ['rt','petugas','admin_desa'],
    'perubahan' => ['rt','petugas','admin_desa'],
    'admin-users' => ['admin_desa'],
    'admin-wilayah' => ['admin_desa'],
    'admin-site' => ['admin_desa'],
    'admin-services' => ['admin_desa'],
    'admin-directory' => ['petugas','admin_desa'],
    'admin-budget' => ['admin_desa'],
    'laporan' => ['rt','rw','petugas','admin_desa','kepala_desa'],
];
function mv_top(string $title, string $description, bool $public = false): void {
    echo '<section class="page-hero"><span class="eyebrow">'.e($public?'Informasi Publik':'Ruang Kerja Terbatas').'</span><h1>'.e($title).'</h1><p>'.e($description).'</p></section>';
}
function mv_tools_nav(array $user): void {
    echo '<nav class="module-toolbar" aria-label="Navigasi panel">';
    echo '<a href="'.e(app_url('dashboard')).'">Dashboard</a>';
    $role=$user['role'] ?? '';
    if(in_array($role,['rt','admin_desa','petugas'],true)) echo '<a href="'.e(app_url('rt-data')).'">Data Warga</a>';
    if(in_array($role,['rw','admin_desa','petugas'],true)) echo '<a href="'.e(app_url('rw-rekap')).'">Rekap RW</a>';
    if(in_array($role,['rt','petugas','admin_desa'],true)) { echo '<a href="'.e(app_url('dokumen')).'">Dokumen Warga</a><a href="'.e(app_url('perubahan')).'">Antrean Verifikasi</a>'; }
    if($role==='admin_desa') {
        echo '<a href="'.e(app_url('admin-users')).'">Akun RT/RW</a><a href="'.e(app_url('admin-wilayah')).'">Wilayah</a><a href="'.e(app_url('admin-services')).'">Jenis Layanan</a><a href="'.e(app_url('admin-directory')).'">UMKM/Wisata</a><a href="'.e(app_url('admin-budget')).'">Transparansi</a><a href="'.e(app_url('admin-site')).'">Tampilan Website</a>';
    }
    if(in_array($role,['rt','rw','petugas','admin_desa','kepala_desa'],true)) echo '<a href="'.e(app_url('laporan')).'">Laporan</a>';
    echo '</nav>';
}
function mv_guard(string $page, ?array $user, array $allowed): bool {
    if(!$user) {
        echo '<section class="panel narrow-panel"><h2>Masuk diperlukan</h2><p>Silakan masuk menggunakan akun yang terdaftar untuk membuka halaman ini.</p><a class="button button-primary" href="'.e(app_url('login')).'">Masuk</a></section>';
        return false;
    }
    if(!in_array((string)($user['role'] ?? ''),$allowed,true)) {
        http_response_code(403);
        echo '<section class="panel narrow-panel"><h2>Akses terbatas</h2><p>Akun ini tidak memiliki izin untuk membuka halaman tersebut. Hak akses diverifikasi di server.</p><a class="button button-outline" href="'.e(app_url('dashboard')).'">Kembali ke dashboard</a></section>';
        return false;
    }
    return true;
}
function mv_rt_select(array $options, string $name='rt_id', ?int $selected=null): void {
    echo '<label>Wilayah RT<select name="'.e($name).'" required><option value="">Pilih RT</option>';
    foreach($options as $rt) {
        $id=(int)$rt['id'];
        $label=trim(($rt['dusun_name'] ?? 'Dusun belum ditetapkan').' · RW '.($rt['rw_code'] ?? '-').' / RT '.($rt['number_code'] ?? '-'));
        echo '<option value="'.$id.'"'.($selected===$id?' selected':'').'>'.e($label).'</option>';
    }
    echo '</select></label>';
}
function mv_mask_tail(?string $value, int $digits=4): string {
    $value=(string)$value;
    if($value==='') return '—';
    return str_repeat('•',max(4,strlen($value)-$digits)).substr($value,-$digits);
}
function mv_db_or_message(): ?PDO {
    try { return db(); }
    catch(Throwable $e) {
        error_log('DESA DIGITAL module view data unavailable.');
        echo '<div class="panel"><h2>Data belum tersedia</h2><p>Database belum siap atau sedang tidak tersedia. Periksa konfigurasi server; detail teknis tidak ditampilkan ke publik.</p></div>';
        return null;
    }
}

/* Public pages use only published rows and intentionally avoid private resident data. */
if (in_array($mvPage,['umkm','peta','transparansi','kontak'],true)) {
    if($mvPage==='umkm') {
        mv_top('UMKM & Wisata','Kenali usaha masyarakat dan tujuan wisata desa yang sudah disetujui untuk dipublikasikan.',true);
        $dbx=mv_db_or_message();
        if($dbx) {
            $rows=$dbx->query("SELECT kind,title,slug,excerpt,description,address,contact,image_key,alt_text FROM public_directory_items WHERE status='published' ORDER BY sort_order,title LIMIT 100")->fetchAll();
            if($rows) {
                echo '<div class="news-grid news-grid-wide">';
                foreach($rows as $item) {
                    echo '<article class="news-card">';
                    if(!empty($item['image_key'])) echo '<div class="news-image"><img src="'.e($item['image_key']).'" alt="'.e($item['alt_text'] ?: $item['title']).'"></div>';
                    else echo '<div class="news-image"><img src="assets/village-hero.svg" alt="Ilustrasi desa"></div>';
                    echo '<div class="news-content"><span class="status-pill neutral">'.e(strtoupper($item['kind'])).'</span><h3>'.e($item['title']).'</h3>';
                    if(!empty($item['excerpt'])) echo '<p>'.e($item['excerpt']).'</p>';
                    echo '<p>'.nl2br(e($item['description'])).'</p>';
                    if(!empty($item['address'])) echo '<p><strong>Lokasi:</strong> '.e($item['address']).'</p>';
                    if(!empty($item['contact'])) echo '<p><strong>Kontak:</strong> '.e($item['contact']).'</p>';
                    echo '</div></article>';
                }
                echo '</div>';
            } else echo '<div class="empty-news"><div><strong>Konten UMKM dan wisata belum diterbitkan.</strong><p>Informasi akan tampil setelah disetujui pengelola desa.</p></div></div>';
        }
    } elseif($mvPage==='peta') {
        mv_top('Peta Desa','Gunakan tautan peta resmi yang telah diatur oleh Admin Desa.',true);
        $mapUrl=site_val('map_public_url','');
        echo '<section class="panel narrow-panel"><span class="profile-icon">⌖</span><h2>Lokasi wilayah desa</h2>';
        if($mapUrl!=='' && filter_var($mapUrl,FILTER_VALIDATE_URL) && parse_url($mapUrl,PHP_URL_SCHEME)==='https') {
            echo '<p>Peta resmi desa tersedia melalui tautan berikut.</p><a class="button button-primary" href="'.e($mapUrl).'" target="_blank" rel="noopener noreferrer">Buka peta desa ↗</a>';
        } else echo '<p>Tautan peta belum diatur. Admin Desa dapat menambahkan tautan peta HTTPS melalui menu Tampilan Website.</p>';
        echo '</section>';
    } elseif($mvPage==='transparansi') {
        mv_top('Transparansi Anggaran','Laporan yang tersedia untuk publik. Informasi ini hanya ditampilkan setelah diterbitkan oleh Admin Desa.',true);
        $dbx=mv_db_or_message();
        if($dbx) {
            $rows=$dbx->query("SELECT fiscal_year,title,summary,amount,document_key,published_at FROM budget_reports WHERE status='published' ORDER BY fiscal_year DESC,title")->fetchAll();
            if($rows) {
                echo '<div class="list-cards">';
                foreach($rows as $item) {
                    echo '<article class="list-card"><span class="service-icon">▤</span><div><h3>'.e($item['title']).' · '.e((string)$item['fiscal_year']).'</h3><p>'.nl2br(e($item['summary'])).'</p>';
                    if($item['amount']!==null) echo '<p><strong>Nilai ringkasan:</strong> Rp '.e(number_format((float)$item['amount'],0,',','.')).'</p>';
                    if(!empty($item['document_key']) && preg_match('#^assets/uploads/[a-f0-9]{36}\.pdf$#',(string)$item['document_key'])) echo '<a class="text-link" href="'.e($item['document_key']).'" target="_blank" rel="noopener noreferrer">Buka dokumen PDF ↗</a>';
                    echo '</div></article>';
                }
                echo '</div>';
            } else echo '<div class="empty-news"><div><strong>Belum ada laporan anggaran yang diterbitkan.</strong><p>Laporan akan tampil setelah diperiksa dan disetujui.</p></div></div>';
        }
    } else {
        mv_top('Kontak Desa','Informasi resmi kantor desa dan cara menghubungi petugas.',true);
        echo '<section class="profile-grid"><div class="panel"><span class="profile-icon">⌂</span><h2>Alamat kantor</h2><p>'.nl2br(e(site_val('office_address','Alamat kantor belum diatur.'))).'</p></div><div class="panel"><span class="profile-icon">☎</span><h2>Telepon / WhatsApp</h2><p>'.e(site_val('office_phone','Nomor kontak belum diatur.')).'</p></div><div class="panel"><span class="profile-icon">✉</span><h2>Informasi layanan</h2><p>'.e(site_val('contact_intro','Gunakan menu layanan atau pengaduan untuk menghubungi pemerintah desa.')).'</p></div></section>';
    }
} else {
    $allowed=$mvAllowedRoles[$mvPage] ?? [];
    if(!mv_guard($mvPage,$mvUser,$allowed)) return;
    mv_tools_nav($mvUser);
    $dbx=mv_db_or_message();
    if(!$dbx) return;

    if($mvPage==='rt-data') {
        mv_top('Data Warga & Keluarga','Tambah data sebagai draf menunggu pemeriksaan, cari catatan yang relevan, dan ajukan perubahan tanpa mengubah data resmi langsung.');
        $role=$mvUser['role'];
        $options=in_array($role,['admin_desa','petugas'],true)?module_rt_options():[];
        if(in_array($role,['rt','admin_desa'],true)) {
            echo '<section class="panel"><h2>Tambah keluarga dan kepala keluarga</h2><p class="muted">NIK/KK adalah data pribadi. Data baru berstatus menunggu pemeriksaan dan tidak tampil di website publik.</p><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_resident_family">';
            if($role==='admin_desa') mv_rt_select(module_rt_options());
            echo '<label>Nomor KK<input name="family_number" inputmode="numeric" maxlength="32" required></label><label>NIK kepala keluarga<input name="nik" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" required></label><label>Nama lengkap<input name="full_name" maxlength="160" required></label><label>Tanggal lahir<input name="birth_date" type="date"></label><label>Jenis kelamin<select name="gender"><option value="unknown">Belum dipilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></label><label>Nomor kontak (opsional)<input name="phone" maxlength="30"></label><label class="full-span">Alamat keluarga<textarea name="address" rows="2" maxlength="1000" required></textarea></label><div class="full-span"><button class="button button-primary" type="submit">Simpan sebagai menunggu pemeriksaan →</button></div></form></section>';
        }
        $where='';$params=[];
        if($role==='rt') {$where=' WHERE r.rt_id=?';$params[]=(int)$mvUser['rt_id'];}
        elseif($role==='rw') {$where=' WHERE rt.rw_id=?';$params[]=(int)$mvUser['rw_id'];}
        $search=trim((string)($_GET['q'] ?? ''));
        if($search!=='') {
            $where .= ($where?' AND ':' WHERE ').'(r.full_name LIKE ? OR RIGHT(r.nik,4)=? OR f.family_number LIKE ?)';
            $params[]='%'.$search.'%';$params[]=$search;$params[]='%'.$search.'%';
        }
        $stmt=$dbx->prepare("SELECT r.id,r.family_id,r.rt_id,r.full_name,RIGHT(r.nik,4) AS nik_last4,r.gender,r.birth_date,r.relationship_to_head,r.resident_status,r.created_at,f.family_number,f.status AS family_status,rt.number_code AS rt_code,rw.number_code AS rw_code,d.name AS dusun_name FROM residents r JOIN family_records f ON f.id=r.family_id JOIN rt_units rt ON rt.id=r.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id{$where} ORDER BY r.created_at DESC LIMIT 100");
        $stmt->execute($params);$residents=$stmt->fetchAll();
        echo '<section class="panel"><div class="section-heading compact-heading"><div><h2>Daftar penduduk</h2><p>NIK ditampilkan sebagian. Pencarian dibatasi ke wilayah tugas untuk akun RT/RW.</p></div></div><form method="get" class="track-form large-track"><input type="hidden" name="page" value="rt-data"><input name="q" value="'.e($search).'" placeholder="Cari nama, 4 digit akhir NIK, atau KK"><button type="submit">Cari</button></form>';
        if($residents) {
            echo '<div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Penduduk</th><th>Wilayah</th><th>Status</th><th>Keluarga</th><th>Aksi</th></tr></thead><tbody>';
            foreach($residents as $r) {
                echo '<tr><td><strong>'.e($r['full_name']).'</strong><small>NIK berakhir '.e($r['nik_last4']).' · '.e($r['gender']).'</small></td><td>'.e(($r['dusun_name']??'Dusun -').' / RW '.($r['rw_code']??'-').' / RT '.($r['rt_code']??'-')).'</td><td><span class="status-pill '.e(status_class($r['resident_status'])).'">'.e(status_text($r['resident_status'])).'</span></td><td>#'.(int)$r['family_id'].'<small>KK '.e(mv_mask_tail($r['family_number'])).'</small></td><td><div class="action-links"><a class="text-link" href="'.e(app_url('dokumen',['resident_id'=>(int)$r['id']])).'">Dokumen</a>';
                if(in_array($role,['rt','admin_desa'],true) && $r['family_status']!=='archived') {
                    echo '<details class="revise-details"><summary>Tambah anggota keluarga</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_add_resident"><input type="hidden" name="family_id" value="'.(int)$r['family_id'].'"><label>NIK anggota (16 digit)<input name="nik" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" required></label><label>Nama lengkap<input name="full_name" maxlength="160" required></label><label>Tanggal lahir<input type="date" name="birth_date"></label><label>Jenis kelamin<select name="gender"><option value="unknown">Belum dipilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></label><label>Hubungan dalam keluarga<input name="relationship_to_head" maxlength="60" placeholder="Contoh: Anak"></label><button class="button button-small button-primary" type="submit">Tambah sebagai menunggu pemeriksaan</button></form></details>';
                }
                if($role==='rt') {
                    $rtOptions=module_rt_options();
                    echo '<details class="revise-details"><summary>Usulkan perubahan</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_submit_change"><input type="hidden" name="resident_id" value="'.(int)$r['id'].'"><label>Jenis usulan<select name="change_type"><option value="update">Koreksi data</option><option value="move_rt">Pindah RT (1 KK)</option><option value="deceased">Perubahan status meninggal</option></select></label><label>Nama koreksi (opsional)<input name="full_name" maxlength="160" value="'.e($r['full_name']).'"></label><label>Alamat baru (opsional)<textarea name="address" rows="2"></textarea></label><label>Nomor kontak baru (opsional)<input name="phone" maxlength="30"></label><label>RT tujuan jika pindah<select name="target_rt_id"><option value="">Pilih bila pindah</option>';
                    foreach($rtOptions as $rt) echo '<option value="'.(int)$rt['id'].'">'.e(($rt['dusun_name']??'Dusun -').' / RW '.$rt['rw_code'].' / RT '.$rt['number_code']).'</option>';
                    echo '</select></label><label>Alasan / dokumen pendukung<textarea name="reason" rows="2" minlength="8" maxlength="1000" required></textarea></label><button class="button button-small button-primary" type="submit">Kirim usulan</button></form></details>';
                }
                if(in_array($role,['admin_desa','petugas'],true) && $r['resident_status']==='pending_review') {
                    echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_review_resident"><input type="hidden" name="kind" value="resident"><input type="hidden" name="record_id" value="'.(int)$r['id'].'"><button class="button button-small button-primary" name="decision" value="approve" type="submit">Sahkan</button><button class="button button-small button-outline" name="decision" value="archive" type="submit">Arsip</button></form>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Belum ada catatan untuk filter ini. Tidak ada data contoh yang ditampilkan sebagai data resmi.</p>';
        echo '</section>';
        if($role==='rt') echo '<section class="panel"><h2>Catatan operasional</h2><p>Data resmi tidak dapat diubah langsung melalui formulir ini. Gunakan “Usulkan perubahan”; petugas akan memeriksa sebelum perubahan disahkan.</p></section>';
    } elseif($mvPage==='rw-rekap') {
        mv_top('Rekap RW','Ringkasan jumlah data dan antrean tanpa menampilkan NIK, nomor KK atau alamat lengkap.');
        $role=$mvUser['role'];$params=[];$where='';
        if($role==='rw') {$where=' WHERE rw.id=?';$params[]=(int)$mvUser['rw_id'];}
        $stmt=$dbx->prepare("SELECT d.name AS dusun_name,rw.id AS rw_id,rw.number_code AS rw_code,rt.id AS rt_id,rt.number_code AS rt_code,COUNT(DISTINCT CASE WHEN f.status='active' THEN f.id END) AS families,COUNT(DISTINCT CASE WHEN r.resident_status='active' THEN r.id END) AS residents,COUNT(DISTINCT CASE WHEN r.resident_status='pending_review' THEN r.id END) AS residents_pending,COUNT(DISTINCT CASE WHEN doc.status='pending' THEN doc.id END) AS docs_pending FROM rw_units rw JOIN rt_units rt ON rt.rw_id=rw.id LEFT JOIN dusun d ON d.id=rw.dusun_id LEFT JOIN family_records f ON f.rt_id=rt.id LEFT JOIN residents r ON r.rt_id=rt.id LEFT JOIN resident_documents doc ON doc.rt_id=rt.id{$where} GROUP BY d.name,rw.id,rw.number_code,rt.id,rt.number_code ORDER BY d.name,rw.number_code,rt.number_code");
        $stmt->execute($params);$rows=$stmt->fetchAll();
        echo '<section class="panel"><h2>Rekap per RT</h2>';
        if($rows) { echo '<div class="table-wrap"><table><thead><tr><th>Wilayah</th><th>Jumlah KK aktif</th><th>Penduduk aktif</th><th>Menunggu pemeriksaan</th><th>Dokumen menunggu</th></tr></thead><tbody>';
            foreach($rows as $r) echo '<tr><td>'.e(($r['dusun_name']??'Dusun -').' / RW '.$r['rw_code'].' / RT '.$r['rt_code']).'</td><td>'.(int)$r['families'].'</td><td>'.(int)$r['residents'].'</td><td>'.(int)$r['residents_pending'].'</td><td>'.(int)$r['docs_pending'].'</td></tr>';
            echo '</tbody></table></div>';
        } else echo '<p>Wilayah belum diatur atau tidak ada data yang bisa ditampilkan.</p>';
        echo '</section>';
    } elseif($mvPage==='dokumen') {
        mv_top('Dokumen Warga','Unggah dan periksa dokumen melalui penyimpanan privat. Berkas tidak ditautkan secara publik.');
        $role=$mvUser['role'];
        $resWhere='';$resParams=[];
        if($role==='rt') {$resWhere=' WHERE r.rt_id=?';$resParams[]=(int)$mvUser['rt_id'];}
        $stmt=$dbx->prepare("SELECT r.id,r.full_name,RIGHT(r.nik,4) AS nik_last4,f.id AS family_id FROM residents r JOIN family_records f ON f.id=r.family_id{$resWhere} ORDER BY r.full_name LIMIT 300");
        $stmt->execute($resParams);$residents=$stmt->fetchAll();
        if(in_array($role,['rt','petugas','admin_desa'],true)) {
            echo '<section class="panel"><h2>Unggah dokumen ke arsip privat</h2><p class="muted">Format: PDF/JPG/PNG/WebP, maksimal 5 MB. Dokumen pribadi tidak boleh dimasukkan ke folder media publik atau GitHub.</p><form method="post" enctype="multipart/form-data" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_upload_document"><label class="full-span">Penduduk<select name="resident_id" required><option value="">Pilih penduduk</option>';
            $selectedId=(int)($_GET['resident_id'] ?? 0);
            foreach($residents as $r) echo '<option value="'.(int)$r['id'].'"'.($selectedId===(int)$r['id']?' selected':'').'>'.e($r['full_name'].' · NIK akhir '.$r['nik_last4'].' · ID KK '.$r['family_id']).'</option>';
            echo '</select></label><label>Jenis dokumen<select name="document_type" required><option value="ktp">KTP</option><option value="kk">Kartu Keluarga</option><option value="akta">Akta</option><option value="surat_pendukung">Surat pendukung</option><option value="lainnya">Lainnya</option></select></label><label>File dokumen<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label><div class="full-span"><button class="button button-primary" type="submit">Unggah secara privat →</button></div></form></section>';
        }
        $where='';$params=[];
        if($role==='rt') {$where=' WHERE d.rt_id=?';$params[]=(int)$mvUser['rt_id'];}
        $status=trim((string)($_GET['status'] ?? ''));
        $type=trim((string)($_GET['type'] ?? ''));
        if($status!=='' && in_array($status,['pending','approved','revision_requested','rejected','archived'],true)) {$where.=($where?' AND ':' WHERE ').'d.status=?';$params[]=$status;}
        if($type!=='' && in_array($type,['ktp','kk','akta','surat_pendukung','lainnya'],true)) {$where.=($where?' AND ':' WHERE ').'d.document_type=?';$params[]=$type;}
        $stmt=$dbx->prepare("SELECT d.id,d.document_type,d.original_name,d.mime_type,d.size_bytes,d.status,d.review_note,d.created_at,d.rt_id,r.full_name,RIGHT(r.nik,4) AS nik_last4,u.full_name AS uploader FROM resident_documents d LEFT JOIN residents r ON r.id=d.resident_id JOIN app_users u ON u.id=d.uploaded_by{$where} ORDER BY d.created_at DESC LIMIT 100");
        $stmt->execute($params);$docs=$stmt->fetchAll();
        echo '<section class="panel"><div class="section-heading compact-heading"><div><h2>Arsip dokumen & antrean pemeriksaan</h2><p>Hanya daftar sesuai hak akses server, maksimum 100 per halaman.</p></div></div><form method="get" class="form-grid"><input type="hidden" name="page" value="dokumen"><label>Status<select name="status"><option value="">Semua status</option>';
        foreach(['pending','approved','revision_requested','rejected','archived'] as $s) echo '<option value="'.e($s).'"'.($status===$s?' selected':'').'>'.e(status_text($s)).'</option>';
        echo '</select></label><label>Jenis<select name="type"><option value="">Semua jenis</option>';
        foreach(['ktp','kk','akta','surat_pendukung','lainnya'] as $t) echo '<option value="'.e($t).'"'.($type===$t?' selected':'').'>'.e(strtoupper($t)).'</option>';
        echo '</select></label><div class="full-span"><button class="button button-small button-outline" type="submit">Terapkan filter</button></div></form>';
        if($docs) { echo '<div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Dokumen</th><th>Pemilik</th><th>Status</th><th>Unggahan</th><th>Aksi</th></tr></thead><tbody>';
            foreach($docs as $d) {
                echo '<tr><td><strong>'.e(strtoupper($d['document_type'])).'</strong><small>'.e($d['original_name']).' · '.number_format((int)$d['size_bytes']/1024,1,',','.').' KB</small></td><td>'.e($d['full_name']??'Data diarsipkan').'<small>NIK akhir '.e($d['nik_last4']??'----').'</small></td><td><span class="status-pill '.e(status_class($d['status'])).'">'.e(status_text($d['status'])).'</span>'.(!empty($d['review_note'])?'<small>'.e($d['review_note']).'</small>':'').'</td><td>'.e(date('d M Y H:i',strtotime($d['created_at']))).'<small>Oleh '.e($d['uploader']).'</small></td><td><div class="action-links"><a class="text-link" href="'.e(app_url('private-document',['id'=>(int)$d['id']])).'">Unduh privat</a>';
                if(in_array($role,['petugas','admin_desa'],true) && in_array($d['status'],['pending','revision_requested'],true)) {
                    echo '<details class="revise-details"><summary>Periksa</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_review_document"><input type="hidden" name="document_id" value="'.(int)$d['id'].'"><label>Keputusan<select name="status"><option value="approved">Terima</option><option value="revision_requested">Minta unggah ulang</option><option value="rejected">Tolak</option><option value="archived">Arsipkan</option></select></label><label>Catatan pemeriksaan<textarea name="review_note" rows="2" placeholder="Wajib jika bukan diterima"></textarea></label><button class="button button-small button-primary" type="submit">Simpan pemeriksaan</button></form></details>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Belum ada dokumen untuk filter ini.</p>';
        echo '</section>';
    } elseif($mvPage==='perubahan') {
        mv_top('Antrean Verifikasi','Periksa usulan perubahan data, perpindahan RT, dan data baru sebelum menjadi catatan resmi.');
        $role=$mvUser['role'];
        if(in_array($role,['petugas','admin_desa'],true)) {
            $stmt=$dbx->query("SELECT r.id,r.full_name,RIGHT(r.nik,4) AS nik_last4,r.resident_status,r.created_at,f.family_number,f.status AS family_status,rt.number_code AS rt_code,rw.number_code AS rw_code,d.name AS dusun_name FROM residents r JOIN family_records f ON f.id=r.family_id JOIN rt_units rt ON rt.id=r.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE r.resident_status='pending_review' ORDER BY r.created_at ASC LIMIT 100");
            $pending=$stmt->fetchAll();
            echo '<section class="panel"><h2>Data penduduk menunggu pemeriksaan</h2>';
            if($pending) { echo '<div class="table-wrap"><table><thead><tr><th>Penduduk</th><th>Wilayah</th><th>Input</th><th>Keputusan</th></tr></thead><tbody>';
                foreach($pending as $r) echo '<tr><td>'.e($r['full_name']).'<small>NIK akhir '.e($r['nik_last4']).'</small></td><td>'.e(($r['dusun_name']??'Dusun -').' / RW '.$r['rw_code'].' / RT '.$r['rt_code']).'</td><td>'.e(date('d M Y',strtotime($r['created_at']))).'</td><td><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_review_resident"><input type="hidden" name="kind" value="resident"><input type="hidden" name="record_id" value="'.(int)$r['id'].'"><button class="button button-small button-primary" name="decision" value="approve" type="submit">Sahkan</button><button class="button button-small button-outline" name="decision" value="archive" type="submit">Arsip</button></form></td></tr>';
                echo '</tbody></table></div>';
            } else echo '<p>Tidak ada data penduduk yang menunggu.</p>';
            echo '</section>';
            $stmt=$dbx->query("SELECT f.id,f.family_number,f.status,f.created_at,rt.number_code AS rt_code,rw.number_code AS rw_code,d.name AS dusun_name,COUNT(r.id) AS residents FROM family_records f JOIN rt_units rt ON rt.id=f.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id LEFT JOIN residents r ON r.family_id=f.id WHERE f.status='pending_review' GROUP BY f.id,f.family_number,f.status,f.created_at,rt.number_code,rw.number_code,d.name ORDER BY f.created_at ASC LIMIT 100");
            $families=$stmt->fetchAll();
            echo '<section class="panel"><h2>Keluarga menunggu pemeriksaan</h2>';
            if($families) { echo '<div class="table-wrap"><table><thead><tr><th>Keluarga</th><th>Wilayah</th><th>Anggota</th><th>Keputusan</th></tr></thead><tbody>';
                foreach($families as $r) echo '<tr><td>ID #'.(int)$r['id'].'<small>KK '.e(mv_mask_tail($r['family_number'])).'</small></td><td>'.e(($r['dusun_name']??'Dusun -').' / RW '.$r['rw_code'].' / RT '.$r['rt_code']).'</td><td>'.(int)$r['residents'].'</td><td><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_review_resident"><input type="hidden" name="kind" value="family"><input type="hidden" name="record_id" value="'.(int)$r['id'].'"><button class="button button-small button-primary" name="decision" value="approve" type="submit">Sahkan KK</button><button class="button button-small button-outline" name="decision" value="archive" type="submit">Arsip</button></form></td></tr>';
                echo '</tbody></table></div>';
            } else echo '<p>Tidak ada keluarga yang menunggu.</p>';
            echo '</section>';
        }
        $where='';$params=[];
        if($role==='rt') {$where=' WHERE cr.source_rt_id=?';$params[]=(int)$mvUser['rt_id'];}
        $stmt=$dbx->prepare("SELECT cr.*,r.full_name,RIGHT(r.nik,4) AS nik_last4,st.number_code AS source_rt,dt.number_code AS target_rt,u.full_name AS submitter FROM resident_change_requests cr LEFT JOIN residents r ON r.id=cr.resident_id JOIN rt_units st ON st.id=cr.source_rt_id LEFT JOIN rt_units dt ON dt.id=cr.target_rt_id JOIN app_users u ON u.id=cr.submitted_by{$where} ORDER BY cr.created_at DESC LIMIT 100");
        $stmt->execute($params);$requests=$stmt->fetchAll();
        echo '<section class="panel"><h2>Usulan perubahan & perpindahan</h2>';
        if($requests) { echo '<div class="table-wrap"><table><thead><tr><th>Usulan</th><th>Alasan / data yang diusulkan</th><th>Status</th><th>Aksi pemeriksa</th></tr></thead><tbody>';
            foreach($requests as $r) {
                $data=json_decode((string)$r['proposed_data'],true) ?: [];
                $summary=[];
                foreach($data as $k=>$v) if($v!=='' && $v!==null) $summary[]=$k.': '.(string)$v;
                echo '<tr><td><strong>'.e($r['full_name']??'Penduduk diarsipkan').'</strong><small>'.e($r['change_type']).' · RT '.e($r['source_rt']).($r['target_rt']?' → RT '.e($r['target_rt']):'').'</small><small>'.e(date('d M Y H:i',strtotime($r['created_at']))).'</small></td><td>'.e($r['reason']).'<small>'.e(implode(' · ',$summary) ?: 'Tidak ada field tambahan').'</small>';
                if(!empty($r['review_note'])) echo '<small>Catatan petugas: '.e($r['review_note']).'</small>';
                echo '</td><td><span class="status-pill '.e(status_class($r['status'])).'">'.e(status_text($r['status'])).'</span></td><td>';
                if($role==='rt' && $r['status']==='revision_requested' && (int)$r['submitted_by']===(int)$mvUser['id']) {
                    echo '<details class="revise-details"><summary>Perbaiki usulan</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_resubmit_change"><input type="hidden" name="change_id" value="'.(int)$r['id'].'">';
                    if($r['change_type']==='update') {
                        echo '<label>Nama lengkap baru (opsional)<input name="full_name" maxlength="160" value="'.e($data['full_name'] ?? '').'"></label><label>Tanggal lahir (opsional)<input type="date" name="birth_date" value="'.e($data['birth_date'] ?? '').'"></label><label>Jenis kelamin (opsional)<select name="gender"><option value="unknown"'.(($data['gender']??'')==='unknown'?' selected':'').'>Belum dipilih</option><option value="L"'.(($data['gender']??'')==='L'?' selected':'').'>Laki-laki</option><option value="P"'.(($data['gender']??'')==='P'?' selected':'').'>Perempuan</option></select></label><label>Telepon (opsional)<input name="phone" maxlength="30" value="'.e($data['phone'] ?? '').'"></label><label>Alamat (opsional)<textarea name="address" rows="2">'.e($data['address'] ?? '').'</textarea></label>';
                    } elseif($r['change_type']==='move_rt') {
                        echo '<label>RT tujuan<select name="target_rt_id"><option value="">Pilih RT tujuan</option>';
                        foreach(module_rt_options() as $targetOption) echo '<option value="'.(int)$targetOption['id'].'"'.((int)$targetOption['id']===(int)$r['target_rt_id']?' selected':'').'>'.e(($targetOption['dusun_name']??'Dusun -').' / RW '.$targetOption['rw_code'].' / RT '.$targetOption['number_code']).'</option>';
                        echo '</select></label>';
                    }
                    echo '<label>Alasan revisi<textarea name="reason" rows="3" minlength="8" maxlength="1000" required>'.e($r['reason']).'</textarea></label><button class="button button-small button-primary" type="submit">Kirim revisi pada usulan yang sama</button></form></details>';
                }
                if(in_array($role,['petugas','admin_desa'],true) && in_array($r['status'],['submitted','in_review','revision_requested'],true)) {
                    echo '<form method="post" class="status-form">'.csrf_field().'<input type="hidden" name="action" value="module_review_change"><input type="hidden" name="change_id" value="'.(int)$r['id'].'"><select name="decision"><option value="revision_requested">Minta revisi</option>';
                    if($role==='admin_desa') echo '<option value="approve">Setujui</option><option value="reject">Tolak</option>';
                    echo '</select><input name="review_note" maxlength="1000" placeholder="Catatan keputusan"><button class="button button-small button-primary" type="submit">Simpan</button></form>';
                } else echo '—';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Tidak ada usulan perubahan.</p>';
        echo '</section>';
    } elseif($mvPage==='admin-wilayah') {
        mv_top('Pengaturan Wilayah','Atur hierarki dusun, RW, dan RT. Penugasan wilayah hanya dapat diubah oleh Admin Desa.');
        $dusuns=$dbx->query('SELECT id,name,code,is_active FROM dusun ORDER BY name')->fetchAll();
        $rws=$dbx->query('SELECT rw.id,rw.number_code,rw.is_active,d.name AS dusun_name FROM rw_units rw LEFT JOIN dusun d ON d.id=rw.dusun_id ORDER BY d.name,rw.number_code')->fetchAll();
        $rts=$dbx->query('SELECT rt.id,rt.number_code,rt.is_active,rw.number_code AS rw_code,d.name AS dusun_name FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id ORDER BY d.name,rw.number_code,rt.number_code')->fetchAll();
        echo '<section class="content-layout"><div class="panel"><h2>Tambah dusun</h2><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_create_dusun"><label>Nama dusun<input name="name" maxlength="100" required></label><label>Kode dusun (huruf/angka)<input name="code" maxlength="20" required></label><button class="button button-primary" type="submit">Tambah dusun</button></form></div><div class="panel"><h2>Tambah RW</h2><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_create_rw"><label>Dusun<select name="dusun_id" required><option value="">Pilih dusun</option>';
        foreach($dusuns as $d) if((int)$d['is_active']===1) echo '<option value="'.(int)$d['id'].'">'.e($d['name'].' ('.$d['code'].')').'</option>';
        echo '</select></label><label>Nomor RW<input name="number_code" maxlength="10" required></label><button class="button button-primary" type="submit">Tambah RW</button></form></div></section>';
        echo '<section class="panel"><h2>Wilayah terdaftar</h2><div class="table-wrap"><table><thead><tr><th>Dusun</th><th>RW</th><th>RT</th><th>Status</th></tr></thead><tbody>';
        foreach($rts as $rt) echo '<tr><td>'.e($rt['dusun_name']??'Belum ditetapkan').'</td><td>'.e($rt['rw_code']).'</td><td>'.e($rt['number_code']).'</td><td>'.((int)$rt['is_active']?'Aktif':'Nonaktif').'</td></tr>';
        echo '</tbody></table></div><p class="muted">Akun Ketua RT dapat dibuat pada menu Akun RT/RW. Saat dibuat, RT baru akan disimpan di bawah RW pilihan.</p></section>';
    } elseif($mvPage==='admin-users') {
        mv_top('Akun RT/RW','Akun dibuat secara individual dan ditugaskan ke wilayah tertentu. Menonaktifkan akun RT mencabut sesi yang sedang aktif.');
        $rws=$dbx->query('SELECT rw.id,rw.number_code,d.name AS dusun_name FROM rw_units rw LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE rw.is_active=1 ORDER BY d.name,rw.number_code')->fetchAll();
        echo '<section class="content-layout"><div class="panel"><h2>Buat akun Ketua RT</h2><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_create_rt_account"><label>Wilayah RW<select name="rw_id" required><option value="">Pilih RW</option>';
        foreach($rws as $rw) echo '<option value="'.(int)$rw['id'].'">'.e(($rw['dusun_name']??'Dusun -').' / RW '.$rw['number_code']).'</option>';
        echo '</select></label><label>Nomor RT<input name="rt_code" maxlength="10" required></label><label>Nama pengurus<input name="full_name" maxlength="160" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" required></label><label>Kata sandi awal (min. 14 karakter)<input type="password" name="password" minlength="14" required autocomplete="new-password"></label><button class="button button-primary" type="submit">Buat akun RT →</button></form></div><div class="panel"><h2>Buat akun Ketua RW</h2><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_create_rw_account"><label>Wilayah RW<select name="rw_id" required><option value="">Pilih RW</option>';
        foreach($rws as $rw) echo '<option value="'.(int)$rw['id'].'">'.e(($rw['dusun_name']??'Dusun -').' / RW '.$rw['number_code']).'</option>';
        echo '</select></label><label>Nama pengurus<input name="full_name" maxlength="160" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" required></label><label>Kata sandi awal (min. 14 karakter)<input type="password" name="password" minlength="14" required autocomplete="new-password"></label><button class="button button-primary" type="submit">Buat akun RW →</button></form></div></section>';
        $users=$dbx->query("SELECT u.id,u.username,u.full_name,u.role,u.rt_id,u.rw_id,u.is_active,u.created_at,rt.number_code AS rt_code,rw.number_code AS rw_code,d.name AS dusun_name FROM app_users u LEFT JOIN rt_units rt ON rt.id=u.rt_id LEFT JOIN rw_units rw ON rw.id=COALESCE(u.rw_id,rt.rw_id) LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE u.role IN ('rt','rw') ORDER BY u.role,d.name,rw.number_code,rt.number_code,u.full_name")->fetchAll();
        echo '<section class="panel"><h2>Daftar akun wilayah</h2>';
        if($users) { echo '<div class="table-wrap"><table><thead><tr><th>Akun</th><th>Penugasan</th><th>Status</th><th>Tindakan admin</th></tr></thead><tbody>';
            foreach($users as $u) {
                echo '<tr><td><strong>'.e($u['full_name']).'</strong><small>@'.e($u['username']).' · '.e(role_label($u['role'])).'</small></td><td>'.e(($u['dusun_name']??'Dusun -').' / RW '.($u['rw_code']??'-').($u['role']==='rt'?' / RT '.($u['rt_code']??'-'):'')).'</td><td><span class="status-pill '.((int)$u['is_active']?'good':'bad').'">'.((int)$u['is_active']?'Aktif':'Nonaktif').'</span></td><td><div class="action-links"><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_update_rt_user"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><button class="button button-small button-outline" name="operation" value="'.((int)$u['is_active']?'deactivate':'activate').'" type="submit">'.((int)$u['is_active']?'Nonaktifkan':'Aktifkan').'</button></form>';
                if($u['role']==='rt') echo '<details class="revise-details"><summary>Reset sandi</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_update_rt_user"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><input type="hidden" name="operation" value="reset_password"><label>Sandi baru (min.14)<input type="password" name="new_password" minlength="14" required autocomplete="new-password"></label><button class="button button-small button-primary" type="submit">Reset</button></form></details>';
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Belum ada akun RT/RW. Buat akun setelah struktur wilayah ditetapkan.</p>';
        echo '<p class="muted">Akun RT tidak dihapus permanen agar jejak audit tetap utuh. Nonaktifkan akun saat pengurus berganti.</p></section>';
    } elseif($mvPage==='admin-site') {
        mv_top('Tampilan Website','Ubah teks hero, sambutan, profil, kontak, foto utama, peta, dan statistik. Simpan sebagai draf untuk pemeriksaan atau terbitkan.');
        $keys=[
          'village_name'=>'Nama desa','hero_title_line1'=>'Judul hero baris pertama','hero_title_prefix'=>'Kata penghubung hero',
          'hero_title_highlight'=>'Judul hero sorotan','hero_subtitle'=>'Deskripsi hero','welcome_title'=>'Judul sambutan',
          'welcome_text'=>'Isi sambutan singkat','hero_image_alt'=>'Teks alternatif foto hero','profil_sejarah'=>'Profil dan sejarah desa',
          'profil_potensi'=>'Potensi desa','umkm_intro'=>'Pengantar UMKM dan wisata','map_public_url'=>'Tautan peta HTTPS',
          'transparansi_intro'=>'Pengantar transparansi anggaran','contact_intro'=>'Petunjuk kontak layanan',
          'office_address'=>'Alamat kantor desa','office_phone'=>'Telepon/WhatsApp kantor','stat_area'=>'Luas wilayah (contoh: 325 ha)',
          'stat_dusun'=>'Label jumlah dusun'
        ];
        echo '<section class="panel"><h2>Editor konten</h2><form method="post" enctype="multipart/form-data" id="site-content-form" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_site">';
        foreach($keys as $key=>$label) {
            $value=site_val($key,'');
            $isLong=in_array($key,['hero_subtitle','welcome_text','profil_sejarah','profil_potensi','umkm_intro','transparansi_intro','contact_intro','office_address'],true);
            $class=$isLong?' class="full-span"':'';
            echo '<label'.$class.'>'.e($label);
            if($isLong) echo '<textarea name="content['.e($key).']" rows="3" maxlength="20000" data-preview-key="'.e($key).'">'.e($value).'</textarea>';
            else echo '<input name="content['.e($key).']" value="'.e($value).'" maxlength="500" data-preview-key="'.e($key).'">';
            echo '</label>';
        }
        echo '<label class="full-span">Unggah foto hero (JPG/PNG/WebP, maks. 5 MB)<input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp" id="hero-image-input"></label>';
        echo '<div class="full-span preview-columns"><div class="preview-desktop"><div class="preview-label">Pratinjau desktop</div><div class="preview-mini"><strong data-preview-target="hero_title_line1">'.e(site_val('hero_title_line1','Layanan desa,')).'</strong><br><span data-preview-target="hero_title_highlight">'.e(site_val('hero_title_highlight','lebih dekat')).'</span><p data-preview-target="hero_subtitle">'.e(site_val('hero_subtitle','Akses berbagai layanan desa dalam satu tempat.')).'</p></div></div><div class="preview-mobile"><div class="preview-label">Pratinjau HP</div><div class="preview-mini"><strong data-preview-target="hero_title_line1">'.e(site_val('hero_title_line1','Layanan desa,')).'</strong><br><span data-preview-target="hero_title_highlight">'.e(site_val('hero_title_highlight','lebih dekat')).'</span><p data-preview-target="hero_subtitle">'.e(site_val('hero_subtitle','Akses berbagai layanan desa dalam satu tempat.')).'</p></div></div></div>';
        echo '<div class="full-span button-row"><button class="button button-outline" type="submit" name="operation" value="draft">Simpan sebagai draf</button><button class="button button-primary" type="submit" name="operation" value="publish" data-confirm="Terbitkan perubahan konten ke website publik?">Terbitkan ke website →</button></div></form></section>';
        $drafts=$dbx->query("SELECT id,content_key,content_value,created_at FROM site_content_revisions WHERE status='draft' ORDER BY created_at DESC LIMIT 50")->fetchAll();
        echo '<section class="panel"><h2>Draf menunggu diterbitkan</h2><p>Periksa isi sebelum menerbitkan. Hanya teks yang diterbitkan akan tampil pada website publik.</p>';
        if($drafts) {
            echo '<div class="list-cards">';
            foreach($drafts as $d) {
                echo '<article class="list-card"><span class="service-icon">✎</span><div><h3>'.e($d['content_key']).'</h3><p>'.e(mb_strimwidth($d['content_value'],0,220,'…')).'</p><small>Diperbarui '.e(date('d M Y H:i',strtotime($d['created_at']))).'</small></div><span class="status-pill warn">Draf</span><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_publish_site_revision"><input type="hidden" name="revision_id" value="'.(int)$d['id'].'"><button class="button button-small button-primary" type="submit" data-confirm="Terbitkan draf '.e($d['content_key']).'?">Terbitkan</button></form></article>';
            }
            echo '</div>';
        } else echo '<p>Tidak ada draf konten yang belum diterbitkan.</p>';
        echo '</section>';
        echo '<section class="panel"><h2>Riwayat versi</h2><p>Versi sebelumnya dapat dipulihkan. Pemulihan akan membuat catatan audit dan menyimpan versi saat ini sebelum diganti.</p>';
        $history=$dbx->query('SELECT v.id,v.content_id,sc.content_key,v.content_value,v.created_at,u.full_name FROM site_content_versions v JOIN site_content sc ON sc.id=v.content_id JOIN app_users u ON u.id=v.editor_id ORDER BY v.created_at DESC LIMIT 30')->fetchAll();
        if($history) {
            echo '<div class="table-wrap"><table><thead><tr><th>Editor</th><th>Konten</th><th>Waktu</th><th>Versi sebelumnya</th><th>Aksi</th></tr></thead><tbody>';
            foreach($history as $h) echo '<tr><td>'.e($h['full_name']).'</td><td>'.e($h['content_key']).'</td><td>'.e(date('d M Y H:i',strtotime($h['created_at']))).'</td><td>'.e(mb_strimwidth($h['content_value'],0,150,'…')).'</td><td><form method="post">'.csrf_field().'<input type="hidden" name="action" value="module_restore_site_version"><input type="hidden" name="version_id" value="'.(int)$h['id'].'"><button class="button button-small button-outline" type="submit" data-confirm="Pulihkan dan terbitkan versi lama untuk '.e($h['content_key']).'?">Pulihkan</button></form></td></tr>';
            echo '</tbody></table></div>';
        } else echo '<p>Riwayat versi akan muncul setelah ada konten yang diganti atau dipulihkan.</p>';
        echo '</section>';
    } elseif($mvPage==='admin-services') {
        mv_top('Jenis Layanan Surat','Tambah atau perbarui layanan, syarat, estimasi pengerjaan, dan status publikasi.');
        $editId=(int)($_GET['edit_service'] ?? 0);$editing=null;
        if($editId) {$stmt=$dbx->prepare('SELECT * FROM service_types WHERE id=? LIMIT 1');$stmt->execute([$editId]);$editing=$stmt->fetch() ?: null;}
        $requirements=$editing?json_decode((string)$editing['requirements'],true):[];
        echo '<section class="panel"><h2>'.($editing?'Edit jenis layanan':'Tambah jenis layanan').'</h2><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_service"><input type="hidden" name="service_id" value="'.(int)($editing['id'] ?? 0).'"><label>Nama layanan<input name="name" maxlength="160" required value="'.e($editing['name'] ?? '').'"></label><label>Slug (a-z, 0-9, tanda hubung)<input name="slug" maxlength="160" value="'.e($editing['slug'] ?? '').'"></label><label class="full-span">Deskripsi<textarea name="description" rows="3" maxlength="4000">'.e($editing['description'] ?? '').'</textarea></label><label class="full-span">Persyaratan (satu per baris)<textarea name="requirements" rows="3">'.e(implode("\n",is_array($requirements)?$requirements:[])).'</textarea></label><label>Estimasi hari kerja<input type="number" name="estimated_days" min="0" max="365" value="'.e((string)($editing['estimated_days'] ?? 3)).'"></label><label class="checkbox-label"><input type="checkbox" name="is_published" value="1"'.(!$editing || (int)($editing['is_published'] ?? 0)===1?' checked':'').'> Tampilkan ke publik</label><div class="full-span button-row"><button class="button button-primary" type="submit">Simpan layanan</button>';
        if($editing) echo '<a class="button button-outline" href="'.e(app_url('admin-services')).'">Batal edit</a>';
        echo '</div></form></section>';
        $rows=$dbx->query('SELECT id,name,slug,estimated_days,is_published,sort_order FROM service_types ORDER BY sort_order,name')->fetchAll();
        echo '<section class="panel"><h2>Daftar layanan</h2>';
        if($rows) {echo '<div class="table-wrap"><table><thead><tr><th>Nama</th><th>Slug</th><th>Estimasi</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';foreach($rows as $r) echo '<tr><td>'.e($r['name']).'</td><td>'.e($r['slug']).'</td><td>'.e((string)($r['estimated_days'] ?? '—')).' hari</td><td><span class="status-pill '.((int)$r['is_published']?'good':'neutral').'">'.((int)$r['is_published']?'Terbit':'Disembunyikan').'</span></td><td><a class="text-link" href="'.e(app_url('admin-services',['edit_service'=>(int)$r['id']])).'">Edit</a></td></tr>';echo '</tbody></table></div>';}
        else echo '<p>Belum ada jenis layanan.</p>';
        echo '</section>';
    } elseif($mvPage==='admin-directory') {
        mv_top('UMKM & Wisata','Kelola konten usaha dan wisata. Item dengan status Terbit akan tersedia pada halaman publik.');
        $editId=(int)($_GET['edit_item'] ?? 0);$editing=null;
        if($editId) {$stmt=$dbx->prepare('SELECT * FROM public_directory_items WHERE id=? LIMIT 1');$stmt->execute([$editId]);$editing=$stmt->fetch() ?: null;}
        echo '<section class="panel"><h2>'.($editing?'Edit item':'Tambah UMKM atau wisata').'</h2><form method="post" enctype="multipart/form-data" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_directory"><input type="hidden" name="item_id" value="'.(int)($editing['id'] ?? 0).'"><label>Jenis<select name="kind"><option value="umkm"'.(($editing['kind']??'umkm')==='umkm'?' selected':'').'>UMKM</option><option value="wisata"'.(($editing['kind']??'')==='wisata'?' selected':'').'>Wisata</option></select></label><label>Status<select name="status">';
        foreach(['draft'=>'Draf','published'=>'Terbit','archived'=>'Arsip'] as $k=>$v) echo '<option value="'.e($k).'"'.(($editing['status']??'draft')===$k?' selected':'').'>'.e($v).'</option>';
        echo '</select></label><label>Nama / judul<input name="title" maxlength="220" required value="'.e($editing['title'] ?? '').'"></label><label>Slug<input name="slug" maxlength="220" value="'.e($editing['slug'] ?? '').'"></label><label class="full-span">Ringkasan<input name="excerpt" maxlength="500" value="'.e($editing['excerpt'] ?? '').'"></label><label class="full-span">Deskripsi<textarea name="description" rows="4" maxlength="30000" required>'.e($editing['description'] ?? '').'</textarea></label><label>Alamat / lokasi<input name="address" maxlength="500" value="'.e($editing['address'] ?? '').'"></label><label>Kontak publik<input name="contact" maxlength="160" value="'.e($editing['contact'] ?? '').'"></label><label>Foto (opsional)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label><label>Teks alternatif foto<input name="alt_text" maxlength="255" value="'.e($editing['alt_text'] ?? '').'"></label><div class="full-span button-row"><button class="button button-primary" type="submit">Simpan item</button>';
        if($editing) echo '<a class="button button-outline" href="'.e(app_url('admin-directory')).'">Batal edit</a>';
        echo '</div></form></section>';
        $rows=$dbx->query('SELECT id,kind,title,status,address,updated_at FROM public_directory_items ORDER BY updated_at DESC LIMIT 100')->fetchAll();
        echo '<section class="panel"><h2>Daftar konten</h2>';
        if($rows) {echo '<div class="table-wrap"><table><thead><tr><th>Item</th><th>Lokasi</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';foreach($rows as $r) echo '<tr><td>'.e(strtoupper($r['kind'])).'<small>'.e($r['title']).'</small></td><td>'.e($r['address'] ?? '—').'</td><td><span class="status-pill '.e(status_class($r['status'])).'">'.e(status_text($r['status'])).'</span></td><td><a class="text-link" href="'.e(app_url('admin-directory',['edit_item'=>(int)$r['id']])).'">Edit</a></td></tr>';echo '</tbody></table></div>';}
        else echo '<p>Belum ada UMKM atau wisata ditambahkan.</p>';
        echo '</section>';
    } elseif($mvPage==='admin-budget') {
        mv_top('Transparansi Anggaran','Terbitkan ringkasan laporan anggaran dan PDF resmi yang memang diizinkan untuk publikasi.');
        $editId=(int)($_GET['edit_report'] ?? 0);$editing=null;
        if($editId) {$stmt=$dbx->prepare('SELECT * FROM budget_reports WHERE id=? LIMIT 1');$stmt->execute([$editId]);$editing=$stmt->fetch() ?: null;}
        echo '<section class="panel"><h2>'.($editing?'Edit laporan':'Tambah laporan transparansi').'</h2><form method="post" enctype="multipart/form-data" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_budget"><input type="hidden" name="report_id" value="'.(int)($editing['id'] ?? 0).'"><label>Tahun anggaran<input type="number" name="fiscal_year" min="2000" max="2100" value="'.e((string)($editing['fiscal_year'] ?? date('Y'))).'" required></label><label>Status<select name="status">';
        foreach(['draft'=>'Draf','published'=>'Terbit','archived'=>'Arsip'] as $k=>$v) echo '<option value="'.e($k).'"'.(($editing['status']??'draft')===$k?' selected':'').'>'.e($v).'</option>';
        echo '</select></label><label class="full-span">Judul laporan<input name="title" maxlength="220" required value="'.e($editing['title'] ?? '').'"></label><label class="full-span">Ringkasan publik<textarea name="summary" rows="4" maxlength="20000" required>'.e($editing['summary'] ?? '').'</textarea></label><label>Nilai total ringkasan (opsional, rupiah)<input name="amount" inputmode="decimal" value="'.e(isset($editing['amount'])?(string)$editing['amount']:'').'" placeholder="Contoh: 125000000"></label><label>Dokumen PDF publik (maks. 10 MB)<input type="file" name="report_pdf" accept="application/pdf"></label><div class="full-span button-row"><button class="button button-primary" type="submit">Simpan laporan</button>';
        if($editing) echo '<a class="button button-outline" href="'.e(app_url('admin-budget')).'">Batal edit</a>';
        echo '</div></form><p class="muted">Pastikan dokumen ini memang layak dipublikasikan dan tidak berisi data pribadi warga sebelum menekan status Terbit.</p></section>';
        $rows=$dbx->query('SELECT id,fiscal_year,title,amount,status,document_key,updated_at FROM budget_reports ORDER BY fiscal_year DESC,title LIMIT 100')->fetchAll();
        echo '<section class="panel"><h2>Daftar laporan</h2>';
        if($rows) {echo '<div class="table-wrap"><table><thead><tr><th>Tahun</th><th>Laporan</th><th>Nilai</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';foreach($rows as $r) echo '<tr><td>'.e((string)$r['fiscal_year']).'</td><td>'.e($r['title']).(!empty($r['document_key'])?'<small><a class="text-link" href="'.e($r['document_key']).'" target="_blank" rel="noopener noreferrer">Lihat PDF</a></small>':'').'</td><td>'.($r['amount']!==null?'Rp '.e(number_format((float)$r['amount'],0,',','.')):'—').'</td><td><span class="status-pill '.e(status_class($r['status'])).'">'.e(status_text($r['status'])).'</span></td><td><a class="text-link" href="'.e(app_url('admin-budget',['edit_report'=>(int)$r['id']])).'">Edit</a></td></tr>';echo '</tbody></table></div>';}
        else echo '<p>Belum ada laporan transparansi.</p>';
        echo '</section>';
    } elseif($mvPage==='laporan') {
        mv_top('Laporan & Ekspor','Ekspor hanya sesuai kewenangan dan cakupan wilayah akun. Ekspor dicatat di audit log, dan NIK/KK lengkap tidak disertakan.');
        $kinds=[
            'residents'=>['label'=>'Data penduduk (ringkas)','desc'=>'Nama, NIK disamarkan, status dan wilayah. Hanya RT/RW/petugas/admin.'],
            'families'=>['label'=>'Rekap keluarga/KK','desc'=>'ID internal, wilayah, status dan jumlah anggota; nomor KK serta alamat lengkap tidak disertakan.'],
            'requests'=>['label'=>'Laporan pengajuan surat','desc'=>'Kode, layanan, status, catatan publik dan tanggal; hanya petugas/admin/kepala desa.'],
            'complaints'=>['label'=>'Laporan pengaduan','desc'=>'Kode, judul, status, tanggapan publik dan tanggal; hanya petugas/admin/kepala desa.'],
        ];
        echo '<section class="content-layout">';
        foreach($kinds as $kind=>$meta) {
            $can=true;
            if(in_array($kind,['residents','families'],true) && !in_array($mvUser['role'],['rt','rw','petugas','admin_desa'],true)) $can=false;
            if(in_array($kind,['requests','complaints'],true) && !in_array($mvUser['role'],['petugas','admin_desa','kepala_desa'],true)) $can=false;
            if(!$can) continue;
            echo '<div class="panel"><h2>'.e($meta['label']).'</h2><p>'.e($meta['desc']).'</p><form method="get" action="index.php" class="form-stack"><input type="hidden" name="page" value="module-export"><input type="hidden" name="kind" value="'.e($kind).'"><label>Alasan kerja untuk audit<input name="reason" maxlength="300" minlength="4" placeholder="Contoh: laporan rapat desa" required></label><button class="button button-primary" type="submit">Unduh CSV →</button></form></div>';
        }
        echo '</section>';
    }
}
