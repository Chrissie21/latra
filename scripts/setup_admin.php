#!/usr/bin/env php
<?php
/**
 * Create or reset an operator account for the web UI.
 * Usage:
 *   php scripts/setup_admin.php [username] [password]
 * If password is omitted, a strong one is generated and printed once.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Auth::ensureTables();

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? '';

$generated = false;
if ($password === '') {
    $password = bin2hex(random_bytes(8));
    $generated = true;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$exists = Database::fetch('SELECT id FROM app_users WHERE username = ?', [$username]);
if ($exists) {
    Database::update('app_users', ['password_hash' => $hash, 'is_active' => 1], 'id = ?', [(int) $exists['id']]);
    echo "Account '{$username}' updated.\n";
} else {
    Database::insert('app_users', [
        'username' => $username,
        'password_hash' => $hash,
        'display_name' => ucfirst($username),
        'is_active' => 1,
    ]);
    echo "Account '{$username}' created.\n";
}

if ($generated) {
    echo "Generated password: {$password}\n";
    echo "Save it now \342\200\224 it won't be shown again.\n";
} else {
    echo "Password set.\n";
}