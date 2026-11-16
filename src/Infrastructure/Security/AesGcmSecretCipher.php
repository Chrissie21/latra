<?php
namespace Latra\Infrastructure\Security;

use Closure;
use Latra\Application\Security\SecretCipher;
use Latra\Domain\Security\SecretKeyPolicy;
use RuntimeException;

final class AesGcmSecretCipher implements SecretCipher
{
    private Closure $rawKey;
    public function __construct(callable $rawKey) { $this->rawKey = Closure::fromCallable($rawKey); }
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', SecretKeyPolicy::bytes(($this->rawKey)()), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new RuntimeException('encrypt failed');
        return base64_encode($iv . $tag . $ct);
    }
    public function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('ciphertext truncated');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', SecretKeyPolicy::bytes(($this->rawKey)()),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('decrypt failed');
        return $plain;
    }
}
