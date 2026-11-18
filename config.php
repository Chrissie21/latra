<?php
/**
 * LATRA-Wialon Integration Hub - Configuration
 */

function loadEnvFile(string $path): void {
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function envValue(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : (string) $value;
}

loadEnvFile(__DIR__ . '/.env');

define('LOGS_DIR', __DIR__ . '/logs');
if (!is_dir(LOGS_DIR)) {
    @mkdir(LOGS_DIR, 0755, true);
}

define('APP_ENV', envValue('APP_ENV', 'production'));
// Delivery is relay-only: the durable relay is the single LATRA delivery path.
// The legacy direct-send mode was retired deliberately, so an explicit
// unsupported value still fails closed instead of silently falling back.
define('DELIVERY_ARCHITECTURE', envValue('DELIVERY_ARCHITECTURE', 'relay'));
if (DELIVERY_ARCHITECTURE !== 'relay') {
    throw new RuntimeException('Unsupported delivery architecture; relay delivery is the only supported mode');
}

// Error reporting: safe off-by-default, verbose only in development.
error_reporting(E_ALL);
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOGS_DIR . '/error.log');

// Optional HTTPS enforcement once TLS is terminated at Apache.
define('FORCE_HTTPS', envValue('FORCE_HTTPS', 'off') === 'on');
if (FORCE_HTTPS
    && empty($_SERVER['HTTPS'])
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
    exit;
}

// Database Configuration
define('DB_HOST', envValue('DB_HOST', '127.0.0.1'));
define('DB_PORT', envValue('DB_PORT', ''));
define('DB_NAME', envValue('DB_NAME', 'latra_wialon'));
define('DB_USER', envValue('DB_USER', 'root'));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_CHARSET', envValue('DB_CHARSET', 'utf8mb4'));

// Wialon API Configuration
// Get token from: https://help.wialon.com/en/api/user-guide/getting-an-access-token
define('WIALON_BASE_URL', envValue('WIALON_BASE_URL', 'https://hst-api.wialon.com/wialon/ajax.html'));
define('WIALON_TOKEN', envValue('WIALON_TOKEN', ''));

// LATRA API Configuration
define('LATRA_API_URL', envValue('LATRA_API_URL', ''));
define('LATRA_USERNAME', envValue('LATRA_USERNAME', ''));
define('LATRA_PASSWORD', envValue('LATRA_PASSWORD', ''));

// Application Settings
define('APP_NAME', 'LATRA-Wialon');
define('APP_VERSION', '2.0.0');
define('APP_KEY', envValue('APP_KEY', ''));
define('TIMEZONE', 'Africa/Dar_es_Salaam');
define('DATE_FORMAT', 'Y-m-d H:i:s');

// Session
define('SESSION_TIMEOUT', 3600);

date_default_timezone_set('UTC');
if (!defined('DISPLAY_TZ')) {
    define('DISPLAY_TZ', TIMEZONE);
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // API-only surface: no page, asset or third-party origin is loaded any more.
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Hardened session bootstrap (shared by api.php and CLI-authenticated tooling)
if (session_status() === PHP_SESSION_NONE) {
    session_name('LATRA_SID');
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    session_start();
}

require_once __DIR__ . '/includes/autoload.php';
