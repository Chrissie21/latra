<?php
namespace Latra\Domain\Maintenance;

final class RetentionPolicy
{
    public static function hourlyCutoff(int $now, int $hours): string
    {
        return gmdate('Y-m-d H:i:s', $now - max(1, $hours) * 3600);
    }
    public static function dailyCutoff(int $now, int $days): string
    {
        return gmdate('Y-m-d H:i:s', $now - $days * 86400);
    }
    /** Order is significant: remove resolved children before eligible parents. */
    public static function maintenancePlan(int $now, array $days): array
    {
        $stream = self::dailyCutoff($now, (int) ($days['stream'] ?? 14));
        $runs = self::dailyCutoff($now, (int) ($days['runs'] ?? 60));
        $audit = self::dailyCutoff($now, (int) ($days['audit'] ?? 365));
        return [
            'delivered_sources'=>$stream, 'old_receipts'=>$stream, 'wialon_events'=>$stream,
            'old_queue'=>$stream, 'webhook_events'=>$stream, 'automation_run_items'=>$runs,
            'automation_runs'=>$runs, 'job_runs'=>$runs, 'audit_log'=>$audit,
        ];
    }
}
