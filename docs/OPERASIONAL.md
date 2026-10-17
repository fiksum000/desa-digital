# Operasional dan instalasi awal

## Status penting

Repository ini adalah tahap pengembangan/beta. Jangan masukkan data penduduk sungguhan sebelum uji role, alur dokumen privat, keamanan upload, persetujuan surat, backup, dan pemulihan selesai.

## Prasyarat

- Debian atau Ubuntu pada VPS/CT Proxmox.
- Docker Engine dan Docker Compose plugin.
- Domain dan HTTPS saat dibuka untuk publik.
- Backup database dan lampiran di lokasi terpisah.

## Persiapan lingkungan

1. Salin `.env.example` menjadi `.env`.
2. Buat nilai acak yang kuat untuk `APP_KEY`, `DB_PASSWORD`, dan `DB_ROOT_PASSWORD`. Untuk APP_KEY, jalankan `openssl rand -hex 32` lalu salin hasil 64 karakter heksadesimal ke `.env`. Buat kedua sandi database secara terpisah; jangan gunakan contoh atau sandi yang sama.
3. **Jangan kehilangan atau mengganti APP_KEY setelah data warga masuk.** Kunci ini dipakai untuk mengenkripsi NIK/KK dan membuat HMAC pencarian duplikasi. Simpan cadangan kunci secara terenkripsi dan terpisah dari backup database.
4. Untuk akses melalui HTTPS, atur `SESSION_SECURE_COOKIE=true` setelah TLS diaktifkan di reverse proxy. Ini mengaktifkan cookie Secure dan header HSTS; jangan aktifkan sebelum HTTPS publik bekerja.
5. Jalankan `docker compose up -d --build`.
6. Periksa `docker compose ps` dan `docker compose logs --tail=100 web db`.
7. Buat akun admin awal melalui terminal trusted container menggunakan `php bin/create_admin.php <username> \"<Nama Admin>\" '<sandi-panjang>'`. Hindari menuliskan sandi asli di riwayat shell; gunakan mekanisme secret management yang sesuai pada server.
8. Untuk dashboard pemilik tingkat tertinggi, jalankan `php bin/migrate_owner_role.php` pada database lama, lalu buat Owner pertama memakai `php bin/create_owner.php <username> <nama-lengkap> <sandi-minimal-20-karakter>`. Jalankan hanya dari terminal tepercaya; jangan memasukkan sandi ke URL, chat, atau log. Schema baru sudah memuat role Owner.
9. Buka halaman melalui port lokal yang dipilih. Jangan buka port database ke internet.

## Keamanan sebelum publik

- Pasang HTTPS dan aktifkan cookie sesi Secure.
- Uji bahwa file di `storage/uploads` tidak bisa dibuka melalui URL publik.
- Pastikan setiap aksi admin/Owner diperiksa di sisi server, tidak hanya dengan menyembunyikan menu.
- Uji akun dari RT berbeda agar tidak dapat mengakses warga, laporan, atau dokumen RT lain.
- Uji data ganda NIK/KK, revisi surat, persetujuan berjenjang, unggah ulang dokumen, dan pergantian pengurus.
- Login sekarang membatasi percobaan secara persisten: maksimal 5 kegagalan per akun dan 20 per alamat jaringan dalam jendela 10 menit. Kunci bucket adalah HMAC dari APP_KEY; alamat IP mentah tidak disimpan di tabel pembatasan.
- Untuk mengurangi spam dari satu akun, pengajuan surat baru, revisi pengajuan, dan pengaduan masing-masing dibatasi maksimal 10 kali per jam. Pembatasan disimpan di tabel `auth_attempts` dengan kunci HMAC dan bucket terpisah per jenis aksi; tidak perlu migrasi skema tambahan.
- Siapkan MFA admin jika tersedia, serta prosedur reset akun. Kredensial Owner harus unik, terpisah dari akun harian, dan dipakai hanya oleh pemilik sistem.
- Buat backup terenkripsi database dan file upload di lokasi terpisah. Uji pemulihan, jangan hanya cek file backup ada.
- Isi profil desa, kontak, jenis surat, syarat resmi, SOP dan pejabat penyetuju bersama pemerintah desa. Statistik kosong sengaja ditampilkan sebagai tanda strip, bukan angka rekaan.

## Backup dan pemulihan terenkripsi

Syarat: Docker Compose, GPG, gzip, tar, dan SHA-256 tersedia pada host; `.env` sudah diisi; layanan web/database berjalan. Letakkan file frasa sandi backup di luar repo dengan izin `600`, dan atur `BACKUP_DIR` ke volume/disk backup terpisah.

```bash
chmod 600 /root/.config/desa-backup-passphrase
BACKUP_DIR=/mnt/backup/desa-digital \
BACKUP_PASSPHRASE_FILE=/root/.config/desa-backup-passphrase \
./bin/backup.sh
```

Skrip menghasilkan dua arsip GPG AES-256: dump MariaDB serta media/unggahan (`storage/uploads` dan `assets/uploads`), plus checksum SHA-256. Saat pemulihan, berikan ketiga berkas dari timestamp yang sama; skrip memverifikasi manifest berisi tepat dua nama arsip tersebut dan checksum cocok sebelum meminta konfirmasi atau mengubah data. Salin ketiga berkas ke lokasi terpisah/offsite dan uji pemulihan secara berkala.

Simpan salinan `APP_KEY` yang sama melalui pengelola rahasia terenkripsi dan terpisah. Kunci ini tidak disertakan dalam arsip backup. Jika kunci hilang atau berbeda, NIK/KK terenkripsi tidak bisa dibaca walaupun database pulih.

Pemulihan **mengganti isi tabel database dan folder unggahan**. Pilih pasangan database/berkas dari timestamp yang sama, pastikan `APP_KEY` sama, lalu jalankan dari direktori repo:

```bash
BACKUP_PASSPHRASE_FILE=/root/.config/desa-backup-passphrase \
./bin/restore.sh /mnt/backup/desa-digital/desa-digital-db-YYYYMMDDTHHMMSSZ.sql.gz.gpg \
                  /mnt/backup/desa-digital/desa-digital-files-YYYYMMDDTHHMMSSZ.tar.gz.gpg \
                  /mnt/backup/desa-digital/desa-digital-YYYYMMDDTHHMMSSZ.sha256
```

Baca peringatan dan ketik `RESTORE` hanya setelah memastikan pasangan backup benar. Skrip memverifikasi dekripsi/format dan menolak jalur arsip di luar dua direktori upload yang diizinkan, menghentikan web untuk mengurangi penulisan selama pemulihan, lalu menyalakannya kembali. Setelahnya, periksa `docker compose ps`, log aplikasi, akun warga/RT, dokumen privat, dan halaman publik. Ini belum menggantikan latihan pemulihan pada staging.

## Konflik usulan perubahan data

Saat RT mengirim usulan koreksi, perubahan status, atau pindah RT, aplikasi menyimpan sidik HMAC dari data sumber warga, keluarga, serta daftar anggota keluarga saat itu. Nilai NIK/KK mentah tidak disalin ke snapshot.

Pada saat persetujuan akhir, aplikasi mengunci baris sumber yang relevan dan membandingkan sidik data sekarang dengan snapshot saat pengajuan. Jika ada perubahan bersamaan, persetujuan ditahan, tidak ada perubahan resmi yang diterapkan, dan status menjadi **Konflik data**. Petugas/admin dapat membandingkan usulan dengan data terkini; setelah itu admin meminta revisi pada usulan yang sama atau menolak dengan alasan. Untuk mengirim revisi setelah konflik, RT harus mengonfirmasi bahwa data terkini sudah dibandingkan.

Usulan tertunda dari database lama yang tidak memiliki snapshot dipindahkan ke status konflik oleh migrasi, bukan otomatis disetujui. Uji alur ini dengan dua sesi petugas sebelum produksi: kirim usulan di sesi pertama, ubah data sumber pada sesi kedua, lalu pastikan persetujuan sesi pertama menghasilkan konflik dan mempertahankan perubahan baru.

## Catatan data pribadi

NIK dan nomor KK disimpan sebagai ciphertext yang dienkripsi dengan secretbox libsodium. Kolom yang dipakai pada daftar hanya berisi topeng `************1234`; HMAC berkunci dipakai untuk deteksi duplikasi dan indeks unik tanpa menyimpan nilai asli sebagai kolom pencarian. `APP_KEY` tidak dapat dipulihkan dari database; simpan salinan terenkripsi yang aksesnya sangat terbatas.

Jika database lama sudah dibuat dari skema yang menyimpan NIK/KK sebagai teks biasa atau belum memiliki tabel pembatasan login, ambil backup database dan APP_KEY terlebih dahulu lalu jalankan `php bin/migrate_private_identifiers.php` dari container web yang dipercaya setelah APP_KEY diatur dengan benar. Skrip berhenti jika kunci salah, data tidak bisa dinormalisasi, atau ditemukan duplikasi; jangan melewati error migrasi dengan mengubah data secara manual. Jangan pernah commit file .env, salinan database, NIK/KK, foto identitas, atau berkas warga ke GitHub. Semua lampiran warga wajib berada di penyimpanan privat dengan otorisasi per permintaan. Isi database dummy hanya boleh dipakai untuk pengujian.

## Penerbitan nomor dan PDF surat

1. Pada **Admin Desa → Tampilan Website**, isi lalu terbitkan Nama desa, Alamat kantor desa, Kabupaten/Kota, Provinsi, dan Nama kepala desa/pejabat penandatangan. Kecamatan, jabatan penandatangan, serta kontak kantor bersifat opsional.
2. Petugas memeriksa permohonan dan mengubah statusnya ke **Dalam pemeriksaan**. Hanya admin desa atau kepala desa yang dapat memberi persetujuan akhir.
3. Setelah status **Disetujui**, admin desa atau kepala desa memasukkan nomor surat sesuai buku agenda dan aturan penomoran resmi desa. Nomor disimpan unik; kop surat disalin sebagai snapshot sehingga perubahan profil desa tidak mengubah surat yang sudah diterbitkan.
4. Periksa PDF melalui tautan **Unduh surat PDF**. Setelah benar dan siap diserahkan, ubah status menjadi **Siap diambil**. Sistem menolak perubahan ke status tersebut bila nomor surat belum ada.
5. Pemohon hanya dapat mengunduh PDF miliknya sendiri. Petugas, admin desa, dan kepala desa dapat mengakses PDF sesuai kebutuhan pekerjaan; akses dicatat dalam audit log. RT/RW dan pengguna lain tidak mendapat akses ke endpoint PDF.

**Catatan format:** PDF saat ini adalah templat umum yang memuat data permohonan, nomor surat, kop desa, dan penandatangan. Desa tetap harus menyesuaikan isi tiap jenis surat, dasar hukum, persyaratan, format, dan prosedur tanda tangan/cap sesuai SOP sebelum dokumen dipakai sebagai surat resmi. Jangan gunakan data warga sungguhan sampai peninjauan ini, uji akses antar-RT, backup, dan pemulihan selesai.

Pada database yang sudah ada, jalankan migrasi yang aman diulang dari container web setelah mengambil backup:
`docker compose exec web php bin/migrate_service_letters.php`

## Penguatan kode pelacakan publik

Kode baru untuk pengajuan dan pengaduan menggunakan token acak 128-bit. Kode lama tetap berlaku; kolom database diperlebar dari 32 menjadi 48 karakter. Pada database yang sudah ada, ambil backup terlebih dahulu lalu jalankan migrasi idempotent dari container web:

```bash
docker compose exec web php bin/migrate_tracking_codes.php
```

Respons aplikasi menggunakan `Cache-Control: private, no-store` agar halaman pelacakan, formulir, dan data sesi tidak tersimpan di cache browser/proxy. Pastikan HTTPS aktif untuk penggunaan publik dan atur `SESSION_SECURE_COOKIE=true` di reverse proxy.

## Pembaruan dari GitHub

Setelah versi diuji dan disetujui: masuk ke direktori proyek, periksa branch dan perubahan lokal, jalankan backup, lalu gunakan `git pull --ff-only origin main`. Setelah itu rebuild image dan smoke-test semua alur sebelum membuka lagi ke warga. Jalankan migrasi baru dari container web jika bagian rilis menyebutkannya; jangan melakukan pull langsung ke produksi tanpa backup.
