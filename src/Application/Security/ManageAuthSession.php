<?php
namespace Latra\Application\Security;

use Closure;
use Latra\Domain\Security\AuthenticationPolicy;

final class ManageAuthSession
{
    private Closure $now;
    public function __construct(private AuthSession $session, callable $now, private int $idleTimeout)
    {
        $this->now = Closure::fromCallable($now);
    }
    public function check(): bool
    {
        $this->session->start();
        if (!$this->session->get('auth')) return false;
        $now = ($this->now)();
        if (AuthenticationPolicy::sessionExpired($now, $this->session->get('last_activity'),
            $this->session->get('auth_started'), $this->idleTimeout)) {
            $this->session->clear();
            return false;
        }
        $this->session->set('last_activity', $now);
        return true;
    }
    public function csrfToken(): string
    {
        $this->session->start();
        $token = $this->session->get('csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }
        return $token;
    }
    public function verifyCsrf(?string $token): bool { return $token ? hash_equals($this->csrfToken(), $token) : false; }
}
