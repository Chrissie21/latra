<?php
namespace Latra\Domain\Collection;

final class OperatorPayloadPolicy
{
    public const RECENT_SECONDS = 600;
    public const LIVE_HISTORY_SECONDS = 7 * 86400;
    public const BULK_LIMIT = 10;
    public static function recent(int $timestamp,int $now): bool { return $timestamp >= $now-self::RECENT_SECONDS; }
}
