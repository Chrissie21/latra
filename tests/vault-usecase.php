<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Security\SecretRepository;
use Latra\Application\Security\ManageSecrets;
use Latra\Infrastructure\Security\AesGcmSecretCipher;
use Latra\Domain\Security\SecretKeyPolicy;
function secretAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$repo = new class implements SecretRepository {
    public array $values = [];
    public function ensure(): void {}
    public function putCiphertext(string $key, string $value): void { $this->values[$key] = $value; }
    public function ciphertext(string $key): ?string { return $this->values[$key] ?? null; }
    public function has(string $key): bool { return array_key_exists($key,$this->values); }
};
$warnings = [];
$rawKey = str_repeat('12',32);
$cipher = new AesGcmSecretCipher(static fn(): string => $rawKey);
$vault = new ManageSecrets($repo, $cipher, static function() use (&$warnings): void { $warnings[] = true; });
$vault->put('synthetic', 'synthetic secret é');
secretAssert($vault->get('synthetic') === 'synthetic secret é' && $vault->has('synthetic')
    && $repo->values['synthetic'] !== 'synthetic secret é', 'Vault plaintext exposure or roundtrip failure');
$first = $repo->values['synthetic']; $vault->put('synthetic','synthetic secret é');
secretAssert($first !== $repo->values['synthetic'] && strlen(base64_decode($first,true)) === 28 + strlen('synthetic secret é'), 'Nonce reuse or ciphertext format changed');
// The existing IV + 16-byte tag + ciphertext format must remain readable across the extraction.
$bytes = base64_decode($first,true);
$plain = openssl_decrypt(substr($bytes,28),'aes-256-gcm',hex2bin($rawKey),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
secretAssert($plain === 'synthetic secret é', 'Existing AES-GCM storage format changed');
$bytes[28] = chr(ord($bytes[28]) ^ 1); $repo->values['synthetic'] = base64_encode($bytes);
secretAssert($vault->get('synthetic','fallback') === 'fallback' && $warnings === [true], 'Tampering not rejected/fallback not observed');
secretAssert($vault->get('absent','default') === 'default' && !$vault->has('absent'), 'Missing-secret behavior changed');
secretAssert(SecretKeyPolicy::bytes(base64_encode(str_repeat('k',40))) === str_repeat('k',32), 'Existing base64 key truncation changed');
foreach (['', 'invalid', base64_encode('short')] as $invalid) {
    try { SecretKeyPolicy::bytes($invalid); throw new LogicException('Invalid key accepted'); }
    catch (RuntimeException $error) { secretAssert(str_contains($error->getMessage(),'APP_KEY'), 'Key validation error changed'); }
}
$repo->values['synthetic'] = 'not base64';
secretAssert($vault->get('synthetic','fallback') === 'fallback', 'Corrupt ciphertext escaped read fallback');
echo "PASS vault encryption format, nonce freshness, tamper rejection, key validation and read fallback\n";
