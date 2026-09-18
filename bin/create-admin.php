<?php

/**
 * Creates (or promotes) a Platform Admin account — there is deliberately no
 * self-serve path to `account_type = 'admin'` (AuthController::signup only
 * accepts customer/provider), so the first admin must be seeded from a
 * trusted shell with database access.
 *
 * Usage:
 *   ADMIN_PASSWORD='...' php bin/create-admin.php <phone_number> "<Full Name>"
 *
 * The password is read from the ADMIN_PASSWORD env var (not argv) so it
 * doesn't land in shell history / process listings. Re-running for an
 * existing phone number promotes that user to admin and resets the password.
 */

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

use Construction\Config\Database;

$phone = $argv[1] ?? '';
$name = $argv[2] ?? '';
$password = (string) getenv('ADMIN_PASSWORD');

if ($phone === '' || $name === '' || strlen($password) < 12) {
    fwrite(STDERR, "Usage: ADMIN_PASSWORD='<12+ chars>' php bin/create-admin.php <phone_number> \"<Full Name>\"\n");
    exit(1);
}

$db = Database::connection();
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare('SELECT id FROM users WHERE phone_number = :phone');
$stmt->execute(['phone' => $phone]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare("UPDATE users SET account_type = 'admin', status = 'active', password_hash = :hash, full_name = :name, updated_at = NOW() WHERE id = :id")
        ->execute(['hash' => $hash, 'name' => $name, 'id' => $existing['id']]);
    echo "Promoted existing user #{$existing['id']} to admin.\n";
} else {
    $db->prepare(
        "INSERT INTO users (phone_number, password_hash, full_name, account_type, status, created_at, updated_at)
         VALUES (:phone, :hash, :name, 'admin', 'active', NOW(), NOW())"
    )->execute(['phone' => $phone, 'hash' => $hash, 'name' => $name]);
    echo "Created admin user #" . $db->lastInsertId() . ".\n";
}
