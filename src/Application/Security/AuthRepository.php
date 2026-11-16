<?php
namespace Latra\Application\Security;

interface AuthRepository
{
    public function ensure(): void;
    public function activeUser(string $name): ?array;
    public function failedLogin(int $id, int $failures, ?string $lockedUntil): void;
    public function successfulLogin(int $id, string $at): void;
    public function incrementRate(string $key, int $window): int;
    public function appendAudit(array $entry): void;
}
