<?php
require_once __DIR__ . '/autoload.php';
/**
 * Authentication, session guarding, CSRF protection, rate limiting and audit.
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

    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
        if (class_exists('Schema')) {
            try { Schema::ensure(); } catch (Throwable $e) { error_log('schema ensure: ' . $e->getMessage()); }
        }
    }

    public static function logout(): void { (new \Latra\Infrastructure\Security\NativeAuthSession())->clear(); }

    public static function user(): array {
        return $_SESSION['auth'] ?? ['uid' => 0, 'username' => '', 'display_name' => '', 'role' => ''];
    }

    public static function role(): string {
        return self::user()['role'] ?? '';
    }

    public static function isAdmin(): bool {
        return self::role() === 'admin';
    }

    public static function canWrite(): bool { return \Latra\Domain\Security\AccessPolicy::canWrite(self::role()); }

    /** View (read-only) access: any authenticated internal or LATRA role. */
    public static function canView(): bool {
        return self::role() !== '';
    }

    public static function requireRole(array $roles): void {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    /** Require login + the given permission; used for page access. */
    public static function requireView(string $perm): void {
        self::requireLogin();
        if (!self::hasPermission($perm)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    public static function requireWrite(): void {
        if (!self::canWrite()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Read-only role']);
            exit;
        }
    }

    /**
     * LATRA-facing restricted roles. Internal roles (admin/operator) hold every permission.
     * Permission is enforced server-side — the UI only hides what it offers.
     */
    public static function roleLabels(): array { return \Latra\Domain\Security\AccessPolicy::roleLabels(); }

    /** Read-only permission set shared by the internal viewer role and LATRA viewers. */
    public static function viewerPermissions(): array { return \Latra\Domain\Security\AccessPolicy::viewerPermissions(); }

    public static function latraPermissions(): array { return \Latra\Domain\Security\AccessPolicy::latraPermissions(); }

    public static function isLatraRole(?string $role = null): bool { return \Latra\Domain\Security\AccessPolicy::isLatraRole($role ?? self::role()); }

    public static function hasPermission(string $perm): bool { return \Latra\Domain\Security\AccessPolicy::hasPermission(self::role(), $perm); }

    public static function requirePermission(string $perm): void {
        self::requireLogin();
        if (!self::hasPermission($perm)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'This action requires the "' . $perm . '" permission.']);
            exit;
        }
    }

    public static function csrfToken(): string { return self::session()->csrfToken(); }

    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . self::csrfToken() . '">';
    }

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