<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migration hanya boleh dijalankan lewat CLI.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'role'")->fetch();
if (!$column) {
    fwrite(STDERR, "Kolom app_users.role tidak ditemukan; periksa skema database.\n");
    exit(1);
}

$type = strtolower((string)($column['Type'] ?? ''));
if (!str_contains($type, "'owner'")) {
    $pdo->exec("ALTER TABLE app_users MODIFY role ENUM('owner','warga','rt','rw','petugas','admin_desa','kepala_desa') NOT NULL DEFAULT 'warga'");
    fwrite(STDOUT, "Role Owner ditambahkan ke app_users.role.\n");
} else {
    fwrite(STDOUT, "Role Owner sudah tersedia; tidak ada perubahan.\n");
}
fwrite(STDOUT, "Migrasi role Owner selesai dan aman dijalankan ulang.\n");
