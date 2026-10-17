<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Script migrasi hanya boleh dijalankan lewat CLI.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = db();
// Fail closed before touching data if APP_KEY is missing or still a placeholder.
private_identifier_key('identifier-encryption');
private_identifier_key('identifier-index');

$pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
    bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
    bucket_type ENUM('account','ip') NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_auth_attempt_lock (locked_until, window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function migration_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function migration_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->query('SHOW INDEX FROM ' . preg_replace('/[^a-z_]/', '', $table));
    foreach ($stmt->fetchAll() as $row) {
        if (($row['Key_name'] ?? '') === $index) return true;
    }
    return false;
}

function migration_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!migration_column_exists($pdo, $table, $column)) {
        $allowed = [
            'family_records' => ['family_number_cipher' => 'TEXT NULL', 'family_number_hash' => 'CHAR(64) NULL'],
            'residents' => ['nik_cipher' => 'TEXT NULL', 'nik_hash' => 'CHAR(64) NULL'],
            'resident_change_requests' => ['source_snapshot' => 'JSON NULL'],
        ];
        if (!isset($allowed[$table][$column]) || $allowed[$table][$column] !== $definition) {
            throw new RuntimeException('Kolom migrasi tidak dikenal.');
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

migration_add_column($pdo, 'family_records', 'family_number_cipher', 'TEXT NULL');
migration_add_column($pdo, 'family_records', 'family_number_hash', 'CHAR(64) NULL');
migration_add_column($pdo, 'residents', 'nik_cipher', 'TEXT NULL');
migration_add_column($pdo, 'residents', 'nik_hash', 'CHAR(64) NULL');
migration_add_column($pdo, 'resident_change_requests', 'source_snapshot', 'JSON NULL');

// Expand the enum before marking older pending requests as conflicts. Existing requests
// without a source snapshot cannot be safely auto-approved after a code upgrade.
$pdo->exec("ALTER TABLE resident_change_requests MODIFY status ENUM('draft','submitted','in_review','revision_requested','conflict','approved','rejected') NOT NULL DEFAULT 'draft'");
$pdo->exec("UPDATE resident_change_requests SET status='conflict', review_note=CONCAT('[KONFLIK] Snapshot sumber belum ada setelah pembaruan. Admin Desa harus membandingkan data terkini sebelum meminta revisi.'), reviewed_at=NOW() WHERE source_snapshot IS NULL AND status IN ('submitted','in_review','revision_requested')");

foreach ([
    ['family_records', 'uq_family_number'],
    ['residents', 'uq_resident_nik'],
] as [$table, $oldIndex]) {
    if (migration_index_exists($pdo, $table, $oldIndex)) {
        $pdo->exec('ALTER TABLE ' . $table . ' DROP INDEX ' . $oldIndex);
    }
}

$familiesUpdated = 0;
$residentsUpdated = 0;
$pdo->beginTransaction();
try {
    $families = $pdo->query('SELECT id,family_number,family_number_cipher,family_number_hash FROM family_records ORDER BY id FOR UPDATE')->fetchAll();
    $familyUpdate = $pdo->prepare('UPDATE family_records SET family_number=?,family_number_cipher=?,family_number_hash=? WHERE id=?');
    foreach ($families as $row) {
        $cipher = (string)($row['family_number_cipher'] ?? '');
        $plain = $cipher !== '' ? decrypt_private_identifier($cipher) : normalize_private_identifier((string)$row['family_number']);
        if (!is_string($plain)) {
            throw new RuntimeException('Nomor KK pada keluarga ID ' . (int)$row['id'] . ' tidak dapat dibuka. Migrasi dihentikan tanpa menebak data; periksa APP_KEY dan cadangan database.');
        }
        $hash = private_identifier_hash($plain);
        if (!empty($row['family_number_hash']) && !hash_equals((string)$row['family_number_hash'], $hash)) {
            throw new RuntimeException('Hash nomor KK tidak cocok pada keluarga ID ' . (int)$row['id'] . '. Migrasi dihentikan.');
        }
        $familyUpdate->execute([mask_private_identifier($plain), $cipher !== '' ? $cipher : encrypt_private_identifier($plain), $hash, (int)$row['id']]);
        $familiesUpdated++;
    }

    $residents = $pdo->query('SELECT id,nik,nik_cipher,nik_hash FROM residents ORDER BY id FOR UPDATE')->fetchAll();
    $residentUpdate = $pdo->prepare('UPDATE residents SET nik=?,nik_cipher=?,nik_hash=? WHERE id=?');
    foreach ($residents as $row) {
        $cipher = (string)($row['nik_cipher'] ?? '');
        $plain = $cipher !== '' ? decrypt_private_identifier($cipher) : normalize_private_identifier((string)$row['nik']);
        if (!is_string($plain)) {
            throw new RuntimeException('NIK pada penduduk ID ' . (int)$row['id'] . ' tidak dapat dibuka. Migrasi dihentikan tanpa menebak data; periksa APP_KEY dan cadangan database.');
        }
        $hash = private_identifier_hash($plain);
        if (!empty($row['nik_hash']) && !hash_equals((string)$row['nik_hash'], $hash)) {
            throw new RuntimeException('Hash NIK tidak cocok pada penduduk ID ' . (int)$row['id'] . '. Migrasi dihentikan.');
        }
        $residentUpdate->execute([mask_private_identifier($plain), $cipher !== '' ? $cipher : encrypt_private_identifier($plain), $hash, (int)$row['id']]);
        $residentsUpdated++;
    }

    $duplicateFamily = $pdo->query('SELECT family_number_hash FROM family_records GROUP BY family_number_hash HAVING COUNT(*)>1 LIMIT 1')->fetchColumn();
    $duplicateNik = $pdo->query('SELECT nik_hash FROM residents GROUP BY nik_hash HAVING COUNT(*)>1 LIMIT 1')->fetchColumn();
    if ($duplicateFamily !== false) throw new RuntimeException('Ada nomor KK duplikat setelah normalisasi. Minta admin memeriksa data sebelum migrasi dilanjutkan.');
    if ($duplicateNik !== false) throw new RuntimeException('Ada NIK duplikat setelah normalisasi. Minta admin memeriksa data sebelum migrasi dilanjutkan.');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Migrasi dibatalkan: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec('ALTER TABLE family_records MODIFY family_number CHAR(16) NOT NULL, MODIFY family_number_cipher TEXT NOT NULL, MODIFY family_number_hash CHAR(64) NOT NULL');
$pdo->exec('ALTER TABLE residents MODIFY nik CHAR(16) NOT NULL, MODIFY nik_cipher TEXT NOT NULL, MODIFY nik_hash CHAR(64) NOT NULL');

if (!migration_index_exists($pdo, 'family_records', 'uq_family_number_hash')) {
    $pdo->exec('ALTER TABLE family_records ADD UNIQUE KEY uq_family_number_hash (family_number_hash)');
}
if (!migration_index_exists($pdo, 'residents', 'uq_resident_nik_hash')) {
    $pdo->exec('ALTER TABLE residents ADD UNIQUE KEY uq_resident_nik_hash (nik_hash)');
}

fwrite(STDOUT, "Migrasi selesai. Keluarga diperiksa: {$familiesUpdated}; penduduk diperiksa: {$residentsUpdated}.\n");
fwrite(STDOUT, "Kolom NIK/KK kini menyimpan nilai bertopeng, nilai penuh terenkripsi, dan HMAC untuk pemeriksaan duplikasi.\n");
fwrite(STDOUT, "Simpan APP_KEY dengan aman. Jangan mengganti atau menghilangkannya tanpa prosedur rotasi kunci dan pemulihan yang teruji.\n");
