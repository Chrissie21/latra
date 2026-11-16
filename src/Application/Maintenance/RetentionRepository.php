<?php
namespace Latra\Application\Maintenance;

interface RetentionRepository
{
    public function acquire(): bool;
    public function release(): void;
    public function deleteEligible(string $category, string $cutoff, ?int $maxBatches = null): int;
    public function deleteExpiredRateLimits(int $cutoff): int;
}
