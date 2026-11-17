<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Security;

use Latra\Application\Security\PasswordHasher;

/** Argon2id when the PHP build provides it, otherwise the platform default. */
final class NativePasswordHasher implements PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}
