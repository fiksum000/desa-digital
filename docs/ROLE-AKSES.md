# Matriks role dan akses

Dokumen ini menjadi checklist implementasi. Jangan menganggap akses aman hanya karena menu disembunyikan; setiap operasi harus memeriksa peran dan cakupan wilayah di server.

| Peran | Boleh | Tidak boleh |
| --- | --- | --- |
| Warga | Buat pengajuan/pengaduan, lihat status dan riwayat miliknya | Melihat data atau dokumen warga lain |
| Ketua RT | Mengelola draf warga/keluarga RT tugasnya, mengajukan koreksi/mutasi, mengunggah berkas terkait, melihat rekap RT sendiri | Membuat/menghapus akun RT, pindah wilayah sendiri, menyetujui perubahan penting, membuka data RT lain |
| Ketua RW | Melihat rekap wilayah RW sendiri dan memeriksa usulan sesuai SOP desa | Mengelola akun RT atau melihat dokumen di luar kebutuhan tugas |
| Petugas desa | Memeriksa dokumen/usulan, memproses layanan dan pengaduan sesuai penugasan | Mengubah audit log atau mengakses data tanpa kebutuhan kerja |
| Admin desa | Mengelola akun dan penugasan RT/RW, wilayah, jenis layanan, CMS dan ekspor sesuai hak | Menghapus riwayat audit tanpa prosedur |
| Owner | Mengawasi administrasi, mengelola akun Admin Desa, serta melihat log aktivitas penting | Menghapus atau mengubah riwayat audit lewat aplikasi; memakai akses tinggi untuk pekerjaan harian yang tidak perlu |
| Kepala desa | Melihat laporan agregat dan memberi persetujuan akhir sesuai SOP | Mengakses data pribadi tanpa keperluan atau mengubah penugasan sistem yang bukan kewenangannya |

## Wajib diuji sebelum produksi

- Dua akun RT dengan wilayah berbeda tidak dapat membaca, mengubah, mengekspor, atau mengunduh lampiran milik RT lain, termasuk dengan menebak ID.
- Registrasi publik selalu menghasilkan role warga, bukan role petugas.
- Akun RT hanya dapat dibuat/diubah/dinonaktifkan oleh admin desa.
- NIK unik pada database dan impor; konflik hanya terlihat sebagai peringatan terbatas, bukan membocorkan identitas RT lain.
- Dokumen pribadi berada di penyimpanan privat, bukan di folder media publik; tidak memiliki URL publik yang bisa ditebak.
- Setiap perubahan status, koreksi, dokumen, penugasan RT, ekspor, serta keputusan pemeriksaan tercatat dalam audit log.
- Revisi pengajuan mempertahankan kode pelacakan dan riwayat status.
- Setiap perubahan data penting melewati alur pengusulan, pemeriksaan, dan persetujuan sesuai SOP desa.

- Halaman `Log Aktivitas` hanya dapat dibuka Admin Desa dan Owner. Halaman menampilkan metadata minimum (waktu, pelaku, tindakan, jenis data, ID objek); payload sebelum/sesudah perubahan, hash IP, dan user-agent tidak ditampilkan oleh viewer. Viewer bersifat baca-saja.

Fitur yang belum memenuhi item di atas harus tetap berstatus belum siap produksi.
