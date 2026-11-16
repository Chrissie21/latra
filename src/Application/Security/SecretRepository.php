<?php
namespace Latra\Application\Security;

interface SecretRepository
{
    public function ensure(): void;
    public function putCiphertext(string $key, string $value): void;
    public function ciphertext(string $key): ?string;
    public function has(string $key): bool;
}
