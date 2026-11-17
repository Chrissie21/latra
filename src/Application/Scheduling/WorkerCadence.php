<?php
declare(strict_types=1);

namespace Latra\Application\Scheduling;

/**
 * Long-running worker cadence: fast capture while an emergency event is active,
 * otherwise the ordinary poll interval, plus a fixed job-tick interval.
 */
final class WorkerCadence
{
    private const JOB_INTERVAL_SECONDS = 15;

    public function sleepSeconds(int $activeEmergencies): int
    {
        return $activeEmergencies > 0 ? 2 : 5;
    }

    public function jobIntervalSeconds(): int
    {
        return self::JOB_INTERVAL_SECONDS;
    }

    public function jobIntervalNanos(): int
    {
        return self::JOB_INTERVAL_SECONDS * 1_000_000_000;
    }
}
