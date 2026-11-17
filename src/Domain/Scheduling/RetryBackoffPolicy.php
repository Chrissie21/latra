<?php
declare(strict_types=1);

namespace Latra\Domain\Scheduling;

/** Full-jitter exponential backoff for durable retries. */
final class RetryBackoffPolicy
{
    public const CAP_SECONDS = 600;

    /**
     * random_int(0, min(cap, base * 2^(attempt-1))), with a jitter ceiling of at least five seconds.
     * Attempt numbers below one behave like the first attempt.
     */
    public static function next(int $attempt): int
    {
        $exponent = max(0, $attempt - 1);
        $base = 30 * (2 ** $exponent);
        return random_int(0, min(self::CAP_SECONDS, max(5, (int) $base)));
    }
}
