<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migration hanya boleh dijalankan lewat CLI.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = db();

function letter_migration_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function letter_migration_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->query('SHOW INDEX FROM ' . preg_replace('/[^a-z_]/', '', $table));
    foreach ($stmt->fetchAll() as $row) {
        if (($row['Key_name'] ?? '') === $index) return true;
    }
    return false;
}

function letter_migration_constraint_exists(PDO $pdo, string $table, string $constraint): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=?');
    $stmt->execute([$table, $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

$columns = [
    'letter_number' => 'VARCHAR(100) NULL',
    'letter_snapshot' => 'JSON NULL',
    'letter_issued_by' => 'BIGINT UNSIGNED NULL',
    'letter_issued_at' => 'DATETIME NULL',
];
foreach ($columns as $name => $definition) {
    if (!letter_migration_column_exists($pdo, 'service_requests', $name)) {
        $pdo->exec('ALTER TABLE service_requests ADD COLUMN ' . $name . ' ' . $definition);
        fwrite(STDOUT, "Added service_requests.{$name}\n");
    }
}

if (!letter_migration_index_exists($pdo, 'service_requests', 'uq_service_letter_number')) {
    $pdo->exec('ALTER TABLE service_requests ADD UNIQUE KEY uq_service_letter_number (letter_number)');
    fwrite(STDOUT, "Added unique service letter number index\n");
}

if (!letter_migration_constraint_exists($pdo, 'service_requests', 'fk_request_letter_issuer')) {
    $pdo->exec('ALTER TABLE service_requests ADD CONSTRAINT fk_request_letter_issuer FOREIGN KEY (letter_issued_by) REFERENCES app_users(id) ON DELETE SET NULL');
    fwrite(STDOUT, "Added service letter issuer foreign key\n");
}

fwrite(STDOUT, "Service letter migration complete; safe to run again.\n");
