<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php bin/create_owner.php <username> <full-name> <password>\n");
    fwrite(STDERR, "Run only on a trusted terminal. Password must be at least 20 characters.\n");
    exit(2);
}
[, $username, $fullName, $password] = $argv;
if (!preg_match('/^[a-z0-9._-]{4,80}$/', $username)) {
    fwrite(STDERR, "Invalid username.\n");
    exit(2);
}
if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 160 || strlen($password) < 20) {
    fwrite(STDERR, "Name must be 3-160 characters and password at least 20 characters.\n");
    exit(2);
}

try {
    $pdo = db();
    $roleColumn = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'role'")->fetch();
    if (!$roleColumn || !str_contains(strtolower((string)($roleColumn['Type'] ?? '')), "'owner'")) {
        fwrite(STDERR, "Owner role is not in the database schema. Run php bin/migrate_owner_role.php first.\n");
        exit(1);
    }
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM app_users WHERE role='owner'")->fetchColumn();
    if ($existing > 0) {
        fwrite(STDERR, "An Owner account already exists. Refusing to create a second Owner through this bootstrap script.\n");
        exit(1);
    }
    $stmt = $pdo->prepare("INSERT INTO app_users (username,full_name,password_hash,role) VALUES (?,?,?,'owner')");
    $stmt->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int)$pdo->lastInsertId();
    audit_log($id, 'bootstrap_owner', 'app_users', (string)$id);
    fwrite(STDOUT, "Owner account created for username: {$username}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create Owner; the username may already exist or the database is unavailable.\n");
    exit(1);
}
