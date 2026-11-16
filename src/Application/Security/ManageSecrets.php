<?php
namespace Latra\Application\Security;

use Closure;
use Throwable;

final class ManageSecrets
{
    private Closure $readFailure;
    public function __construct(private SecretRepository $repository, private SecretCipher $cipher, callable $readFailure)
    {
        $this->readFailure = Closure::fromCallable($readFailure);
    }
    public function put(string $key, string $plain): void
    {
        $this->repository->ensure();
        $this->repository->putCiphertext($key, $this->cipher->encrypt($plain));
    }
    public function get(string $key, string $fallback = ''): string
    {
        $this->repository->ensure();
        $blob = $this->repository->ciphertext($key);
        if ($blob === null || $blob === '') return $fallback;
        try {
            $plain = $this->cipher->decrypt($blob);
            return $plain !== '' ? $plain : $fallback;
        } catch (Throwable $error) {
            // No key name, plaintext, ciphertext or provider exception is sent to the logger.
            ($this->readFailure)();
            return $fallback;
        }
    }
    public function has(string $key): bool
    {
        $this->repository->ensure();
        return $this->repository->has($key);
    }
}
