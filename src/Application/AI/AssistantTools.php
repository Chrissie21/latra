<?php
namespace Latra\Application\AI;

interface AssistantTools
{
    public function schemas(): array;
    public function execute(string $name,array $args): array;
}
