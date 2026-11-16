<?php
namespace Latra\Domain\Ingestion;

final class GpsHealthPolicy
{
    public const MAX_INTERVAL_SECONDS = 180;
    public const OFFLINE_SECONDS = 900;
    public static function emptyCounts(): array { return ['ok'=>0,'stale'=>0,'offline'=>0,'unknown'=>0]; }
    public static function classify(?int $lastGps, int $now): array
    {
        if (!$lastGps) return ['status'=>'unknown','minutes'=>null];
        $age = $now - $lastGps;
        return ['status'=>$age <= self::MAX_INTERVAL_SECONDS ? 'ok' : ($age <= self::OFFLINE_SECONDS ? 'stale' : 'offline'),
            'minutes'=>(int)floor($age / 60)];
    }
}
