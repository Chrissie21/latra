<?php
namespace Latra\Domain\Scheduling;

final class SchedulePolicy
{
    public static function jobDelay(int $interval): int { return max(1, $interval); }
    public static function failedJobDelay(): int { return 30; }
    public static function bucketDelay(): int { return 180; }

    /** Normal polling cadence when `poll_interval_seconds` is not configured. */
    public static function pollIntervalSeconds(): int { return 180; }

    public static function bucketResult(array $stats): array
    {
        $failed = (int) ($stats['failed'] ?? 0);
        $success = (int) ($stats['pushed'] ?? 0);
        $skipped = (int) ($stats['skipped'] ?? 0) + (int) ($stats['denied'] ?? 0);
        $reason = $skipped > 0
            ? ((int) ($stats['denied'] ?? 0) > 0
                ? 'Some vehicles were skipped because Wialon access was denied or the unit ID is unavailable.'
                : 'Some vehicles were skipped because no new Wialon telemetry was available for this interval.')
            : null;
        return ['failed'=>$failed, 'success'=>$success, 'skipped'=>$skipped,
            'total'=>$success + $failed + $skipped, 'reason'=>$reason];
    }
}
