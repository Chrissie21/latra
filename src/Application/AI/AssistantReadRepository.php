<?php
namespace Latra\Application\AI;

/** Only the enumerated, read-only operational queries are exposed to the assistant. */
interface AssistantReadRepository
{
    public function one(AssistantReadQuery $query,array $params=[],bool $filtered=false): ?array;
    public function many(AssistantReadQuery $query,array $params=[],bool $filtered=false): array;
}
