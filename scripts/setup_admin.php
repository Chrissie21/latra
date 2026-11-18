#!/usr/bin/env php
<?php
/**
 * Create or reset an operator account for the web UI.
 * Usage:
 *   php scripts/setup_admin.php [username] [password]
 * If password is omitted, a strong one is generated and printed once.
 */
require_once __DIR__ . '/../config.php';

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

$updated = (new \Latra\Application\Security\ProvisionOperatorAccount(
    new \Latra\Infrastructure\Persistence\PdoOperatorAccountRepository(Database::getInstance()),
    new \Latra\Infrastructure\Security\NativePasswordHasher(),
))->provision($username, $password);
echo $updated ? "Account '{$username}' updated.\n" : "Account '{$username}' created.\n";

if ($generated) {
    echo "Generated password: {$password}\n";
    echo "Save it now \342\200\224 it won't be shown again.\n";
} else {
    echo "Password set.\n";
}