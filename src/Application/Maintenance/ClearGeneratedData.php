<?php
namespace Latra\Application\Maintenance;

use Closure;
use Latra\Domain\Maintenance\DataCleanupPolicy;

final class ClearGeneratedData
{
    private Closure $now;
    public function __construct(private DataCleanupRepository $repository,callable $now) { $this->now=Closure::fromCallable($now); }
    public function clear(string $category,array $filters): array
    {
        return $this->repository->clearCategory($category,DataCleanupPolicy::criteria($category,$filters,($this->now)()));
    }
    public function resetAll(): array
    {
        $total=0;
        foreach (DataCleanupPolicy::RESET_ORDER as $table) $total+=$this->repository->resetGenerated($table);
        $this->repository->resetBucketCounters();
        return ['deleted'=>$total];
    }
}
