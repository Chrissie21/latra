<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use Latra\Domain\Scheduling\RetryBackoffPolicy;

/**
 * Operating defaults and per-cycle budgets for the durable relay runtime.
 *
 * These are the values a deployment falls back to when nothing is configured, so
 * they belong with the rules rather than inside the composition adapter that
 * happens to read the config table.
 */
final class RelayRuntimePolicy
{
    /** Retry backoff never grows past this, whatever the attempt count. */
    public const RETRY_CAP_SECONDS = 900;

    /** Fallback outbound cap when `latra_rate_per_minute` is absent. */
    public const RATE_PER_MINUTE = 300;

    /** Units inspected per flush cycle; a failing unit blocks only itself. */
    public const PENDING_UNITS_PER_CYCLE = 200;

    /** Samples above the limit before an overspeed event. */
    public const OVERSPEED_CONSECUTIVE = 2;

    /** Minimum gap between repeated momentary events for one vehicle. */
    public const EVENT_COOLDOWN_SECONDS = 300;

    /** Work budget for one flush: emergency sweeps stay small and frequent. */
    public static function flushLimit(bool $emergencyOnly): int
    {
        return $emergencyOnly ? 40 : 80;
    }

    /** Wall-clock budget for one flush cycle. */
    public static function flushSeconds(): int
    {
        return 120;
    }

    public static function backoff(int $attempt): int
    {
        return min(self::RETRY_CAP_SECONDS, RetryBackoffPolicy::next($attempt));
    }
}
