<?php
declare(strict_types=1);

namespace Latra\Domain\Security;

/**
 * Client-facing request budgets.
 *
 * These are operating policy, not transport detail: the entry point enforces them
 * but the limits themselves stay with the rules so they can be reviewed and tested
 * without an HTTP request.
 */
final class RateLimitPolicy
{
    /** All API traffic per client address. */
    public const API_PER_MINUTE = 300;
    public const API_WINDOW_SECONDS = 60;

    /** LATRA submission actions per client address per day. */
    public const PUSH_PER_DAY = 500;
    public const PUSH_WINDOW_SECONDS = 86400;

    public static function apiKey(string $clientKey): string
    {
        return 'api:' . $clientKey;
    }

    public static function pushKey(string $clientKey): string
    {
        return 'push:' . $clientKey;
    }
}
