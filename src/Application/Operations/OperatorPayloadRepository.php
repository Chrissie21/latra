<?php
namespace Latra\Application\Operations;

use Latra\Domain\Collection\Vehicle;

interface OperatorPayloadRepository
{
    public function vehicle(int $id): ?Vehicle;
    public function latestMessage(int $id): ?array;
    public function recentMessages(int $id,int $limit): array;
}
