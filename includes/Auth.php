<?php
require_once __DIR__ . '/autoload.php';
/**
 * Authentication, session guarding, CSRF protection, rate limiting and audit.
 *
 * API-only surface: the web UI (and its page guards, HTML token field and role
 * labels) was removed by owner decision, so this adapter exposes only the
 * session/credential/CSRF/audit contract the entry points use.
 */
class Auth {
    private static function repository(): \Latra\Infrastructure\Persistence\PdoAuthRepository {
        return new \Latra\Infrastructure\Persistence\PdoAuthRepository(Database::getInstance());
    }
    private static function session(): \Latra\Application\Security\ManageAuthSession {
        return new \Latra\Application\Security\ManageAuthSession(
            new \Latra\Infrastructure\Security\NativeAuthSession(), static fn(): int => time(),
            SESSION_TIMEOUT,
        );
    }


    public static function ensureTables(): void { self::repository()->ensure(); }

    /** Verify credentials; on success populate the session. */
    public static function attempt(string $username, string $password, array $extra = []): bool {
        return (new \Latra\Application\Security\AuthenticateUser(
            self::repository(), new \Latra\Infrastructure\Security\NativeAuthSession(),
            static fn(): int => time(), static fn(int $time): string => date(DATE_FORMAT, $time),
            static fn(string $action, string $target, string $detail) => self::audit($action, $target, $detail),
            static fn(string $name, string $result, array $context) => self::logLoginAttempt($name, $result, $context),
        ))->attempt($username, $password, $extra);
    }

    public static function check(): bool { return self::session()->check(); }

    public static function logout(): void { (new \Latra\Infrastructure\Security\NativeAuthSession())->clear(); }

    public static function user(): array {
        return $_SESSION['auth'] ?? ['uid' => 0, 'username' => '', 'display_name' => '', 'role' => ''];
    }

    public static function role(): string {
        return self::user()['role'] ?? '';
    }

    public static function canWrite(): bool { return \Latra\Domain\Security\AccessPolicy::canWrite(self::role()); }

    public static function requireWrite(): void {
        if (!self::canWrite()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Read-only role']);
            exit;
        }
    }

    public static function hasPermission(string $perm): bool { return \Latra\Domain\Security\AccessPolicy::hasPermission(self::role(), $perm); }

    public static function requirePermission(string $perm): void {
        if (!self::check()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        if (!self::hasPermission($perm)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'This action requires the "' . $perm . '" permission.']);
            exit;
        }
    }

    public static function csrfToken(): string { return self::session()->csrfToken(); }

    /** Validate a CSRF token from POST body, header or query string. */
    public static function verifyCsrf(?string $token): bool { return self::session()->verifyCsrf($token); }

    /** State-changing POST? performs the token check. */
    public static function requireCsrf(): void {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['_csrf'] ?? '';
        if (!self::verifyCsrf((string) $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    // Write a simple audit line.
    public static function audit(string $action, string $target = '', string $detail = '', $actor = null, $before = null, $after = null): void {
        try {
            $user = $actor === 'system' ? ['uid'=>0,'username'=>'system'] : (is_array($actor) ? $actor : self::user());
            (new \Latra\Application\Security\RecordAuditEntry(self::repository(), static fn(string $message) => error_log($message)))
                ->record($action, $target, $detail, $user, $_SERVER['REMOTE_ADDR'] ?? '', $before, $after);
        } catch (Throwable $error) {
            error_log('audit write skipped: ' . $error->getMessage());
        }
    }

    // Simple fixed-window rate limit.
    public static function rateLimit(string $key, int $max, int $windowSeconds): bool {
        return (new \Latra\Application\Security\EnforceRateLimit(self::repository(), static fn(): int => time()))->allows($key, $max, $windowSeconds);
    }

    public static function clientKey(string $prefix = 'ip'): string {
        return $prefix . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    /**
     * Append a full device-detail line for a login attempt to logs/login.log.
     * Never fatal: failures are silently dropped. Server-side values always
     * win over anything a client submits in $extra.
     *
     * @param string $username attempted username
     * @param string $result   success|failed|locked|rate_limited|invalid
     * @param array  $extra    optional client-supplied fingerprint fields
     */
    public static function logLoginAttempt(string $username, string $result, array $extra = []): void {
        (new \Latra\Infrastructure\Security\FileLoginAttemptLogger(
            defined('LOGS_DIR') ? LOGS_DIR : null, $_SERVER,
            defined('LOGS_DIR') && envValue('LOGIN_LOG_DNS', 'off') === 'on',
        ))->log($username, $result, $extra);
    }

    /**
     * Sanitize the client-side device fingerprint into a whitelisted array
     * that is safe to merge into a login.attempt log line.
     */
    public static function deviceExtra(string $json): array { return \Latra\Domain\Security\AuthenticationPolicy::deviceExtra($json); }
}
