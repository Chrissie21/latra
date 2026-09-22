#!/usr/bin/env php
<?php
/**
 * One-command install: schema, APP_KEY, admin user.
 *   php scripts/install.php [username] [password]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Automation.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$envPath = dirname(__DIR__) . '/.env';
if (!is_readable($envPath)) {
    copy(dirname(__DIR__) . '/.env.example', $envPath);
    echo "Created .env from example. Fill Wialon/LATRA credentials, then re-run.\n";
}

$env = file_get_contents($envPath);
if (!preg_match('/^APP_KEY=.+$/m', $env) || preg_match('/^APP_KEY=\s*$/m', $env)) {
    $key = bin2hex(random_bytes(32));
    if (preg_match('/^APP_KEY=.*$/m', $env)) {
        $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $env);
    } else {
        $env .= "\nAPP_KEY={$key}\n";
    }
    file_put_contents($envPath, $env);
    putenv('APP_KEY=' . $key);
    $_ENV['APP_KEY'] = $key;
    echo "Generated APP_KEY.\n";
}

Schema::ensure();

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? bin2hex(random_bytes(8));
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algo);
$exists = Database::fetch('SELECT id FROM app_users WHERE username = ?', [$username]);
if ($exists) {
    Database::update('app_users', [
        'password_hash' => $hash,
        'is_active' => 1,
        'role' => 'admin',
        'failed_logins' => 0,
        'locked_until' => null,
    ], 'id = ?', [(int) $exists['id']]);
    echo "Updated admin '{$username}'.\n";
} else {
    Database::insert('app_users', [
        'username' => $username,
        'password_hash' => $hash,
        'display_name' => ucfirst($username),
        'role' => 'admin',
        'is_active' => 1,
    ]);
    echo "Created admin '{$username}'.\n";
}
echo "Password: {$password}\n";
echo "Start the worker: php bin/worker.php\n";
echo "Or install systemd: sudo cp scripts/latra-worker.service /etc/systemd/system/ && sudo systemctl enable --now latra-worker\n";
