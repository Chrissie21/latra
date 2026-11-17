<?php
declare(strict_types=1);

namespace Latra\Application\Security;

/** Password hashing port; the algorithm choice belongs to the infrastructure adapter. */
interface PasswordHasher
{
    public function hash(string $password): string;
}
