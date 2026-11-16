<?php
namespace Latra\Domain\Security;

use RuntimeException;

final class SecretKeyPolicy
{
    public static function bytes(string $raw): string
    {
        if ($raw === '') throw new RuntimeException('APP_KEY is not set. Run scripts/install.php to generate one.');
        $bin = ctype_xdigit($raw) && strlen($raw) === 64 ? hex2bin($raw) : base64_decode($raw, true);
        if ($bin === false || strlen($bin) < 32) throw new RuntimeException('APP_KEY must be 32 bytes (64 hex chars or base64).');
        return substr($bin, 0, 32);
    }
}
