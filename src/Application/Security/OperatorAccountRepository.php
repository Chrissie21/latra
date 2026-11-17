<?php
declare(strict_types=1);

namespace Latra\Application\Security;

/** Operator account provisioning used by the install and admin-reset commands. */
interface OperatorAccountRepository
{
    public function findIdByName(string $username): ?int;

    /** @param array<string,mixed> $account */
    public function create(array $account): void;

    /** @param array<string,mixed> $fields */
    public function update(int $id, array $fields): void;
}
