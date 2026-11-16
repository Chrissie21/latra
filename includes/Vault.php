<?php
require_once __DIR__ . '/autoload.php';

/** Compatibility names for secret configuration; no credential is read until requested. */
class Vault {
    private static function repository(): \Latra\Infrastructure\Persistence\PdoSecretRepository {
        return new \Latra\Infrastructure\Persistence\PdoSecretRepository(Database::getInstance());
    }
    private static function cipher(): \Latra\Infrastructure\Security\AesGcmSecretCipher {
        return new \Latra\Infrastructure\Security\AesGcmSecretCipher(static fn(): string => envValue('APP_KEY', ''));
    }
    private static function work(): \Latra\Application\Security\ManageSecrets {
        return new \Latra\Application\Security\ManageSecrets(self::repository(), self::cipher(),
            static fn() => error_log('vault decrypt failed for key (name only)'));
    }
    public static function ensureTable(): void { self::repository()->ensure(); }
    public static function put(string $key, string $plain): void { self::work()->put($key, $plain); }
    public static function get(string $key, string $fallback = ''): string { return self::work()->get($key, $fallback); }
    public static function has(string $key): bool { return self::work()->has($key); }
    public static function encrypt(string $plain): string { return self::cipher()->encrypt($plain); }
    public static function decrypt(string $blob): string { return self::cipher()->decrypt($blob); }

    public static function wialonToken(): string {
        return self::get('wialon_token', defined('WIALON_TOKEN') ? WIALON_TOKEN : '');
    }

    public static function wialonUrl(): string {
        return self::get('wialon_base_url', defined('WIALON_BASE_URL') ? WIALON_BASE_URL : '');
    }

    public static function latraUrl(): string {
        return self::get('latra_api_url', defined('LATRA_API_URL') ? LATRA_API_URL : '');
    }

    public static function latraUser(): string {
        return self::get('latra_username', defined('LATRA_USERNAME') ? LATRA_USERNAME : '');
    }

    public static function latraPass(): string {
        return self::get('latra_password', defined('LATRA_PASSWORD') ? LATRA_PASSWORD : '');
    }

}
