<?php
/**
 * Secret storage.
 */
class Vault {
    public static function ensureTable(): void {
        Database::query("CREATE TABLE IF NOT EXISTS vault (
            vault_key VARCHAR(100) PRIMARY KEY,
            ciphertext TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    }

    public static function put(string $key, string $plain): void {
        self::ensureTable();
        $ct = self::encrypt($plain);
        Database::query(
            "INSERT INTO vault (vault_key, ciphertext) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext)",
            [$key, $ct]
        );
    }

    public static function get(string $key, string $fallback = ''): string {
        self::ensureTable();
        $row = Database::fetch("SELECT ciphertext FROM vault WHERE vault_key = ?", [$key]);
        if (!$row || $row['ciphertext'] === '') {
            return $fallback;
        }
        try {
            $plain = self::decrypt($row['ciphertext']);
            return $plain !== '' ? $plain : $fallback;
        } catch (Throwable $e) {
            error_log('vault decrypt failed for key (name only)');
            return $fallback;
        }
    }

    public static function has(string $key): bool {
        self::ensureTable();
        $row = Database::fetch("SELECT vault_key FROM vault WHERE vault_key = ?", [$key]);
        return (bool) $row;
    }

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

    private static function keyBytes(): string {
        $raw = envValue('APP_KEY', '');
        if ($raw === '') {
            throw new RuntimeException('APP_KEY is not set. Run scripts/install.php to generate one.');
        }
        $bin = ctype_xdigit($raw) && strlen($raw) === 64 ? hex2bin($raw) : base64_decode($raw, true);
        if ($bin === false || strlen($bin) < 32) {
            throw new RuntimeException('APP_KEY must be 32 bytes (64 hex chars or base64).');
        }
        return substr($bin, 0, 32);
    }

    public static function encrypt(string $plain): string {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::keyBytes(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('encrypt failed');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): string {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('ciphertext truncated');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::keyBytes(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('decrypt failed');
        }
        return $plain;
    }
}
