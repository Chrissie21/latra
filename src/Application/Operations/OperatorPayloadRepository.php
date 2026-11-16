<?php
namespace Latra\Application\Operations;

interface OperatorPayloadRepository
{
    public function vehicle(int $id): ?array;
    public function latestMessage(int $id): ?array;
    public function recentMessages(int $id,int $limit): array;
}
