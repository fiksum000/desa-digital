# DESA DIGITAL

Portal informasi dan pelayanan publik desa dengan desain responsif yang mengikuti rancangan visual yang disepakati.

## Status

Versi awal pengembangan. Jangan gunakan untuk data warga sungguhan sebelum pengujian keamanan, alur persetujuan, serta pemulihan backup diselesaikan.

## Rencana fitur

- Beranda publik responsif: profil, berita/pengumuman, layanan surat, pelacakan pengajuan, pengaduan, UMKM/wisata, peta, transparansi anggaran, dan kontak.
- Role terpisah: warga, ketua RT, ketua RW, petugas desa, admin desa, dan kepala desa.
- Akun RT individual; hanya admin desa yang dapat membuat, mengubah penugasan, mereset, atau menonaktifkan akun RT.
- Data wilayah dan penduduk dengan pembatasan akses pada sisi server.
- Dokumen warga bersifat privat, dengan audit akses, status pemeriksaan, dan riwayat versi.
- Pencegahan data ganda dan alur verifikasi perubahan warga.
- Pengajuan surat, pelacakan, koreksi/revisi pada pengajuan yang sama, pengaduan, berita, serta CMS dengan draf/pratinjau/terbit/arsip.
- Ekspor yang dibatasi kewenangan dan wilayah, backup terpisah, serta audit aktivitas.

## Rancangan visual

Halaman publik harus tetap dekat dengan gambar acuan: navbar putih, hero hijau-krem dengan foto lanskap, judul “Layanan desa, kini lebih dekat”, tombol Ajukan Surat dan Lacak Pengajuan, kartu layanan, widget cek status, berita bergambar, statistik desa, dan navigasi mobile di bagian bawah.

## Persiapan server

Target deployment: Debian/Ubuntu pada VPS atau CT Proxmox, PHP 8.3, MariaDB, dan Apache/PHP. Simpan konfigurasi sensitif pada .env; jangan pernah commit file .env atau dokumen warga.

Panduan operasional akan ditempatkan di docs/OPERASIONAL.md. Skema database ada di schema.sql. Untuk database beta yang dibuat dengan skema identifier lama, lihat `bin/migrate_private_identifiers.php` dan `docs/OPERASIONAL.md` sebelum melanjutkan deployment.

## Keamanan data

NIK dan nomor KK disimpan dalam bentuk terenkripsi menggunakan libsodium; kolom pencarian hanya menyimpan topeng empat digit terakhir dan HMAC berkunci untuk mencegah duplikasi tanpa menyimpan nilai mentah sebagai indeks. Alamat lengkap personal, nomor telepon, lampiran KTP/KK, pengajuan pribadi, serta catatan internal tidak boleh tampil di halaman publik. Upload harus disimpan di lokasi privat, diakses melalui pemeriksaan autentikasi dan otorisasi di server, dan dicatat dalam audit log. Simpan APP_KEY dengan aman bersama strategi backup terenkripsi; kehilangan kunci berarti NIK/KK tidak dapat dibuka.

## Backup dan pemulihan

Backup terenkripsi tersedia melalui `bin/backup.sh`; pemulihan interaktif melalui `bin/restore.sh`. Baca `docs/OPERASIONAL.md` terlebih dahulu. Output backup dan file passphrase harus disimpan di luar repository; `APP_KEY` perlu dicadangkan secara terpisah dan aman.

## Pengembangan

1. Bangun fondasi database, autentikasi, role, wilayah, dan audit.
2. Bangun data keluarga/penduduk, unggah dokumen privat, validasi data ganda, serta antrean verifikasi.
3. Bangun layanan surat, alur revisi/persetujuan, pengaduan, dan pelacakan.
4. Bangun seluruh CMS publik dan panel admin.
5. Jalankan tes otomatis, pengujian browser, backup, dan pemulihan sebelum deployment produksi.
