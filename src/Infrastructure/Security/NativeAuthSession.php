<?php
namespace Latra\Infrastructure\Security;

use Latra\Application\Security\AuthSession;

final class NativeAuthSession implements AuthSession
{
    public function start(): void { if (session_status() === PHP_SESSION_NONE) session_start(); }
    public function get(string $key): mixed { return $_SESSION[$key] ?? null; }
    public function set(string $key, mixed $value): void { $_SESSION[$key] = $value; }
    public function login(array $identity, int $now): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = $identity;
        $_SESSION['auth_started'] = $now;
        $_SESSION['last_activity'] = $now;
    }
    public function clear(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
}
