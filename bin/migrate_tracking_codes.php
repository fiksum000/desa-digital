<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migrasi hanya boleh dijalankan melalui CLI.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = db();

/** Returns the declared length for a column, or null when the column is absent. */
function tracking_migration_column_length(PDO $pdo, string $table, string $column): ?int {
    $stmt = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $stmt->execute([$table, $column]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? null : (int)$value;
}

foreach (['service_requests', 'complaints'] as $table) {
    $length = tracking_migration_column_length($pdo, $table, 'tracking_code');
    if ($length === null) {
        fwrite(STDERR, "Kolom {$table}.tracking_code tidak ditemukan; pastikan skema dasar sudah diterapkan.\n");
        exit(1);
    }
    if ($length < 48) {
        // Table names come only from this fixed allowlist, never from user input.
        $pdo->exec('ALTER TABLE ' . $table . ' MODIFY COLUMN tracking_code VARCHAR(48) NOT NULL');
        fwrite(STDOUT, "Widened {$table}.tracking_code to VARCHAR(48).\n");
    } else {
        fwrite(STDOUT, "{$table}.tracking_code already supports the new token length.\n");
    }
}

// Update only the old built-in example, never a custom label chosen by an admin.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='site_content'");
$stmt->execute();
if ((int)$stmt->fetchColumn() > 0) {
    $update = $pdo->prepare('UPDATE site_content SET content_value=? WHERE content_key=? AND content_value=?');
    $update->execute([
        'Contoh: SR-261017-0123456789ABCDEF0123456789ABCDEF',
        'tracking_placeholder',
        'Contoh: SR-260101-AB12CD34',
    ]);
    if ($update->rowCount() > 0) fwrite(STDOUT, "Updated the built-in tracking-code example.\n");
}

fwrite(STDOUT, "Tracking-code migration complete; safe to run again. Existing shorter codes remain valid.\n");
