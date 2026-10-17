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
2. Buat nilai acak yang kuat untuk `APP_KEY`, `DB_PASSWORD`, dan `DB_ROOT_PASSWORD`. Jangan gunakan contoh atau sandi yang sama.
3. Untuk akses melalui HTTPS, atur `SESSION_SECURE_COOKIE=true` setelah TLS diaktifkan di reverse proxy.
4. Jalankan `docker compose up -d --build`.
5. Periksa `docker compose ps` dan `docker compose logs --tail=100 web db`.
6. Buat akun admin awal melalui terminal trusted container menggunakan `php bin/create_admin.php <username> "<Nama Admin>" '<sandi-panjang>'`. Hindari menuliskan sandi asli di riwayat shell; gunakan mekanisme secret management yang sesuai pada server.
7. Buka halaman melalui port lokal yang dipilih. Jangan buka port database ke internet.

## Keamanan sebelum publik

- Pasang HTTPS dan aktifkan cookie sesi Secure.
- Uji bahwa file di `storage/uploads` tidak bisa dibuka melalui URL publik.
- Pastikan setiap aksi admin diperiksa di sisi server, tidak hanya dengan menyembunyikan menu.
- Uji akun dari RT berbeda agar tidak dapat mengakses warga, laporan, atau dokumen RT lain.
- Uji data ganda NIK/KK, revisi surat, persetujuan berjenjang, unggah ulang dokumen, dan pergantian pengurus.
- Siapkan pembatasan percobaan login, MFA admin jika tersedia, serta prosedur reset akun.
- Buat backup terenkripsi database dan file upload di lokasi terpisah. Uji pemulihan, jangan hanya cek file backup ada.
- Isi profil desa, kontak, jenis surat, syarat resmi, SOP dan pejabat penyetuju bersama pemerintah desa. Statistik kosong sengaja ditampilkan sebagai tanda strip, bukan angka rekaan.

## Catatan data pribadi

Jangan pernah commit file .env, salinan database, NIK/KK, foto identitas, atau berkas warga ke GitHub. Semua lampiran warga wajib berada di penyimpanan privat dengan otorisasi per permintaan. Isi database dummy hanya boleh dipakai untuk pengujian.

## Pembaruan dari GitHub

Setelah versi diuji dan disetujui: masuk ke direktori proyek, periksa branch dan perubahan lokal, jalankan backup, lalu gunakan `git pull --ff-only origin main`. Setelah itu rebuild image dan smoke-test semua alur sebelum membuka lagi ke warga. Jangan melakukan pull langsung ke produksi tanpa backup.
