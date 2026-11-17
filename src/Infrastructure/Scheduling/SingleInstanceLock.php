<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Scheduling;

/**
 * Single-instance tick lock for cron and the worker supervisor.
 *
 * A lock is stale after `$staleSeconds` or when its recorded pid no longer
 * answers, so a crashed process never blocks the scheduler permanently.
 */
final class SingleInstanceLock
{
    public function __construct(private string $lockFile, private int $staleSeconds) {}

    /** @return bool True when this process may run the tick. */
    public function acquire(): bool
    {
        $pidFile = $this->lockFile . '.pid';
        if (file_exists($this->lockFile)) {
            $age = time() - (int) file_get_contents($this->lockFile);
            $pid = (int) (file_exists($pidFile) ? file_get_contents($pidFile) : 0);
            $alive = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
            if ($age <= $this->staleSeconds && $alive) {
                return false;
            }
        }
        file_put_contents($this->lockFile, time());
        file_put_contents($pidFile, getmypid());
        return true;
    }

    public function release(): void
    {
        @unlink($this->lockFile . '.pid');
        @unlink($this->lockFile);
    }
}
