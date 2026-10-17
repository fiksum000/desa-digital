<?php
declare(strict_types=1);

$mvPage = (string)($_GET['page'] ?? '');
$mvUser = current_user();
$mvAllowedRoles = [
    'rt-data' => ['rt','admin_desa','owner','petugas'],
    'rw-rekap' => ['rw','admin_desa','owner','petugas'],
    'dokumen' => ['rt','petugas','admin_desa','owner'],
    'perubahan' => ['rt','petugas','admin_desa','owner'],
    'admin-users' => ['admin_desa','owner'],
    'admin-wilayah' => ['admin_desa','owner'],
    'admin-site' => ['admin_desa','owner'],
    'admin-services' => ['admin_desa','owner'],
    'admin-directory' => ['petugas','admin_desa','owner'],
    'admin-budget' => ['admin_desa','owner'],
    'admin-resident-export' => ['admin_desa','owner'],
    'laporan' => ['rt','rw','petugas','admin_desa','owner','kepala_desa'],
    'notifikasi' => ['warga','rt','rw','petugas','admin_desa','owner','kepala_desa'],
];
function mv_top(string $title, string $description, bool $public = false): void {
    echo '<section class="page-hero"><span class="eyebrow">'.e($public?'Informasi Publik':'Ruang Kerja Terbatas').'</span><h1>'.e($title).'</h1><p>'.e($description).'</p></section>';
}
function mv_tools_nav(array $user): void {
    $role = (string)($user['role'] ?? '');
    echo '<nav class="module-toolbar'.($role === 'rt' ? ' module-toolbar-rt' : '').'" aria-label="Navigasi panel">';
    echo '<a href="'.e(app_url('dashboard')).'">Beranda</a>';
    if ($role === 'rt') {
        echo '<a href="'.e(app_url('rt-data')).'">Data Warga</a>';
        echo '<a href="'.e(app_url('dokumen')).'">Dokumen Warga</a>';
        echo '<a href="'.e(app_url('perubahan')).'">Verifikasi Data</a>';
        echo '<a href="'.e(app_url('laporan')).'">Laporan RT</a>';
        echo '<a href="'.e(app_url('notifikasi')).'">Pengumuman & Notifikasi</a>';
    } else {
        echo '<a href="'.e(app_url('notifikasi')).'">Notifikasi</a>';
        if (in_array($role,['admin_desa','owner','petugas'],true)) echo '<a href="'.e(app_url('rt-data')).'">Data Warga</a>';
        if (in_array($role,['rw','admin_desa','owner','petugas'],true)) echo '<a href="'.e(app_url('rw-rekap')).'">Rekap RW</a>';
        if (in_array($role,['rt','petugas','admin_desa','owner'],true)) {
            echo '<a href="'.e(app_url('dokumen')).'">Dokumen Warga</a><a href="'.e(app_url('perubahan')).'">Antrean Verifikasi</a>';
        }
        if (in_array($role,['admin_desa','owner'],true)) {
            echo '<a href="'.e(app_url('admin-resident-export')).'">Unduh Data Penduduk</a><a href="'.e(app_url('admin-users')).'">Akun RT/RW</a><a href="'.e(app_url('admin-wilayah')).'">Wilayah</a><a href="'.e(app_url('admin-services')).'">Jenis Layanan</a><a href="'.e(app_url('admin-directory')).'">UMKM/Wisata</a><a href="'.e(app_url('admin-budget')).'">Transparansi</a><a href="'.e(app_url('admin-site')).'">Tampilan Website</a>';
        }
        if (in_array($role,['rt','rw','petugas','admin_desa','owner','kepala_desa'],true)) echo '<a href="'.e(app_url('laporan')).'">Laporan</a>';
    }
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
        error_log('DESA MAKASARI module view data unavailable.');
        echo '<div class="panel"><h2>Data belum tersedia</h2><p>Database belum siap atau sedang tidak tersedia. Periksa konfigurasi server; detail teknis tidak ditampilkan ke publik.</p></div>';
        return null;
    }
}

/* Public pages use only published rows and intentionally avoid private resident data. */
if (in_array($mvPage,['umkm','peta','transparansi','kontak'],true)) {
    if($mvPage==='umkm') {
        mv_top(site_val('umkm_title','UMKM & Wisata'),site_val('umkm_intro','Kenali usaha masyarakat dan tujuan wisata desa yang sudah disetujui untuk dipublikasikan.'),true);
        $dbx=mv_db_or_message();
        if($dbx) {
            $rows=$dbx->query("SELECT kind,title,slug,excerpt,description,address,contact,image_key,alt_text FROM public_directory_items WHERE status='published' ORDER BY sort_order,title LIMIT 100")->fetchAll();
            if($rows) {
                echo '<div class="news-grid news-grid-wide">';
                foreach($rows as $item) {
                    echo '<article class="news-card">';
                    if(!empty($item['image_key'])) echo '<div class="news-image"><img src="'.e(public_image_src($item['image_key'],site_image_val('news_fallback_image','assets/village-hero.svg'))).'" alt="'.e($item['alt_text'] ?: $item['title']).'"></div>';
                    else echo '<div class="news-image"><img src="'.e(site_image_val('news_fallback_image','assets/village-hero.svg')).'" alt="Ilustrasi desa"></div>';
                    echo '<div class="news-content"><span class="status-pill neutral">'.e(strtoupper($item['kind'])).'</span><h3>'.e($item['title']).'</h3>';
                    if(!empty($item['excerpt'])) echo '<p>'.e($item['excerpt']).'</p>';
                    echo '<p>'.nl2br(e($item['description'])).'</p>';
                    if(!empty($item['address'])) echo '<p><strong>'.e(site_val('directory_location_label','Lokasi:')).'</strong> '.e($item['address']).'</p>';
                    if(!empty($item['contact'])) echo '<p><strong>'.e(site_val('directory_contact_label','Kontak:')).'</strong> '.e($item['contact']).'</p>';
                    echo '</div></article>';
                }
                echo '</div>';
            } else echo '<div class="empty-news"><div><strong>'.e(site_val('umkm_empty_title','Konten UMKM dan wisata belum diterbitkan.')).'</strong><p>'.e(site_val('umkm_empty_text','Informasi akan tampil setelah disetujui pengelola desa.')).'</p></div></div>';
        }
    } elseif($mvPage==='peta') {
        mv_top(site_val('map_title','Peta Desa'),site_val('map_intro','Gunakan tautan peta resmi yang telah diatur oleh Admin Desa.'),true);
        $mapUrl=site_val('map_public_url','');
        echo '<section class="panel narrow-panel"><span class="profile-icon">⌖</span><h2>'.e(site_val('map_location_title','Lokasi wilayah desa')).'</h2>';
        if($mapUrl!=='' && filter_var($mapUrl,FILTER_VALIDATE_URL) && parse_url($mapUrl,PHP_URL_SCHEME)==='https') {
            echo '<p>'.e(site_val('map_public_link_intro','Peta resmi desa tersedia melalui tautan berikut.')).'</p><a class="button button-primary" href="'.e($mapUrl).'" target="_blank" rel="noopener noreferrer">'.e(site_val('map_link_text','Buka peta desa ↗')).'</a>';
        } else echo '<p>'.e(site_val('map_empty_text','Tautan peta belum diatur. Admin Desa dapat menambahkan tautan peta HTTPS melalui menu Tampilan Website.')).'</p>';
        echo '</section>';
    } elseif($mvPage==='transparansi') {
        mv_top(site_val('transparansi_title','Transparansi Anggaran'),site_val('transparansi_intro','Laporan yang tersedia untuk publik. Informasi ini hanya ditampilkan setelah diterbitkan oleh Admin Desa.'),true);
        $dbx=mv_db_or_message();
        if($dbx) {
            $rows=$dbx->query("SELECT fiscal_year,title,summary,amount,document_key,published_at FROM budget_reports WHERE status='published' ORDER BY fiscal_year DESC,title")->fetchAll();
            if($rows) {
                echo '<div class="list-cards">';
                foreach($rows as $item) {
                    echo '<article class="list-card"><span class="service-icon">▤</span><div><h3>'.e($item['title']).' · '.e((string)$item['fiscal_year']).'</h3><p>'.nl2br(e($item['summary'])).'</p>';
                    if($item['amount']!==null) echo '<p><strong>'.e(site_val('budget_amount_label','Nilai ringkasan:')).'</strong> Rp '.e(number_format((float)$item['amount'],0,',','.')).'</p>';
                    if(!empty($item['document_key']) && preg_match('#^assets/uploads/[a-f0-9]{36}\.pdf$#',(string)$item['document_key'])) echo '<a class="text-link" href="'.e($item['document_key']).'" target="_blank" rel="noopener noreferrer">'.e(site_val('budget_pdf_link_label','Buka dokumen PDF ↗')).'</a>';
                    echo '</div></article>';
                }
                echo '</div>';
            } else echo '<div class="empty-news"><div><strong>'.e(site_val('transparansi_empty_title','Belum ada laporan anggaran yang diterbitkan.')).'</strong><p>'.e(site_val('transparansi_empty_text','Laporan akan tampil setelah diperiksa dan disetujui.')).'</p></div></div>';
        }
    } else {
        mv_top(site_val('contact_title','Kontak Desa'),site_val('contact_intro','Informasi resmi kantor desa dan cara menghubungi petugas.'),true);
        echo '<section class="profile-grid"><div class="panel"><span class="profile-icon">⌂</span><h2>'.e(site_val('office_address_title','Alamat kantor')).'</h2><p>'.nl2br(e(site_val('office_address','Alamat kantor belum diatur.'))).'</p></div><div class="panel"><span class="profile-icon">☎</span><h2>'.e(site_val('contact_phone_title','Telepon / WhatsApp')).'</h2><p>'.e(site_val('office_phone','Nomor kontak belum diatur.')).'</p></div><div class="panel"><span class="profile-icon">✉</span><h2>'.e(site_val('contact_info_title','Informasi layanan')).'</h2><p>'.e(site_val('contact_intro','Gunakan menu layanan atau pengaduan untuk menghubungi pemerintah desa.')).'</p></div></section>';
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
        $options=in_array($role,['admin_desa','owner','petugas'],true)?module_rt_options():[];
        if(in_array($role,['rt','admin_desa','owner'],true)) {
            echo '<section class="panel"><h2>Impor penduduk dari CSV</h2><p>Gunakan template UTF-8. Maksimal 1.000 baris per file. Data hasil impor masuk sebagai menunggu pemeriksaan, bukan langsung menjadi data resmi. NIK/KK ganda atau keluarga di wilayah lain akan ditolak untuk ditinjau admin.</p><p><a class="text-link" href="assets/template-import-warga.csv" download>Unduh template CSV →</a></p><form method="post" enctype="multipart/form-data" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_import_residents">';
            if(in_array($role,['admin_desa','owner'],true)) mv_rt_select(module_rt_options());
            echo '<label class="full-span">Pilih file CSV UTF-8 (maks. 5 MB)<input type="file" name="import_csv" accept=".csv,text/csv,text/plain" required></label><div class="full-span"><button class="button button-primary" type="submit" data-confirm="Impor data sekarang? Data yang masuk akan tetap menunggu pemeriksaan.">Impor CSV →</button></div></form></section>';
            echo '<section class="panel"><h2>Tambah keluarga dan kepala keluarga</h2><p class="muted">NIK/KK adalah data pribadi. Data baru berstatus menunggu pemeriksaan dan tidak tampil di website publik.</p><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_resident_family">';
            if(in_array($role,['admin_desa','owner'],true)) mv_rt_select(module_rt_options());
            echo '<label>Nomor KK<input name="family_number" inputmode="numeric" maxlength="32" required></label><label>NIK kepala keluarga<input name="nik" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" required></label><label>Nama lengkap<input name="full_name" maxlength="160" required></label><label>Tanggal lahir<input name="birth_date" type="date"></label><label>Jenis kelamin<select name="gender"><option value="unknown">Belum dipilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></label><label>Nomor kontak (opsional)<input name="phone" maxlength="30"></label><label class="full-span">Alamat keluarga<textarea name="address" rows="2" maxlength="1000" required></textarea></label><div class="full-span"><button class="button button-primary" type="submit">Simpan sebagai menunggu pemeriksaan →</button></div></form></section>';
        }
        $where='';$params=[];
        if($role==='rt') {$where=' WHERE r.rt_id=?';$params[]=(int)$mvUser['rt_id'];}
        elseif($role==='rw') {$where=' WHERE rt.rw_id=?';$params[]=(int)$mvUser['rw_id'];}
        $search=trim((string)($_GET['q'] ?? ''));
        if($search!=='') {
            $searchTail = preg_match('/^[0-9]{4,16}$/', $search) ? substr($search, -4) : $search;
            $where .= ($where?' AND ':' WHERE ').'(r.full_name LIKE ? OR RIGHT(r.nik,4)=? OR RIGHT(f.family_number,4)=?)';
            $params[]='%'.$search.'%';$params[]=$searchTail;$params[]=$searchTail;
        }
        $countStmt=$dbx->prepare("SELECT COUNT(*) FROM residents r JOIN family_records f ON f.id=r.family_id JOIN rt_units rt ON rt.id=r.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id{$where}");
        $countStmt->execute($params);$residentsTotal=(int)$countStmt->fetchColumn();
        $residentsPage=max(1,(int)($_GET['p'] ?? 1));$residentsPerPage=50;
        $residentsPages=max(1,(int)ceil($residentsTotal/$residentsPerPage));$residentsPage=min($residentsPage,$residentsPages);
        $residentsOffset=($residentsPage-1)*$residentsPerPage;
        $stmt=$dbx->prepare("SELECT r.id,r.family_id,r.rt_id,r.full_name,RIGHT(r.nik,4) AS nik_last4,r.gender,r.birth_date,r.relationship_to_head,r.resident_status,r.created_at,f.family_number,f.status AS family_status,rt.number_code AS rt_code,rw.number_code AS rw_code,d.name AS dusun_name FROM residents r JOIN family_records f ON f.id=r.family_id JOIN rt_units rt ON rt.id=r.rt_id JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id{$where} ORDER BY r.created_at DESC LIMIT {$residentsPerPage} OFFSET {$residentsOffset}");
        $stmt->execute($params);$residents=$stmt->fetchAll();
        if($role==='rt') {
            $stmt=$dbx->prepare("SELECT r.id,r.family_id,r.full_name,RIGHT(r.nik,4) AS nik_last4,RIGHT(f.family_number,4) AS kk_last4,
                NOT EXISTS (SELECT 1 FROM resident_documents dk WHERE dk.resident_id=r.id AND dk.document_type='ktp' AND dk.status='approved') AS missing_ktp,
                NOT EXISTS (SELECT 1 FROM resident_documents df WHERE df.family_id=f.id AND df.document_type='kk' AND df.status='approved') AS missing_kk,
                EXISTS (SELECT 1 FROM resident_documents dr WHERE dr.family_id=f.id AND (dr.resident_id=r.id OR dr.resident_id IS NULL) AND dr.status IN ('revision_requested','rejected')) AS needs_revision
                FROM residents r JOIN family_records f ON f.id=r.family_id
                WHERE r.rt_id=? AND r.resident_status<>'archived' AND f.status<>'archived'
                AND (NOT EXISTS (SELECT 1 FROM resident_documents dk WHERE dk.resident_id=r.id AND dk.document_type='ktp' AND dk.status='approved')
                  OR NOT EXISTS (SELECT 1 FROM resident_documents df WHERE df.family_id=f.id AND df.document_type='kk' AND df.status='approved')
                  OR EXISTS (SELECT 1 FROM resident_documents dr WHERE dr.family_id=f.id AND (dr.resident_id=r.id OR dr.resident_id IS NULL) AND dr.status IN ('revision_requested','rejected')))
                ORDER BY r.full_name LIMIT 100");
            $stmt->execute([(int)$mvUser['rt_id']]);$incompleteRows=$stmt->fetchAll();
            echo '<section class="panel"><div class="section-heading compact-heading"><div><h2>Data Belum Lengkap</h2><p>Daftar tindak lanjut awal memeriksa KTP tiap warga dan KK keluarga yang sudah disetujui petugas.</p></div><span class="status-pill '.($incompleteRows?'warn':'good').'">'.count($incompleteRows).' perlu tindak lanjut</span></div>';
            if($incompleteRows) {
                echo '<div class="table-wrap"><table><thead><tr><th>Warga / keluarga</th><th>Yang perlu ditindaklanjuti</th><th>Aksi</th></tr></thead><tbody>';
                foreach($incompleteRows as $item) {
                    $issues=[];
                    if((int)$item['missing_ktp']===1) $issues[]='KTP belum disetujui';
                    if((int)$item['missing_kk']===1) $issues[]='KK keluarga belum disetujui';
                    if((int)$item['needs_revision']===1) $issues[]='Dokumen perlu diperbaiki/diunggah ulang';
                    echo '<tr><td><strong>'.e($item['full_name']).'</strong><small>NIK akhir '.e($item['nik_last4']).' · KK akhir '.e($item['kk_last4']).'</small></td><td>'.e(implode(' · ',$issues)).'</td><td><a class="button button-small button-outline" href="'.e(app_url('dokumen',['resident_id'=>(int)$item['id']])).'">Tindak lanjuti</a></td></tr>';
                }
                echo '</tbody></table></div><p class="muted">Maksimal 100 entri ditampilkan. Gunakan pencarian di daftar penduduk untuk menemukan warga tertentu.</p>';
            } else echo '<p>Semua warga pada cakupan daftar awal sudah memiliki KTP dan KK yang disetujui, tanpa dokumen yang perlu revisi.</p>';
            echo '</section>';
        }

        echo '<section class="panel"><div class="section-heading compact-heading"><div><h2>Daftar penduduk</h2><p>NIK ditampilkan sebagian. Pencarian dibatasi ke wilayah tugas untuk akun RT/RW.</p></div></div><form method="get" class="track-form large-track"><input type="hidden" name="page" value="rt-data"><input name="q" value="'.e($search).'" placeholder="Cari nama, 4 digit akhir NIK, atau KK"><button type="submit">Cari</button></form>';
        if($residents) {
            echo '<div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Penduduk</th><th>Wilayah</th><th>Status</th><th>Keluarga</th><th>Aksi</th></tr></thead><tbody>';
            foreach($residents as $r) {
                echo '<tr><td><strong>'.e($r['full_name']).'</strong><small>NIK berakhir '.e($r['nik_last4']).' · '.e($r['gender']).'</small></td><td>'.e(($r['dusun_name']??'Dusun -').' / RW '.($r['rw_code']??'-').' / RT '.($r['rt_code']??'-')).'</td><td><span class="status-pill '.e(status_class($r['resident_status'])).'">'.e(status_text($r['resident_status'])).'</span></td><td>#'.(int)$r['family_id'].'<small>KK '.e(mv_mask_tail($r['family_number'])).'</small></td><td><div class="action-links"><a class="text-link" href="'.e(app_url('dokumen',['resident_id'=>(int)$r['id']])).'">Dokumen</a>';
                if(in_array($role,['rt','admin_desa','owner'],true) && $r['family_status']!=='archived') {
                    echo '<details class="revise-details"><summary>Tambah anggota keluarga</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_add_resident"><input type="hidden" name="family_id" value="'.(int)$r['family_id'].'"><label>NIK anggota (16 digit)<input name="nik" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" required></label><label>Nama lengkap<input name="full_name" maxlength="160" required></label><label>Tanggal lahir<input type="date" name="birth_date"></label><label>Jenis kelamin<select name="gender"><option value="unknown">Belum dipilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></label><label>Hubungan dalam keluarga<input name="relationship_to_head" maxlength="60" placeholder="Contoh: Anak"></label><button class="button button-small button-primary" type="submit">Tambah sebagai menunggu pemeriksaan</button></form></details>';
                }
                if($role==='rt') {
                    $rtOptions=module_rt_options();
                    echo '<details class="revise-details"><summary>Usulkan perubahan</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_submit_change"><input type="hidden" name="resident_id" value="'.(int)$r['id'].'"><label>Jenis usulan<select name="change_type"><option value="update">Koreksi data</option><option value="move_rt">Pindah RT (1 KK)</option><option value="deceased">Perubahan status meninggal</option></select></label><label>Nama koreksi (opsional)<input name="full_name" maxlength="160" value="'.e($r['full_name']).'"></label><label>Alamat baru (opsional)<textarea name="address" rows="2"></textarea></label><label>Nomor kontak baru (opsional)<input name="phone" maxlength="30"></label><label>RT tujuan jika pindah<select name="target_rt_id"><option value="">Pilih bila pindah</option>';
                    foreach($rtOptions as $rt) echo '<option value="'.(int)$rt['id'].'">'.e(($rt['dusun_name']??'Dusun -').' / RW '.$rt['rw_code'].' / RT '.$rt['number_code']).'</option>';
                    echo '</select></label><label>Alasan / dokumen pendukung<textarea name="reason" rows="2" minlength="8" maxlength="1000" required></textarea></label><button class="button button-small button-primary" type="submit">Kirim usulan</button></form></details>';
                }
                if(in_array($role,['admin_desa','owner','petugas'],true) && $r['resident_status']==='pending_review') {
                    echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_review_resident"><input type="hidden" name="kind" value="resident"><input type="hidden" name="record_id" value="'.(int)$r['id'].'"><button class="button button-small button-primary" name="decision" value="approve" type="submit">Sahkan</button><button class="button button-small button-outline" name="decision" value="archive" type="submit">Arsip</button></form>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Belum ada catatan untuk filter ini. Tidak ada data contoh yang ditampilkan sebagai data resmi.</p>';
        if($residentsTotal>0) {
            $fromRow=$residentsOffset+1;$toRow=min($residentsOffset+count($residents),$residentsTotal);
            echo '<div class="button-row" style="justify-content:space-between;align-items:center;margin-top:16px"><span class="muted">Menampilkan '.(int)$fromRow.'–'.(int)$toRow.' dari '.(int)$residentsTotal.' warga · halaman '.(int)$residentsPage.' dari '.(int)$residentsPages.'</span><div class="button-row">';
            if($residentsPage>1) echo '<a class="button button-small button-outline" href="'.e(app_url('rt-data',['q'=>$search,'p'=>$residentsPage-1])).'">← Sebelumnya</a>';
            if($residentsPage<$residentsPages) echo '<a class="button button-small button-outline" href="'.e(app_url('rt-data',['q'=>$search,'p'=>$residentsPage+1])).'">Berikutnya →</a>';
            echo '</div></div>';
        }
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
        if(in_array($role,['rt','petugas','admin_desa','owner'],true)) {
            echo '<section class="panel"><h2>Unggah dokumen ke arsip privat</h2><p class="muted">Format: PDF/JPG/PNG/WebP, maksimal 5 MB. Dokumen pribadi tidak boleh dimasukkan ke folder media publik atau GitHub.</p><form method="post" enctype="multipart/form-data" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_upload_document"><label class="full-span">Penduduk<select name="resident_id" required><option value="">Pilih penduduk</option>';
            $selectedId=(int)($_GET['resident_id'] ?? 0);
            foreach($residents as $r) echo '<option value="'.(int)$r['id'].'"'.($selectedId===(int)$r['id']?' selected':'').'>'.e($r['full_name'].' · NIK akhir '.$r['nik_last4'].' · ID KK '.$r['family_id']).'</option>';
            echo '</select></label><label>Jenis dokumen<select name="document_type" required><option value="ktp">KTP</option><option value="kk">Kartu Keluarga</option><option value="akta">Akta</option><option value="surat_pendukung">Surat pendukung</option><option value="lainnya">Lainnya</option></select></label><label>File dokumen<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label><div class="full-span"><button class="button button-primary" type="submit">Unggah secara privat →</button></div></form></section>';
        }
        $where='';$params=[];
        if($role==='rt') {$where=' WHERE d.rt_id=?';$params[]=(int)$mvUser['rt_id'];}
        $status=trim((string)($_GET['status'] ?? ''));
        $type=trim((string)($_GET['type'] ?? ''));
        $documentSearch=trim((string)($_GET['q'] ?? ''));
        if($status!=='' && in_array($status,['pending','approved','revision_requested','rejected','archived'],true)) {$where.=($where?' AND ':' WHERE ').'d.status=?';$params[]=$status;}
        if($type!=='' && in_array($type,['ktp','kk','akta','surat_pendukung','lainnya'],true)) {$where.=($where?' AND ':' WHERE ').'d.document_type=?';$params[]=$type;}
        if($documentSearch!=='') {
            $searchTail=preg_match('/^[0-9]{4,16}$/',$documentSearch)?substr($documentSearch,-4):$documentSearch;
            $where.=($where?' AND ':' WHERE ').'(r.full_name LIKE ? OR d.original_name LIKE ? OR d.document_type LIKE ? OR RIGHT(r.nik,4)=? OR RIGHT(f.family_number,4)=?)';
            $params[]='%'.$documentSearch.'%';$params[]='%'.$documentSearch.'%';$params[]='%'.$documentSearch.'%';$params[]=$searchTail;$params[]=$searchTail;
        }
        $docCountStmt=$dbx->prepare("SELECT COUNT(*) FROM resident_documents d LEFT JOIN residents r ON r.id=d.resident_id LEFT JOIN family_records f ON f.id=d.family_id JOIN app_users u ON u.id=d.uploaded_by{$where}");
        $docCountStmt->execute($params);$documentsTotal=(int)$docCountStmt->fetchColumn();
        $documentsPage=max(1,(int)($_GET['doc_p'] ?? 1));$documentsPerPage=30;
        $documentsPages=max(1,(int)ceil($documentsTotal/$documentsPerPage));$documentsPage=min($documentsPage,$documentsPages);
        $documentsOffset=($documentsPage-1)*$documentsPerPage;
        $stmt=$dbx->prepare("SELECT d.id,d.document_type,d.original_name,d.mime_type,d.size_bytes,d.status,d.review_note,d.created_at,d.rt_id,r.full_name,RIGHT(r.nik,4) AS nik_last4,u.full_name AS uploader FROM resident_documents d LEFT JOIN residents r ON r.id=d.resident_id LEFT JOIN family_records f ON f.id=d.family_id JOIN app_users u ON u.id=d.uploaded_by{$where} ORDER BY d.created_at DESC LIMIT {$documentsPerPage} OFFSET {$documentsOffset}");
        $stmt->execute($params);$docs=$stmt->fetchAll();
        echo '<section class="panel"><div class="section-heading compact-heading"><div><h2>Arsip dokumen & antrean pemeriksaan</h2><p>Pencarian dan filter berlaku hanya pada wilayah yang diizinkan untuk akun ini.</p></div></div><form method="get" class="form-grid"><input type="hidden" name="page" value="dokumen"><label class="full-span">Cari dokumen<input name="q" value="'.e($documentSearch).'" placeholder="Nama warga, nama file, jenis dokumen, 4 digit akhir NIK/KK"></label><label>Status<select name="status"><option value="">Semua status</option>';
        foreach(['pending','approved','revision_requested','rejected','archived'] as $s) echo '<option value="'.e($s).'"'.($status===$s?' selected':'').'>'.e(status_text($s)).'</option>';
        echo '</select></label><label>Jenis<select name="type"><option value="">Semua jenis</option>';
        foreach(['ktp','kk','akta','surat_pendukung','lainnya'] as $t) echo '<option value="'.e($t).'"'.($type===$t?' selected':'').'>'.e(strtoupper($t)).'</option>';
        echo '</select></label><div class="full-span button-row"><button class="button button-small button-outline" type="submit">Terapkan filter</button><a class="button button-small button-outline" href="'.e(app_url('dokumen')).'">Reset</a></div></form>';
        if($docs) { echo '<div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Dokumen</th><th>Pemilik</th><th>Status</th><th>Unggahan</th><th>Aksi</th></tr></thead><tbody>';
            foreach($docs as $d) {
                echo '<tr><td><strong>'.e(strtoupper($d['document_type'])).'</strong><small>'.e($d['original_name']).' · '.number_format((int)$d['size_bytes']/1024,1,',','.').' KB</small></td><td>'.e($d['full_name']??'Data diarsipkan').'<small>NIK akhir '.e($d['nik_last4']??'----').'</small></td><td><span class="status-pill '.e(status_class($d['status'])).'">'.e(status_text($d['status'])).'</span>'.(!empty($d['review_note'])?'<small>'.e($d['review_note']).'</small>':'').'</td><td>'.e(date('d M Y H:i',strtotime($d['created_at']))).'<small>Oleh '.e($d['uploader']).'</small></td><td><div class="action-links"><a class="text-link" href="'.e(app_url('private-document',['id'=>(int)$d['id']])).'">Unduh privat</a>';
                if(in_array($role,['petugas','admin_desa','owner'],true) && in_array($d['status'],['pending','revision_requested'],true)) {
                    echo '<details class="revise-details"><summary>Periksa</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_review_document"><input type="hidden" name="document_id" value="'.(int)$d['id'].'"><label>Keputusan<select name="status"><option value="approved">Terima</option><option value="revision_requested">Minta unggah ulang</option><option value="rejected">Tolak</option><option value="archived">Arsipkan</option></select></label><label>Catatan pemeriksaan<textarea name="review_note" rows="2" placeholder="Wajib jika bukan diterima"></textarea></label><button class="button button-small button-primary" type="submit">Simpan pemeriksaan</button></form></details>';
                }
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Belum ada dokumen untuk filter ini.</p>';
        if($documentsTotal>0) {
            $docFrom=$documentsOffset+1;$docTo=min($documentsOffset+count($docs),$documentsTotal);
            echo '<div class="button-row" style="justify-content:space-between;align-items:center;margin-top:16px"><span class="muted">Menampilkan '.(int)$docFrom.'–'.(int)$docTo.' dari '.(int)$documentsTotal.' dokumen · halaman '.(int)$documentsPage.' dari '.(int)$documentsPages.'</span><div class="button-row">';
            $docParams=['q'=>$documentSearch,'status'=>$status,'type'=>$type];
            if($documentsPage>1) echo '<a class="button button-small button-outline" href="'.e(app_url('dokumen',$docParams+['doc_p'=>$documentsPage-1])).'">← Sebelumnya</a>';
            if($documentsPage<$documentsPages) echo '<a class="button button-small button-outline" href="'.e(app_url('dokumen',$docParams+['doc_p'=>$documentsPage+1])).'">Berikutnya →</a>';
            echo '</div></div>';
        }
        echo '</section>';
    } elseif($mvPage==='perubahan') {
        mv_top('Antrean Verifikasi','Periksa usulan perubahan data, perpindahan RT, dan data baru sebelum menjadi catatan resmi.');
        $role=$mvUser['role'];
        if(in_array($role,['petugas','admin_desa','owner'],true)) {
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
        $stmt=$dbx->prepare("SELECT cr.*,r.full_name,r.birth_date,r.gender,r.phone,RIGHT(r.nik,4) AS nik_last4,f.address AS current_address,st.number_code AS source_rt,dt.number_code AS target_rt,u.full_name AS submitter FROM resident_change_requests cr LEFT JOIN residents r ON r.id=cr.resident_id LEFT JOIN family_records f ON f.id=cr.family_id JOIN rt_units st ON st.id=cr.source_rt_id LEFT JOIN rt_units dt ON dt.id=cr.target_rt_id JOIN app_users u ON u.id=cr.submitted_by{$where} ORDER BY cr.created_at DESC LIMIT 100");
        $stmt->execute($params);$requests=$stmt->fetchAll();
        echo '<section class="panel"><h2>Usulan perubahan & perpindahan</h2>';
        if($requests) { echo '<div class="table-wrap"><table><thead><tr><th>Usulan</th><th>Alasan / data yang diusulkan</th><th>Status</th><th>Aksi pemeriksa</th></tr></thead><tbody>';
            foreach($requests as $r) {
                $data=json_decode((string)$r['proposed_data'],true) ?: [];
                $summary=[];
                foreach($data as $k=>$v) if($v!=='' && $v!==null) $summary[]=$k.': '.(string)$v;
                $isConflict=(string)$r['status']==='conflict';
                echo '<tr><td><strong>'.e($r['full_name']??'Penduduk diarsipkan').'</strong><small>'.e($r['change_type']).' · RT '.e($r['source_rt']).($r['target_rt']?' → RT '.e($r['target_rt']):'').'</small><small>'.e(date('d M Y H:i',strtotime($r['created_at']))).'</small></td><td>'.e($r['reason']).'<small><strong>Usulan:</strong> '.e(implode(' · ',$summary) ?: 'Tidak ada field tambahan').'</small>';
                if($isConflict && in_array($role,['petugas','admin_desa','owner'],true)) {
                    $currentSummary=[];
                    if(!empty($r['full_name'])) $currentSummary[]='Nama: '.$r['full_name'];
                    if(!empty($r['birth_date'])) $currentSummary[]='Tanggal lahir: '.$r['birth_date'];
                    if(!empty($r['gender'])) $currentSummary[]='Jenis kelamin: '.$r['gender'];
                    if(!empty($r['phone'])) $currentSummary[]='Kontak: '.$r['phone'];
                    if(!empty($r['current_address'])) $currentSummary[]='Alamat keluarga: '.$r['current_address'];
                    if(!empty($r['nik_last4'])) $currentSummary[]='NIK akhir: '.$r['nik_last4'];
                    echo '<small><strong>Data saat ini untuk dibandingkan:</strong> '.e(implode(' · ',$currentSummary) ?: 'Tidak ada data aktif untuk dibandingkan').'</small>';
                }
                if(!empty($r['review_note'])) echo '<small>Catatan pemeriksa: '.e($r['review_note']).'</small>';
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
                    if(str_starts_with((string)($r['review_note'] ?? ''),'[KONFLIK]')) {
                        echo '<label class="checkbox-label"><input type="checkbox" name="confirm_current_data" value="1" required> Saya sudah membandingkan usulan dengan data terkini.</label>';
                    }
                    echo '<label>Alasan revisi<textarea name="reason" rows="3" minlength="8" maxlength="1000" required>'.e($r['reason']).'</textarea></label><button class="button button-small button-primary" type="submit">Kirim revisi pada usulan yang sama</button></form></details>';
                }
                if(in_array($role,['petugas','admin_desa','owner'],true) && in_array($r['status'],['submitted','in_review','revision_requested','conflict'],true)) {
                    echo '<form method="post" class="status-form">'.csrf_field().'<input type="hidden" name="action" value="module_review_change"><input type="hidden" name="change_id" value="'.(int)$r['id'].'"><select name="decision"><option value="revision_requested">Minta revisi</option>';
                    if(in_array($role,['admin_desa','owner'],true)) {
                        if(!$isConflict) echo '<option value="approve">Setujui</option>';
                        echo '<option value="reject">Tolak</option>';
                    }
                    echo '</select><input name="review_note" maxlength="1000" placeholder="'.($isConflict?'Alasan/hasil pemeriksaan konflik':'Catatan keputusan').'" required><button class="button button-small button-primary" type="submit">Simpan</button></form>';
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
        mv_top(($mvUser['role'] ?? '')==='owner' ? 'Akun & Hak Akses' : 'Akun RT/RW','Akun dibuat secara individual dan ditugaskan ke wilayah tertentu. Owner juga dapat mengelola akun Admin Desa. Menonaktifkan akun mencabut sesi yang sedang aktif.');
        $rws=$dbx->query('SELECT rw.id,rw.number_code,d.name AS dusun_name FROM rw_units rw LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE rw.is_active=1 ORDER BY d.name,rw.number_code')->fetchAll();
        echo '<section class="content-layout"><div class="panel"><h2>Buat akun Ketua RT</h2><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_create_rt_account"><label>Wilayah RW<select name="rw_id" required><option value="">Pilih RW</option>';
        foreach($rws as $rw) echo '<option value="'.(int)$rw['id'].'">'.e(($rw['dusun_name']??'Dusun -').' / RW '.$rw['number_code']).'</option>';
        echo '</select></label><label>Nomor RT<input name="rt_code" maxlength="10" required></label><label>Nama pengurus<input name="full_name" maxlength="160" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" required></label><label>Kata sandi awal (min. 14 karakter)<input type="password" name="password" minlength="14" required autocomplete="new-password"></label><button class="button button-primary" type="submit">Buat akun RT →</button></form></div><div class="panel"><h2>Buat akun Ketua RW</h2><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_create_rw_account"><label>Wilayah RW<select name="rw_id" required><option value="">Pilih RW</option>';
        foreach($rws as $rw) echo '<option value="'.(int)$rw['id'].'">'.e(($rw['dusun_name']??'Dusun -').' / RW '.$rw['number_code']).'</option>';
        echo '</select></label><label>Nama pengurus<input name="full_name" maxlength="160" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" required></label><label>Kata sandi awal (min. 14 karakter)<input type="password" name="password" minlength="14" required autocomplete="new-password"></label><button class="button button-primary" type="submit">Buat akun RW →</button></form></div></section>';
        echo '<section class="panel"><h2>Buat akun petugas / kepala desa</h2><p>Akun kerja desa bersifat pribadi. Pengguna wajib mengganti kata sandi awal ketika masuk pertama kali.</p><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_create_staff_account"><label>Peran<select name="staff_role"><option value="petugas">Petugas Desa</option><option value="kepala_desa">Kepala Desa</option></select></label><label>Nama lengkap<input name="full_name" maxlength="160" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" required></label><label>Email (opsional)<input type="email" name="email" maxlength="190"></label><label class="full-span">Kata sandi awal (minimal 14 karakter)<input type="password" name="password" minlength="14" required autocomplete="new-password"></label><div class="full-span"><button class="button button-primary" type="submit">Buat akun petugas →</button></div></form></section>';
        if (($mvUser['role'] ?? '') === 'owner') {
            echo '<section class="panel"><h2>Buat akun Admin Desa</h2><p>Akses ini hanya tersedia untuk Owner. Akun baru diwajibkan mengganti kata sandi awal.</p><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_create_admin_account"><label>Nama lengkap<input name="full_name" maxlength="160" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" required></label><label>Email (opsional)<input type="email" name="email" maxlength="190"></label><label>Kata sandi awal (minimal 20 karakter)<input type="password" name="password" minlength="20" required autocomplete="new-password"></label><div class="full-span"><button class="button button-primary" type="submit">Buat akun Admin Desa →</button></div></form></section>';
        }
        $managedRoleSql = ($mvUser['role'] ?? '') === 'owner' ? "'rt','rw','petugas','kepala_desa','admin_desa'" : "'rt','rw','petugas','kepala_desa'";
        $users=$dbx->query("SELECT u.id,u.username,u.full_name,u.role,u.rt_id,u.rw_id,u.is_active,u.created_at,rt.number_code AS rt_code,rw.number_code AS rw_code,d.name AS dusun_name FROM app_users u LEFT JOIN rt_units rt ON rt.id=u.rt_id LEFT JOIN rw_units rw ON rw.id=COALESCE(u.rw_id,rt.rw_id) LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE u.role IN ({$managedRoleSql}) ORDER BY FIELD(u.role,'rt','rw','petugas','kepala_desa','admin_desa'),d.name,rw.number_code,rt.number_code,u.full_name")->fetchAll();
        echo '<section class="panel"><h2>Daftar akun dan penugasan</h2><p>Nonaktifkan akun yang tidak digunakan; jangan hapus permanen karena riwayat aktivitas harus tetap dapat ditelusuri.</p>';
        if($users) {
            echo '<div class="table-wrap"><table><thead><tr><th>Akun</th><th>Penugasan</th><th>Status</th><th>Tindakan admin</th></tr></thead><tbody>';
            foreach($users as $u) {
                $assignment='Akun tingkat desa';
                if($u['role']==='rt') $assignment=($u['dusun_name']??'Dusun -').' / RW '.($u['rw_code']??'-').' / RT '.($u['rt_code']??'-');
                elseif($u['role']==='rw') $assignment=($u['dusun_name']??'Dusun -').' / RW '.($u['rw_code']??'-');
                echo '<tr><td><strong>'.e($u['full_name']).'</strong><small>@'.e($u['username']).' · '.e(role_label($u['role'])).'</small></td><td>'.e($assignment).'</td><td><span class="status-pill '.((int)$u['is_active']?'good':'bad').'">'.((int)$u['is_active']?'Aktif':'Nonaktif').'</span></td><td><div class="action-links">';
                echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_update_rt_user"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><button class="button button-small button-outline" name="operation" value="'.((int)$u['is_active']?'deactivate':'activate').'" type="submit">'.((int)$u['is_active']?'Nonaktifkan':'Aktifkan').'</button></form>';
                echo '<details class="revise-details"><summary>Edit identitas</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_update_rt_user"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><input type="hidden" name="operation" value="update_identity"><label>Nama lengkap<input name="full_name" maxlength="160" value="'.e($u['full_name']).'" required></label><label>Username<input name="username" pattern="[A-Za-z0-9._-]{4,80}" minlength="4" maxlength="80" value="'.e($u['username']).'" required></label><button class="button button-small button-primary" type="submit">Simpan identitas</button></form></details>';
                if($u['role']==='rt') {
                    echo '<details class="revise-details"><summary>Ubah penugasan RT</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_reassign_rt_account"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><label>RT tujuan<select name="rt_id" required><option value="">Pilih RT tujuan</option>';
                    foreach(module_rt_options() as $targetOption) echo '<option value="'.(int)$targetOption['id'].'"'.((int)$targetOption['id']===(int)$u['rt_id']?' selected':'').'>'.e(($targetOption['dusun_name']??'Dusun -').' / RW '.$targetOption['rw_code'].' / RT '.$targetOption['number_code']).'</option>';
                    echo '</select></label><button class="button button-small button-primary" type="submit" data-confirm="Ubah wilayah akun RT ini? Sesi lama akan dicabut dan pengguna wajib mengganti kata sandi.">Simpan penugasan</button></form></details>';
                }
                echo '<details class="revise-details"><summary>Reset kata sandi</summary><form method="post" class="form-stack">'.csrf_field().'<input type="hidden" name="action" value="module_update_rt_user"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><input type="hidden" name="operation" value="reset_password"><label>Kata sandi baru (minimal 14 karakter)<input type="password" name="new_password" minlength="14" required autocomplete="new-password"></label><button class="button button-small button-primary" type="submit">Reset sandi</button></form></details>';
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p>Belum ada akun RT/RW atau akun petugas.</p>';
        echo '<p class="muted">Akun RT dibuat satu per wilayah aktif. Bila pengurus berganti, nonaktifkan akun lama lalu buat/aktifkan akun baru. Penugasan RT dan reset sandi mencabut sesi lama serta tercatat di audit log.</p></section>';
    } elseif($mvPage==='admin-site') {
        mv_top('Tampilan Website','Ubah teks hero, sambutan, profil, kontak, foto utama, peta, dan statistik. Simpan sebagai draf untuk pemeriksaan atau terbitkan.');
        $keys=[
          'village_name'=>'Nama desa','village_subdistrict'=>'Kecamatan','village_regency'=>'Kabupaten/Kota','village_province'=>'Provinsi','village_head_name'=>'Nama kepala desa/pejabat penandatangan','village_head_title'=>'Jabatan penandatangan','hero_title_line1'=>'Judul hero baris pertama','hero_title_prefix'=>'Kata penghubung hero',
          'hero_title_highlight'=>'Judul hero sorotan','hero_subtitle'=>'Deskripsi hero','hero_focus_x'=>'Fokus foto horizontal (0–100)','hero_focus_y'=>'Fokus foto vertikal (0–100)','welcome_title'=>'Judul sambutan',
          'welcome_text'=>'Isi sambutan singkat','hero_image_alt'=>'Teks alternatif foto hero','profil_sejarah'=>'Profil dan sejarah desa',
          'profil_potensi'=>'Potensi desa','umkm_intro'=>'Pengantar UMKM dan wisata','map_public_url'=>'Tautan peta HTTPS',
          'transparansi_intro'=>'Pengantar transparansi anggaran','contact_intro'=>'Petunjuk kontak layanan',
          'office_address'=>'Alamat kantor desa','office_phone'=>'Telepon/WhatsApp kantor','stat_area'=>'Luas wilayah (contoh: 325 ha)',
          'stat_dusun'=>'Label jumlah dusun',
          '__group_navigation'=>'Navigasi dan footer',
          'meta_description'=>'Deskripsi SEO website','village_brand_prefix'=>'Teks awalan logo (contoh: DESA)',
          'nav_home'=>'Menu Beranda','nav_services'=>'Menu Layanan Desa','nav_news'=>'Menu Berita & Pengumuman',
          'nav_complaints'=>'Menu Pengaduan','nav_profile'=>'Menu Profil Desa','nav_more'=>'Menu Lainnya',
          'nav_umkm'=>'Menu UMKM & Wisata','nav_map'=>'Menu Peta Desa','nav_budget'=>'Menu Transparansi Anggaran',
          'nav_contact'=>'Menu Kontak','nav_search_aria'=>'Teks aksesibilitas tombol pencarian','nav_login'=>'Tombol masuk',
          'footer_summary'=>'Ringkasan footer','footer_tagline'=>'Slogan footer','footer_services'=>'Tagline footer',
          '__group_home'=>'Beranda publik',
          'home_eyebrow'=>'Label kecil di atas judul utama','hero_cta_apply'=>'Tombol ajukan surat','hero_cta_track'=>'Tombol lacak pengajuan',
          'benefit_easy'=>'Keunggulan 1','benefit_fast'=>'Keunggulan 2','benefit_transparent'=>'Keunggulan 3','benefit_all'=>'Keunggulan 4',
          'service_card_service_title'=>'Judul kartu layanan surat','service_card_service_text'=>'Deskripsi kartu layanan surat',
          'service_card_complaint_title'=>'Judul kartu pengaduan','service_card_complaint_text'=>'Deskripsi kartu pengaduan',
          'service_card_info_title'=>'Judul kartu informasi','service_card_info_text'=>'Deskripsi kartu informasi',
          'home_tracking_title'=>'Judul kotak pelacakan','home_tracking_subtitle'=>'Deskripsi kotak pelacakan',
          'tracking_placeholder'=>'Contoh kode pelacakan','home_news_title'=>'Judul bagian berita',
          'home_news_subtitle'=>'Deskripsi bagian berita','news_see_all'=>'Tautan lihat semua berita',
          'home_no_news_title'=>'Judul saat belum ada berita','home_no_news_text'=>'Keterangan saat belum ada berita',
          'stat_residents_label'=>'Label jumlah penduduk','stat_families_label'=>'Label jumlah KK',
          'stat_area_label'=>'Label luas wilayah','stat_dusun_label'=>'Label jumlah dusun di beranda','stats_aria_label'=>'Label aksesibilitas statistik',
          '__group_services_page'=>'Halaman layanan publik',
          'services_eyebrow'=>'Label atas halaman layanan','services_title'=>'Judul halaman layanan','services_intro'=>'Deskripsi halaman layanan','services_list_title'=>'Judul daftar layanan',
          '__group_complaints_page'=>'Halaman pengaduan publik',
          'complaints_eyebrow'=>'Label atas halaman pengaduan','complaints_intro'=>'Deskripsi halaman pengaduan','complaints_form_title'=>'Judul formulir pengaduan',
          'complaints_login_text'=>'Pesan bagi warga yang belum masuk','complaints_wrong_role_text'=>'Pesan akun selain warga','complaints_title_label'=>'Label judul laporan',
          'complaints_category_label'=>'Label kategori laporan','complaints_detail_label'=>'Label detail laporan','complaints_submit_label'=>'Tombol kirim pengaduan',
          '__group_tracking_page'=>'Halaman lacak pengajuan',
          'tracking_eyebrow'=>'Label atas lacak pengajuan','tracking_title'=>'Judul halaman lacak pengajuan','tracking_intro'=>'Deskripsi halaman lacak pengajuan',
          'tracking_check_button'=>'Tombol periksa kode','tracking_not_found'=>'Pesan saat kode tidak ditemukan',
          '__group_news_page'=>'Halaman berita dan artikel',
          'news_eyebrow'=>'Label atas halaman berita','news_title'=>'Judul halaman berita','news_intro'=>'Deskripsi halaman berita',
          'news_empty_title'=>'Judul saat berita kosong','news_empty_text'=>'Deskripsi saat berita kosong','article_notfound_title'=>'Judul artikel tidak ditemukan',
          'article_notfound_intro'=>'Deskripsi artikel tidak ditemukan','article_back_label'=>'Tombol kembali ke berita',
          '__group_profile_page'=>'Halaman profil desa',
          'profile_eyebrow'=>'Label atas profil desa','profile_title'=>'Judul halaman profil desa','profile_intro'=>'Deskripsi halaman profil desa',
          'profile_history_title'=>'Judul bagian sejarah','profile_potential_title'=>'Judul bagian potensi desa','profile_contact_title'=>'Judul bagian kontak profil',
          'profile_contact_text'=>'Keterangan kontak pada profil','profile_history_fallback'=>'Teks bawaan sejarah','profile_potential_fallback'=>'Teks bawaan potensi desa',
          '__group_auth_pages'=>'Halaman masuk dan pendaftaran',
          'login_eyebrow'=>'Label halaman masuk','login_title_prefix'=>'Awalan judul masuk','login_title_highlight'=>'Judul logo pada halaman masuk',
          'login_intro'=>'Deskripsi halaman masuk','login_form_title'=>'Judul formulir masuk','login_submit_label'=>'Tombol masuk formulir',
          'login_register_prompt'=>'Teks ajakan daftar','register_eyebrow'=>'Label halaman daftar','register_title_prefix'=>'Awalan judul halaman daftar',
          'register_title_highlight'=>'Sorotan judul halaman daftar','register_intro'=>'Deskripsi halaman daftar','register_form_title'=>'Judul formulir daftar',
          'register_submit_label'=>'Tombol daftar','register_login_prompt'=>'Teks ajakan masuk','register_password_hint'=>'Petunjuk kata sandi pendaftaran',
          'register_link_label'=>'Teks tautan menuju daftar','login_link_label'=>'Teks tautan menuju masuk',
          'page_title_home'=>'Judul tab beranda','page_title_services'=>'Judul tab layanan','page_title_complaints'=>'Judul tab pengaduan',
          'page_title_track'=>'Judul tab pelacakan','page_title_news'=>'Judul tab berita','page_title_article'=>'Judul tab artikel',
          'page_title_profile'=>'Judul tab profil','page_title_login'=>'Judul tab masuk','page_title_register'=>'Judul tab pendaftaran',
          '__group_public_extra'=>'Halaman UMKM, peta, transparansi, dan kontak',
          'umkm_title'=>'Judul halaman UMKM dan wisata','umkm_empty_title'=>'Judul UMKM/wisata kosong','umkm_empty_text'=>'Keterangan UMKM/wisata kosong',
          'map_title'=>'Judul halaman peta desa','map_intro'=>'Deskripsi halaman peta','map_location_title'=>'Judul lokasi peta',
          'map_link_text'=>'Tombol buka peta','map_empty_text'=>'Keterangan peta belum diatur',
          'transparansi_title'=>'Judul transparansi anggaran','transparansi_empty_title'=>'Judul laporan anggaran kosong',
          'transparansi_empty_text'=>'Keterangan laporan anggaran kosong','contact_title'=>'Judul halaman kontak','office_address_title'=>'Judul alamat kantor',
          'contact_phone_title'=>'Judul telepon/WhatsApp','contact_info_title'=>'Judul informasi layanan kontak',
          'page_unavailable_title'=>'Judul halaman tidak tersedia','page_unavailable_text'=>'Deskripsi halaman tidak tersedia','page_home_button'=>'Tombol kembali ke beranda',
          '__group_public_forms'=>'Label formulir dan teks tambahan website publik',
          'services_eta_prefix'=>'Awalan estimasi layanan',
          'services_eta_suffix'=>'Akhiran estimasi layanan',
          'services_apply_button'=>'Tombol ajukan layanan',
          'services_login_for_apply'=>'Teks masuk untuk mengajukan',
          'services_empty_text'=>'Pesan saat belum ada layanan',
          'service_apply_form_title'=>'Judul formulir pengajuan',
          'service_apply_type_label'=>'Label jenis layanan',
          'service_apply_subject_label'=>'Label keperluan/subjek',
          'service_apply_details_label'=>'Label keterangan tambahan',
          'service_apply_attachments_label'=>'Label lampiran persyaratan',
          'service_apply_attachment_hint'=>'Petunjuk format lampiran',
          'service_apply_submit_button'=>'Tombol kirim pengajuan',
          'complaint_category_umum'=>'Kategori pengaduan umum',
          'complaint_category_infrastruktur'=>'Kategori pengaduan infrastruktur',
          'complaint_category_pelayanan'=>'Kategori pengaduan pelayanan',
          'complaint_category_lingkungan'=>'Kategori pengaduan lingkungan',
          'complaint_category_sosial'=>'Kategori pengaduan sosial',
          'tracking_public_note_fallback'=>'Pesan saat belum ada catatan pelacakan',
          'tracking_code_label'=>'Label kode pelacakan',
          'tracking_sent_label'=>'Label tanggal pengajuan',
          'tracking_last_update_label'=>'Label pembaruan terakhir',
          'login_instructions'=>'Petunjuk formulir masuk',
          'login_username_label'=>'Label username masuk',
          'login_password_label'=>'Label kata sandi masuk',
          'register_name_label'=>'Label nama lengkap',
          'register_username_label'=>'Label username pendaftaran',
          'register_email_label'=>'Label email pendaftaran',
          'register_email_hint'=>'Petunjuk email opsional',
          'register_password_label'=>'Label kata sandi pendaftaran',
          'article_body_back_label'=>'Tautan kembali dari artikel',
          'directory_location_label'=>'Label lokasi UMKM/wisata',
          'directory_contact_label'=>'Label kontak UMKM/wisata',
          'map_public_link_intro'=>'Pengantar tautan peta',
          'budget_amount_label'=>'Label nilai ringkasan anggaran',
          'budget_pdf_link_label'=>'Tautan dokumen PDF anggaran',
          'mobile_nav_home'=>'Label navigasi HP beranda',
          'mobile_nav_services'=>'Label navigasi HP layanan',
          'mobile_nav_news'=>'Label navigasi HP berita',
          'mobile_nav_complaints'=>'Label navigasi HP pengaduan',
          'mobile_nav_profile'=>'Label navigasi HP profil',
          'mobile_nav_login'=>'Label navigasi HP masuk',
          'status_submitted'=>'Status diajukan',
          'status_in_review'=>'Status sedang diperiksa',
          'status_revision_requested'=>'Status perlu perbaikan',
          'status_approved'=>'Status disetujui',
          'status_rejected'=>'Status ditolak',
          'status_ready'=>'Status siap diambil',
          'status_completed'=>'Status selesai',
          'status_cancelled'=>'Status dibatalkan',
          'status_received'=>'Status diterima',
          'status_in_progress'=>'Status ditindaklanjuti',
          'status_waiting_reporter'=>'Status menunggu keterangan',
          'status_conflict'=>'Status konflik data',
          'status_resolved'=>'Status tuntas',
          'status_published'=>'Status terbit',
          'status_draft'=>'Status draf',
          'status_archived'=>'Status arsip',
          '__group_tracking_status'=>'Label status yang tampil di pelacakan publik'
        ];
        echo '<section class="panel"><h2>Editor konten</h2><p>Gunakan pencarian untuk menemukan pengaturan, lalu pilih Simpan sebagai draf atau Terbitkan ke website.</p><form method="post" enctype="multipart/form-data" id="site-content-form" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="module_save_site">';
        echo '<div class="full-span cms-setting-search"><label for="site-setting-filter">Cari teks atau gambar</label><input id="site-setting-filter" type="search" placeholder="Contoh: tombol, judul, status, logo, foto..." data-site-setting-filter><small id="site-setting-filter-count" role="status">Semua pengaturan ditampilkan.</small></div>';
        $siteDefaults = [
          'meta_description'=>'Portal informasi dan pelayanan publik desa. Pengajuan layanan, pelacakan, berita, dan pengaduan warga.','village_name'=>'MAKASARI','village_brand_prefix'=>'DESA',
          'hero_title_line1'=>'Layanan desa,','hero_title_prefix'=>'kini','hero_title_highlight'=>'lebih dekat','hero_subtitle'=>'Akses berbagai layanan surat, pengaduan, informasi, dan kabar desa dalam satu tempat, dengan mudah, cepat, dan transparan.',
          'home_eyebrow'=>'Pelayanan Publik Desa','hero_cta_apply'=>'Ajukan Surat','hero_cta_track'=>'Lacak Pengajuan','benefit_easy'=>'Mudah diakses','benefit_fast'=>'Proses lebih cepat',
          'benefit_transparent'=>'Transparan dan terpercaya','benefit_all'=>'Untuk seluruh warga','service_card_service_title'=>'Layanan Surat','service_card_service_text'=>'Ajukan berbagai surat keterangan dengan mudah dan cepat.',
          'service_card_complaint_title'=>'Pengaduan Warga','service_card_complaint_text'=>'Sampaikan aspirasi, keluhan, atau laporan Anda.','service_card_info_title'=>'Informasi Desa',
          'service_card_info_text'=>'Akses informasi seputar program, kegiatan, dan data desa.','home_tracking_title'=>'Cek Status Pengajuan','home_tracking_subtitle'=>'Masukkan nomor pengajuan Anda',
          'tracking_placeholder'=>'Contoh: SR-260101-AB12CD34','home_news_title'=>'Berita & Pengumuman','home_news_subtitle'=>'Informasi terbaru seputar kegiatan, program, dan perkembangan desa.',
          'news_see_all'=>'Lihat Semua','home_no_news_title'=>'Berita desa akan tampil di sini.','home_no_news_text'=>'Petugas dapat menerbitkan berita dan pengumuman melalui panel pengelolaan.',
          'stat_residents_label'=>'Jumlah Penduduk','stat_families_label'=>'Jumlah KK','stat_area_label'=>'Luas Wilayah','stat_dusun_label'=>'Dusun','stats_aria_label'=>'Ringkasan data desa',
          'nav_home'=>'Beranda','nav_services'=>'Layanan Desa','nav_news'=>'Berita & Pengumuman','nav_complaints'=>'Pengaduan','nav_profile'=>'Profil Desa','nav_more'=>'Lainnya',
          'nav_umkm'=>'UMKM & Wisata','nav_map'=>'Peta Desa','nav_budget'=>'Transparansi Anggaran','nav_contact'=>'Kontak','nav_search_aria'=>'Cari berita','nav_login'=>'Masuk',
          'footer_summary'=>'Layanan desa, kini lebih dekat.','footer_tagline'=>'Informasi publik dan pelayanan warga dalam satu tempat.','footer_services'=>'Pelayanan publik yang mudah, cepat, dan transparan.',
          'services_eyebrow'=>'Pelayanan Publik','services_title'=>'Layanan Desa','services_intro'=>'Pilih jenis layanan yang kamu butuhkan. Status pengajuan dapat dipantau dengan kode pelacakan.','services_list_title'=>'Daftar layanan',
          'complaints_eyebrow'=>'Suara Warga','complaints_intro'=>'Sampaikan aspirasi, keluhan, atau laporan. Data pelapor hanya terlihat oleh pihak yang berwenang.','complaints_form_title'=>'Kirim pengaduan',
          'complaints_login_text'=>'Silakan masuk ke akun warga untuk mengirim pengaduan dan melacak tindak lanjutnya.','complaints_wrong_role_text'=>'Akun ini tidak memiliki akses untuk mengirim pengaduan sebagai warga.',
          'complaints_title_label'=>'Judul laporan','complaints_category_label'=>'Kategori','complaints_detail_label'=>'Detail laporan','complaints_submit_label'=>'Kirim pengaduan →',
          'tracking_eyebrow'=>'Transparan & Terpantau','tracking_title'=>'Lacak Pengajuan','tracking_intro'=>'Masukkan kode pelacakan untuk melihat status dan pembaruan yang boleh dibagikan kepada publik.',
          'tracking_check_button'=>'Periksa','tracking_not_found'=>'Kode tidak ditemukan. Periksa kembali kode pada bukti pengajuan.',
          'news_eyebrow'=>'Kabar Desa','news_title'=>'Berita & Pengumuman','news_intro'=>'Informasi kegiatan, program, dan pengumuman resmi desa.',
          'news_empty_title'=>'Belum ada berita yang diterbitkan.','news_empty_text'=>'Konten akan tampil setelah disetujui dan diterbitkan oleh petugas.','article_notfound_title'=>'Berita tidak ditemukan',
          'article_notfound_intro'=>'Berita mungkin sudah diarsipkan atau alamatnya salah.','article_back_label'=>'← Kembali ke berita',
          'profile_eyebrow'=>'Tentang Kami','profile_title'=>'Profil Desa','profile_intro'=>'Ruang informasi resmi desa. Identitas, sejarah, struktur pemerintahan, potensi, alamat, dan kontak akan dikelola melalui panel admin.',
          'profile_history_title'=>'Profil & sejarah','profile_potential_title'=>'Potensi desa','profile_contact_title'=>'Kontak kantor','profile_contact_text'=>'Alamat dan nomor layanan resmi perlu diisi melalui pengaturan website.',
          'profile_history_fallback'=>'Informasi profil desa dapat diperbarui oleh admin desa setelah data resmi tersedia.','profile_potential_fallback'=>'UMKM, wisata, kegiatan masyarakat, dan program unggulan akan ditampilkan setelah konten diterbitkan.',
          'login_eyebrow'=>'Selamat datang kembali','login_title_prefix'=>'Masuk ke','login_title_highlight'=>'DESA MAKASARI','login_intro'=>'Akses layanan warga atau panel kerja sesuai peran akun kamu.','login_form_title'=>'Masuk',
          'login_submit_label'=>'Masuk →','login_register_prompt'=>'Belum punya akun warga?','register_eyebrow'=>'Akun warga','register_title_prefix'=>'Layanan desa,','register_title_highlight'=>'lebih dekat.',
          'register_intro'=>'Buat akun untuk mengajukan layanan dan melacak status permohonan milikmu.','register_form_title'=>'Daftar akun warga','register_submit_label'=>'Buat akun →',
          'register_login_prompt'=>'Sudah punya akun?','register_password_hint'=>'Minimal 12 karakter.','register_link_label'=>'Daftar di sini','login_link_label'=>'Masuk',
          'page_title_home'=>'Beranda','page_title_services'=>'Layanan Desa','page_title_complaints'=>'Pengaduan Warga','page_title_track'=>'Lacak Pengajuan',
          'page_title_news'=>'Berita & Pengumuman','page_title_article'=>'Berita','page_title_profile'=>'Profil Desa','page_title_login'=>'Masuk','page_title_register'=>'Daftar Akun',
          'umkm_title'=>'UMKM & Wisata',
          'umkm_intro'=>'Kenali usaha masyarakat dan tujuan wisata desa yang sudah disetujui untuk dipublikasikan.','umkm_empty_title'=>'Konten UMKM dan wisata belum diterbitkan.','umkm_empty_text'=>'Informasi akan tampil setelah disetujui pengelola desa.',
          'map_title'=>'Peta Desa','map_intro'=>'Gunakan tautan peta resmi yang telah diatur oleh Admin Desa.','map_location_title'=>'Lokasi wilayah desa','map_link_text'=>'Buka peta desa ↗',
          'map_empty_text'=>'Tautan peta belum diatur. Admin Desa dapat menambahkan tautan peta HTTPS melalui menu Tampilan Website.',
          'transparansi_title'=>'Transparansi Anggaran','transparansi_intro'=>'Laporan yang tersedia untuk publik. Informasi ini hanya ditampilkan setelah diterbitkan oleh Admin Desa.',
          'transparansi_empty_title'=>'Belum ada laporan anggaran yang diterbitkan.','transparansi_empty_text'=>'Laporan akan tampil setelah diperiksa dan disetujui.',
          'contact_title'=>'Kontak Desa','contact_intro'=>'Informasi resmi kantor desa dan cara menghubungi petugas.','office_address_title'=>'Alamat kantor','contact_phone_title'=>'Telepon / WhatsApp','contact_info_title'=>'Informasi layanan',
          'page_unavailable_title'=>'Halaman tidak ditemukan','page_unavailable_text'=>'Alamat yang kamu buka tidak tersedia.','page_home_button'=>'Kembali ke beranda',
          'services_eta_prefix'=>'Estimasi',
          'services_eta_suffix'=>'hari kerja',
          'services_apply_button'=>'Ajukan',
          'services_login_for_apply'=>'Masuk untuk ajukan',
          'services_empty_text'=>'Belum ada layanan yang diterbitkan.',
          'service_apply_form_title'=>'Formulir pengajuan',
          'service_apply_type_label'=>'Jenis layanan',
          'service_apply_subject_label'=>'Keperluan / subjek',
          'service_apply_details_label'=>'Keterangan tambahan',
          'service_apply_attachments_label'=>'Lampiran persyaratan (opsional, maksimal 5 file)',
          'service_apply_attachment_hint'=>'PDF/JPG/PNG/WebP, maksimal 5 MB per file.',
          'service_apply_submit_button'=>'Kirim pengajuan →',
          'complaint_category_umum'=>'Umum',
          'complaint_category_infrastruktur'=>'Infrastruktur',
          'complaint_category_pelayanan'=>'Pelayanan',
          'complaint_category_lingkungan'=>'Lingkungan',
          'complaint_category_sosial'=>'Sosial',
          'tracking_public_note_fallback'=>'Belum ada catatan pembaruan untuk ditampilkan.',
          'tracking_code_label'=>'Kode',
          'tracking_sent_label'=>'Dikirim',
          'tracking_last_update_label'=>'Pembaruan terakhir',
          'login_instructions'=>'Gunakan username dan kata sandi yang terdaftar.',
          'login_username_label'=>'Username',
          'login_password_label'=>'Kata sandi',
          'register_name_label'=>'Nama lengkap',
          'register_username_label'=>'Username',
          'register_email_label'=>'Email (opsional)',
          'register_email_hint'=>'Tidak wajib diisi.',
          'register_password_label'=>'Kata sandi',
          'article_body_back_label'=>'← Kembali ke berita',
          'directory_location_label'=>'Lokasi:',
          'directory_contact_label'=>'Kontak:',
          'map_public_link_intro'=>'Peta resmi desa tersedia melalui tautan berikut.',
          'budget_amount_label'=>'Nilai ringkasan:',
          'budget_pdf_link_label'=>'Buka dokumen PDF ↗',
          'mobile_nav_home'=>'Beranda',
          'mobile_nav_services'=>'Layanan',
          'mobile_nav_news'=>'Berita',
          'mobile_nav_complaints'=>'Pengaduan',
          'mobile_nav_profile'=>'Profil',
          'mobile_nav_login'=>'Masuk',
          'status_submitted'=>'Diajukan',
          'status_in_review'=>'Sedang diperiksa',
          'status_revision_requested'=>'Perlu perbaikan',
          'status_approved'=>'Disetujui',
          'status_rejected'=>'Ditolak',
          'status_ready'=>'Siap diambil',
          'status_completed'=>'Selesai',
          'status_cancelled'=>'Dibatalkan',
          'status_received'=>'Diterima',
          'status_in_progress'=>'Ditindaklanjuti',
          'status_waiting_reporter'=>'Menunggu keterangan',
          'status_conflict'=>'Konflik data',
          'status_resolved'=>'Tuntas',
          'status_published'=>'Terbit',
          'status_draft'=>'Draf',
          'status_archived'=>'Arsip'
        ];
        foreach($keys as $key=>$label) {
            if(str_starts_with($key,'__group_')){echo '<div class="full-span cms-subheading"><h3>'.e($label).'</h3></div>';continue;}
            $value=site_val($key,$siteDefaults[$key]??'');
            $isLong=in_array($key,['hero_subtitle','welcome_text','profil_sejarah','profil_potensi','umkm_intro','transparansi_intro','contact_intro','office_address','meta_description','footer_summary','footer_tagline','home_news_subtitle','home_no_news_text','services_intro','complaints_intro','complaints_login_text','complaints_wrong_role_text','tracking_intro','tracking_not_found','news_intro','news_empty_text','article_notfound_intro','profile_intro','profile_history_fallback','profile_potential_fallback','profile_contact_text','login_intro','login_instructions','register_intro','register_email_hint','map_intro','map_public_link_intro','map_empty_text','transparansi_empty_text','service_apply_attachment_hint','tracking_public_note_fallback','page_unavailable_text'],true);
            $class=$isLong?' class="full-span"':'';
            echo '<label data-site-setting data-setting-key="'.e($key).'"'.$class.'>'.e($label);
            if($isLong) echo '<textarea name="content['.e($key).']" rows="3" maxlength="20000" data-preview-key="'.e($key).'">'.e($value).'</textarea>';
            elseif(in_array($key,['hero_focus_x','hero_focus_y'],true)) {
                $focusValue=max(0,min(100,(int)site_val($key,'50')));
                echo '<input type="range" name="content['.e($key).']" min="0" max="100" step="1" value="'.$focusValue.'" data-focus-key="'.e($key).'" aria-label="'.e($label).'"><small>Nilai saat ini: <span data-focus-value="'.e($key).'">'.$focusValue.'</span>%</small>';
            } else echo '<input name="content['.e($key).']" value="'.e($value).'" maxlength="500" data-preview-key="'.e($key).'">';
            echo '</label>';
        }
        echo '<div class="full-span cms-subheading"><h3>Foto dan logo website publik</h3><p>Unggah gambar dari ukuran apa pun. Website otomatis menyesuaikan gambar dengan bingkai desktop dan HP.</p></div>';
        $siteImages=['hero_image'=>['label'=>'Foto utama beranda','fallback'=>'assets/village-hero.webp'],'logo_image'=>['label'=>'Logo/header dan footer','fallback'=>''],'login_image'=>['label'=>'Gambar halaman masuk','fallback'=>'assets/village-hero.webp'],'register_image'=>['label'=>'Gambar halaman pendaftaran','fallback'=>'assets/village-hero.webp'],'news_fallback_image'=>['label'=>'Gambar pengganti berita/UMKM tanpa foto','fallback'=>'assets/village-hero.svg']];
        foreach($siteImages as $imageKey=>$imageMeta){
            $currentImage=site_image_val($imageKey,$imageMeta['fallback']);$previewImage=$currentImage;$previewHidden=$previewImage===''?' hidden':'';
            echo '<label class="full-span" data-site-setting data-setting-key="'.e($imageKey).' '.e($imageMeta['label']).'">'.e($imageMeta['label']).'<input type="file" name="'.e($imageKey).'" accept="image/jpeg,image/png,image/webp" '.($imageKey==='hero_image'?'id="hero-image-input"':'').' data-preview-image-key="'.e($imageKey).'"><small>JPG, PNG, atau WebP · maksimal 5 MB'.($currentImage!==$imageMeta['fallback']?' · Gambar khusus saat ini aktif':'').'</small></label>';
            echo '<div class="full-span cms-image-preview"><span>Pratinjau: '.e($imageMeta['label']).'</span><img data-preview-site-image="'.e($imageKey).'" src="'.e($previewImage).'" alt=""'.$previewHidden.'></div>';
        }
        $focusX=max(0,min(100,(int)site_val('hero_focus_x','50')));$focusY=max(0,min(100,(int)site_val('hero_focus_y','50')));$heroPreview=e(site_image_val('hero_image','assets/village-hero.svg'));$heroAlt=e(site_val('hero_image_alt','Pemandangan desa dan pegunungan'));
        echo '<div class="full-span preview-columns"><div class="preview-desktop"><div class="preview-label">Pratinjau desktop</div><div class="preview-mini"><img class="preview-hero-img" data-preview-hero-image src="'.$heroPreview.'" alt="'.$heroAlt.'" style="object-position:'.$focusX.'% '.$focusY.'%"><strong data-preview-target="hero_title_line1">'.e(site_val('hero_title_line1','Layanan desa,')).'</strong><br><span data-preview-target="hero_title_highlight">'.e(site_val('hero_title_highlight','lebih dekat')).'</span><p data-preview-target="hero_subtitle">'.e(site_val('hero_subtitle','Akses berbagai layanan desa dalam satu tempat.')).'</p></div></div><div class="preview-mobile"><div class="preview-label">Pratinjau HP</div><div class="preview-mini"><img class="preview-hero-img" data-preview-hero-image src="'.$heroPreview.'" alt="'.$heroAlt.'" style="object-position:'.$focusX.'% '.$focusY.'%"><strong data-preview-target="hero_title_line1">'.e(site_val('hero_title_line1','Layanan desa,')).'</strong><br><span data-preview-target="hero_title_highlight">'.e(site_val('hero_title_highlight','lebih dekat')).'</span><p data-preview-target="hero_subtitle">'.e(site_val('hero_subtitle','Akses berbagai layanan desa dalam satu tempat.')).'</p></div></div></div>';
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
    } elseif($mvPage==='notifikasi') {
        if(!mv_guard($mvPage,$mvUser,$mvAllowedRoles[$mvPage])) return;
        $dbx=mv_db_or_message();
        if(!$dbx) return;
        mv_top('Notifikasi','Pemberitahuan privat untuk akun ini. Notifikasi tidak pernah ditampilkan kepada akun lain.');
        $stmt=$dbx->prepare('SELECT id,title,message,notification_type,entity_type,entity_id,read_at,created_at FROM user_notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 100');
        $stmt->execute([(int)$mvUser['id']]);$notificationRows=$stmt->fetchAll();
        $unreadCount=count(array_filter($notificationRows,static fn($row)=>empty($row['read_at'])));
        echo '<section class="panel"><div class="section-heading compact-heading"><div><h2>Kotak masuk</h2><p>Yang belum dibaca: '.(int)$unreadCount.'</p></div><a class="button button-small button-outline" href="'.e(app_url('dashboard')).'">Kembali ke dashboard</a></div>';
        if($notificationRows) {
            echo '<div class="notification-list">';
            foreach($notificationRows as $notification) {
                $unread=empty($notification['read_at']);
                echo '<article class="notification-item'.($unread?' notification-unread':'').'"><div class="notification-copy"><div class="section-heading compact-heading"><strong>'.e($notification['title']).'</strong><span class="status-pill '.($unread?'warn':'neutral').'">'.($unread?'Belum dibaca':'Sudah dibaca').'</span></div><p>'.nl2br(e($notification['message'])).'</p><small>'.e(date('d M Y H:i',strtotime($notification['created_at']))).'</small>';
                if(!empty($notification['entity_type']) && !empty($notification['entity_id']) && $notification['entity_type']==='resident_change_request') {
                    echo '<small>Referensi usulan #'.e((string)$notification['entity_id']).'</small>';
                }
                echo '</div>';
                if($unread) echo '<form method="post" class="notification-action">'.csrf_field().'<input type="hidden" name="action" value="module_mark_notification_read"><input type="hidden" name="notification_id" value="'.(int)$notification['id'].'"><input type="hidden" name="return_page" value="notifikasi"><button class="button button-small button-outline" type="submit">Tandai dibaca</button></form>';
                echo '</article>';
            }
            echo '</div>';
            echo '<p class="muted">Maksimal 100 notifikasi terbaru ditampilkan. Isi notifikasi dibatasi pada akun penerima dan tidak membuka lampiran privat secara langsung.</p>';
        } else echo '<div class="empty-news"><div><strong>Belum ada notifikasi.</strong><p>Pemberitahuan akan muncul di sini saat ada pembaruan yang terkait dengan akunmu.</p></div></div>';
        echo '</section>';
    } elseif($mvPage==='admin-resident-export') {
        mv_top('Unduh Data Penduduk','Unduh daftar penduduk lengkap atau paket ZIP berisi CSV dan KTP/KK yang sudah disetujui. Akses khusus Admin Desa; setiap ekspor dicatat.');
        $dusuns=$dbx->query('SELECT id,name FROM dusun WHERE is_active=1 ORDER BY name')->fetchAll();
        $rws=$dbx->query('SELECT rw.id,rw.number_code,d.name AS dusun_name FROM rw_units rw LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE rw.is_active=1 ORDER BY d.name,rw.number_code')->fetchAll();
        $rts=$dbx->query('SELECT rt.id,rt.number_code,rw.number_code AS rw_code,d.name AS dusun_name FROM rt_units rt JOIN rw_units rw ON rw.id=rt.rw_id LEFT JOIN dusun d ON d.id=rw.dusun_id WHERE rt.is_active=1 AND rw.is_active=1 ORDER BY d.name,rw.number_code,rt.number_code')->fetchAll();
        echo '<section class="panel"><h2>Siapkan ekspor</h2><p>File berisi data pribadi, termasuk NIK dan nomor KK lengkap. Simpan di tempat aman dan bagikan hanya kepada pihak yang berwenang. Dokumen KTP/KK yang masuk ke ZIP hanya yang berstatus disetujui.</p><form method="get" action="index.php" class="form-grid"><input type="hidden" name="page" value="admin-resident-export"><label>Wilayah<select name="scope" id="resident-export-scope"><option value="all">Seluruh desa</option><option value="dusun">Pilih dusun</option><option value="rw">Pilih RW</option><option value="rt">Pilih RT</option></select></label><label>Dusun / RW / RT<select name="scope_id" id="resident-export-scope-id"><option value="">Tidak diperlukan untuk seluruh desa</option>';
        foreach($dusuns as $d) echo '<option data-scope="dusun" value="'.(int)$d['id'].'">'.e($d['name']).'</option>';
        foreach($rws as $rw) echo '<option data-scope="rw" value="'.(int)$rw['id'].'">'.e(($rw['dusun_name']??'Dusun -').' / RW '.$rw['number_code']).'</option>';
        foreach($rts as $rt) echo '<option data-scope="rt" value="'.(int)$rt['id'].'">'.e(($rt['dusun_name']??'Dusun -').' / RW '.$rt['rw_code'].' / RT '.$rt['number_code']).'</option>';
        echo '</select></label><label>Jenis unduhan<select name="bundle"><option value="data">CSV daftar penduduk lengkap</option><option value="data_documents">ZIP: CSV + KTP/KK disetujui</option></select></label><label class="full-span">Alasan kerja / nomor disposisi<input name="reason" minlength="4" maxlength="300" placeholder="Contoh: pemutakhiran data administrasi desa" required></label><div class="full-span"><button class="button button-primary" type="submit">Siapkan unduhan →</button></div></form><p class="muted">Batas satu ekspor adalah 10.000 penduduk. Untuk data lebih banyak, pecah unduhan per wilayah. ZIP membutuhkan ekstensi PHP ZIP pada server.</p></section>';
    } elseif($mvPage==='laporan') {
        mv_top('Laporan & Ekspor','Ekspor hanya sesuai kewenangan dan cakupan wilayah akun. Ekspor dicatat di audit log, dan NIK/KK lengkap tidak disertakan.');
        $kinds=[
            'residents'=>['label'=>'Data penduduk (ringkas)','desc'=>'Nama, NIK disamarkan, status dan wilayah. Hanya RT/RW/petugas/admin.','statuses'=>['draft'=>'Draf','pending_review'=>'Menunggu pemeriksaan','active'=>'Aktif','moved'=>'Pindah','deceased'=>'Meninggal','archived'=>'Arsip']],
            'families'=>['label'=>'Rekap keluarga/KK','desc'=>'ID internal, wilayah, status dan jumlah anggota; nomor KK serta alamat lengkap tidak disertakan.','statuses'=>['draft'=>'Draf','pending_review'=>'Menunggu pemeriksaan','active'=>'Aktif','moved'=>'Pindah','archived'=>'Arsip']],
            'requests'=>['label'=>'Laporan pengajuan surat','desc'=>'Kode, layanan, status, catatan publik dan tanggal; hanya petugas/admin/kepala desa.','statuses'=>['submitted'=>'Diajukan','in_review'=>'Diperiksa','revision_requested'=>'Perlu revisi','approved'=>'Disetujui','rejected'=>'Ditolak','ready'=>'Siap diambil','completed'=>'Selesai','cancelled'=>'Dibatalkan']],
            'complaints'=>['label'=>'Laporan pengaduan','desc'=>'Kode, judul, status, tanggapan publik dan tanggal; hanya petugas/admin/kepala desa.','statuses'=>['received'=>'Diterima','in_review'=>'Diperiksa','in_progress'=>'Ditindaklanjuti','waiting_reporter'=>'Menunggu warga','resolved'=>'Tuntas','rejected'=>'Ditolak']],
        ];
        echo '<section class="content-layout">';
        foreach($kinds as $kind=>$meta) {
            $can=true;
            if(in_array($kind,['residents','families'],true) && !in_array($mvUser['role'],['rt','rw','petugas','admin_desa','owner'],true)) $can=false;
            if(in_array($kind,['requests','complaints'],true) && !in_array($mvUser['role'],['petugas','admin_desa','owner','kepala_desa'],true)) $can=false;
            if(!$can) continue;
            echo '<div class="panel"><h2>'.e($meta['label']).'</h2><p>'.e($meta['desc']).'</p><form method="get" action="index.php" class="form-stack"><input type="hidden" name="page" value="module-export"><input type="hidden" name="kind" value="'.e($kind).'"><label>Dari tanggal<input type="date" name="from"></label><label>Sampai tanggal<input type="date" name="to"></label><label>Status<select name="status"><option value="">Semua status</option>';
            foreach($meta['statuses'] as $statusKey=>$statusLabel) echo '<option value="'.e($statusKey).'">'.e($statusLabel).'</option>';
            echo '</select></label><label>Alasan kerja untuk audit<input name="reason" maxlength="300" minlength="4" placeholder="Contoh: laporan rapat desa" required></label><button class="button button-primary" type="submit">Unduh CSV →</button></form></div>';
        }
        echo '</section>';
    }
}
