<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}
if ($argc < 4) {
    fwrite(STDERR, "Usage: php bin/create_admin.php <username> <full-name> <password>\n");
    fwrite(STDERR, "Password must be at least 14 characters. Run this only on a trusted server terminal.\n");
    exit(2);
}
[$script, $username, $fullName, $password] = $argv;
if (!preg_match('/^[a-z0-9._-]{4,80}$/', $username)) {
    fwrite(STDERR, "Invalid username.\n"); exit(2);
}
if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 160 || strlen($password) < 14) {
    fwrite(STDERR, "Name must be 3-160 chars and password at least 14 chars.\n"); exit(2);
}
try {
    $stmt = db()->prepare("INSERT INTO app_users (username,full_name,password_hash,role) VALUES (?,?,?,'admin_desa')");
    $stmt->execute([$username,$fullName,password_hash($password,PASSWORD_DEFAULT)]);
    $id = (int)db()->lastInsertId();
    audit_log($id, 'bootstrap_admin', 'app_users', (string)$id);
    fwrite(STDOUT, "Admin desa account created for username: {$username}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create admin; the username may already exist or the DB is unavailable.\n");
    exit(1);
}
