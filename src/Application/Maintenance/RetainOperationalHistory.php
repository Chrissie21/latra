<?php
namespace Latra\Application\Maintenance;

use Closure;
use Latra\Domain\Maintenance\RetentionPolicy;

final class RetainOperationalHistory
{
    private Closure $now;
    public function __construct(private RetentionRepository $repository, callable $now)
    {
        $this->now = Closure::fromCallable($now);
    }
    public function purgeDelivered(int $hours): int
    {
        $cutoff = RetentionPolicy::hourlyCutoff(($this->now)(), $hours);
        return $this->locked(function() use ($cutoff): int {
            $removed = 0;
            foreach (['delivered_receipts','delivered_sources','delivered_queue'] as $category) {
                $removed += $this->repository->deleteEligible($category, $cutoff, 50);
            }
            return $removed;
        });
    }
    public function archiveMessages(int $days): int
    {
        $cutoff = RetentionPolicy::dailyCutoff(($this->now)(), $days);
        return $this->locked(fn(): int => $this->repository->deleteEligible('archive_sources', $cutoff));
    }
    public function maintain(array $days): int
    {
        $now = ($this->now)();
        $plan = RetentionPolicy::maintenancePlan($now, $days);
        $rateCutoff = $now - (int) ($days['stream'] ?? 14) * 86400;
        return $this->locked(function() use ($plan, $rateCutoff): int {
            $removed = 0;
            foreach ($plan as $category => $cutoff) $removed += $this->repository->deleteEligible($category, $cutoff);
            return $removed + $this->repository->deleteExpiredRateLimits($rateCutoff);
        });
    }
    private function locked(callable $work): int
    {
        if (!$this->repository->acquire()) return 0;
        try { return $work(); }
        finally { $this->repository->release(); }
    }
}
