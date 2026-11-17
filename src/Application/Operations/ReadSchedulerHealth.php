<?php
declare(strict_types=1);

namespace Latra\Application\Operations;

use Closure;

/** Payload-free scheduler status for operator health checks. */
final class ReadSchedulerHealth
{
    private Closure $lastTick;

    public function __construct(private OperatorActionRepository $repository, callable $lastTick,
        private string $appEnv, private string $appVersion)
    {
        $this->lastTick = Closure::fromCallable($lastTick);
    }

    public function read(): array
    {
        $health = $this->repository->schedulerHealth();
        return [
            'ok' => true,
            'last_tick_at' => ($this->lastTick)() ?: null,
            'last_run_at' => $health['last_run_at'],
            'enabled_buckets' => $health['enabled_buckets'],
            'backlog' => $health['backlog'],
            'dead_letter' => $health['dead_letter'],
            'app_env' => $this->appEnv,
            'app_version' => $this->appVersion,
        ];
    }
}
