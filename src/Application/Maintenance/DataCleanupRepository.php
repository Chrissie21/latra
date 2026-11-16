<?php
namespace Latra\Application\Maintenance;

interface DataCleanupRepository
{
    public function clearCategory(string $category,array $criteria): array;
    public function resetGenerated(string $table): int;
    public function resetBucketCounters(): void;
}
