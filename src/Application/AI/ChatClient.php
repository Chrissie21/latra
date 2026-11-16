<?php
namespace Latra\Application\AI;

interface ChatClient
{
    public function isConfigured(): bool;
    public function model(): string;
    public function chat(array $messages,array $tools=[],int $maxTokens=800,float $temperature=0.2): array;
}
