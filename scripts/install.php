#!/usr/bin/env php
<?php
/**
 * One-command install: schema, APP_KEY, admin user.
 *   php scripts/install.php [username] [password]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

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
$updated = (new \Latra\Application\Security\ProvisionOperatorAccount(
    new \Latra\Infrastructure\Persistence\PdoOperatorAccountRepository(Database::getInstance()),
    new \Latra\Infrastructure\Security\NativePasswordHasher(),
))->provision($username, $password, ['role' => 'admin', 'reset_lockout' => true]);
echo $updated ? "Updated admin '{$username}'.\n" : "Created admin '{$username}'.\n";
echo "Password: {$password}\n";
echo "Start the worker: php bin/worker.php\n";
echo "Or install systemd: sudo cp scripts/latra-worker.service /etc/systemd/system/ && sudo systemctl enable --now latra-worker\n";
