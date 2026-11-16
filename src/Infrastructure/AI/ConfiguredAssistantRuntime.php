<?php
namespace Latra\Infrastructure\AI;

use Closure;
use Latra\Application\AI\AssistantRuntime;

final class ConfiguredAssistantRuntime implements AssistantRuntime
{
    private Closure $readConfig;
    private Closure $deviceHealth;
    public function __construct(callable $readConfig,callable $deviceHealth,private array $configured)
    {
        $this->readConfig=Closure::fromCallable($readConfig);
        $this->deviceHealth=Closure::fromCallable($deviceHealth);
    }
    public function config(string $key,string $fallback): string { return ($this->readConfig)($key,$fallback); }
    public function deviceSummary(): array { return ($this->deviceHealth)(); }
    public function sourceConfigured(string $name): bool { return $this->configured[$name] ?? false; }
}
