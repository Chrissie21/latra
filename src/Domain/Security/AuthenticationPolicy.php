<?php
namespace Latra\Domain\Security;

final class AuthenticationPolicy
{
    public static function locked(array $user, int $now): bool {
        return !empty($user['locked_until']) && strtotime($user['locked_until']) > $now;
    }
    public static function lockUntil(int $failures, int $now): ?int { return $failures >= 8 ? $now + 900 : null; }
    public static function identity(array $user): array {
        return ['uid'=>(int)$user['id'], 'username'=>$user['username'],
            'display_name'=>$user['display_name'] ?: $user['username'], 'role'=>$user['role'] ?? 'operator'];
    }
    public static function sessionExpired(int $now, mixed $lastActivity, mixed $started, int $idleTimeout): bool {
        return ($lastActivity !== null && $now - (int)$lastActivity > $idleTimeout)
            || ($started !== null && $now - (int)$started > 43200);
    }
    public static function deviceExtra(string $json): array {
        $out = [];
        if ($json === '') {
            return $out;
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return $out;
        }
        foreach (['timezone', 'local_time', 'screen', 'languages', 'platform', 'cores', 'memory', 'connection', 'vendor'] as $k) {
            if (!isset($d[$k])) {
                continue;
            }
            $v = $d[$k];
            if (is_string($v) || is_int($v) || is_float($v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = implode(',', array_map('strval', $v));
            }
        }
        return $out;
    }
}
