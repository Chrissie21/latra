<?php
namespace Latra\Application\AI;

interface AssistantEffects
{
    public function allowRequest(int $user,int $max): bool;
    public function logAttempt(string $id,array $user,?int $latency,array $tools,int $http,?int $tokens=null): void;
    public function failure(string $message): void;
}
