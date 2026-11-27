<?php
namespace Latra\Application\Operations;

use Latra\Domain\Collection\Vehicle;

interface OperatorReadRepository
{
    public function vehicle(int $id): ?Vehicle;
    public function device(int $id): ?array;
    public function latestMessageWithIdentity(int $id): ?array;
    public function receipt(int $id): ?array;
    public function message(int $id): ?array;
    public function activityStatistics(): array;
    public function offlineSeconds(): int;
}
