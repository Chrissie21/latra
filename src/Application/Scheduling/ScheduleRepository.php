<?php
namespace Latra\Application\Scheduling;

interface ScheduleRepository
{
    public function dueJobs(): array;
    public function dueBuckets(): array;
    public function orphanTimedOutBuckets(): void;
    public function startJob(array $data): int;
    public function updateJobRun(int $id, array $data): void;
    public function updateJob(int $id, array $data): void;
    public function startBucket(array $data): int;
    public function updateBucketRun(int $id, array $data): void;
    public function updateBucket(int $id, array $data): void;
}
