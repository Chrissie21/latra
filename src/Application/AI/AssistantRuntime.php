<?php
namespace Latra\Application\AI;

interface AssistantRuntime
{
    public function config(string $key,string $fallback): string;
    public function sourceConfigured(string $name): bool;
    public function deviceSummary(): array;
}
