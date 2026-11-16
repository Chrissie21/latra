<?php
namespace Latra\Application\AI;

interface AssistantRepository
{
    public function ensure(): void;
    public function conversation(string $id,int $user): ?array;
    public function createConversation(string $id,int $user,string $title): void;
    /** Newest first, matching the bounded persistence query. */
    public function history(string $id,int $user,int $limit): array;
    public function jobs(): array;
    public function store(array $entry): void;
}
