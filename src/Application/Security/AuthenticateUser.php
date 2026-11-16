<?php
namespace Latra\Application\Security;

use Closure;
use Latra\Domain\Security\AuthenticationPolicy;

final class AuthenticateUser
{
    private Closure $now;
    private Closure $format;
    private Closure $audit;
    private Closure $logAttempt;
    public function __construct(private AuthRepository $repository, private AuthSession $session,
        callable $now, callable $format, callable $audit, callable $logAttempt)
    {
        $this->now = Closure::fromCallable($now);
        $this->format = Closure::fromCallable($format);
        $this->audit = Closure::fromCallable($audit);
        $this->logAttempt = Closure::fromCallable($logAttempt);
    }
    public function attempt(string $username, string $password, array $extra = []): bool
    {
        $this->repository->ensure();
        $user = $this->repository->activeUser($username);
        $now = ($this->now)();
        if ($user && AuthenticationPolicy::locked($user, $now)) {
            ($this->audit)('login_locked', 'user', $username);
            ($this->logAttempt)($username, 'locked', $extra);
            return false;
        }
        if (!$user || !password_verify($password, $user['password_hash'])) {
            if ($user) {
                $failures = (int) ($user['failed_logins'] ?? 0) + 1;
                $lockedUntil = AuthenticationPolicy::lockUntil($failures, $now);
                $this->repository->failedLogin((int) $user['id'], $failures,
                    $lockedUntil === null ? null : ($this->format)($lockedUntil));
            }
            ($this->audit)('login_failed', 'user', $username);
            ($this->logAttempt)($username, 'failed', $extra);
            return false;
        }
        $this->session->login(AuthenticationPolicy::identity($user), $now);
        $this->repository->successfulLogin((int) $user['id'], ($this->format)($now));
        ($this->audit)('login', 'user', $user['username']);
        ($this->logAttempt)($user['username'], 'success', $extra);
        return true;
    }
}
