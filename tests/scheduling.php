<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';

use Latra\Application\Scheduling\RunScheduledWork;
use Latra\Application\Scheduling\ScheduleRepository;
use Latra\Application\Scheduling\ScheduleLocks;
use Latra\Application\Scheduling\ScheduleClock;
use Latra\Domain\Scheduling\SchedulePolicy;

function scheduleAssert(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}
final class MemorySchedule implements ScheduleRepository {
    public array $jobs = [];
    public array $buckets = [];
    public array $jobRuns = [];
    public array $jobUpdates = [];
    public array $bucketRuns = [];
    public array $bucketUpdates = [];
    public int $cleanups = 0;
    public bool $failInsert = false;
    public bool $failReserve = false;
    public function dueJobs(): array { return $this->jobs; }
    public function dueBuckets(): array { return $this->buckets; }
    public function orphanTimedOutBuckets(): void { $this->cleanups++; }
    public function startJob(array $data): int {
        if ($this->failInsert) throw new RuntimeException('insert failed');
        $this->jobRuns[1] = $data; return 1;
    }
    public function updateJobRun(int $id, array $data): void { $this->jobRuns[$id] = array_merge($this->jobRuns[$id], $data); }
    public function updateJob(int $id, array $data): void { $this->jobUpdates[$id] = $data; }
    public function startBucket(array $data): int {
        if ($this->failInsert) throw new RuntimeException('insert failed');
        $this->bucketRuns[1] = $data; return 1;
    }
    public function updateBucketRun(int $id, array $data): void { $this->bucketRuns[$id] = array_merge($this->bucketRuns[$id], $data); }
    public function updateBucket(int $id, array $data): void {
        if ($this->failReserve && isset($data['next_run_at'])) throw new RuntimeException('reservation failed');
        $this->bucketUpdates[$id] = array_merge($this->bucketUpdates[$id] ?? [], $data);
    }
}
final class MemoryScheduleLocks implements ScheduleLocks {
    public array $held = [];
    public array $released = [];
    public array $blocked = [];
    public function acquire(string $name): bool {
        if (in_array($name, $this->blocked, true)) return false;
        $this->held[] = $name; return true;
    }
    public function release(string $name): void {
        $this->released[] = $name;
        $this->held = array_values(array_diff($this->held, [$name]));
    }
}
$clock = new class implements ScheduleClock {
    public function now(): float { return 1000.0; }
    public function format(float $time): string { return gmdate('Y-m-d H:i:s', (int)$time); }
};
$repo = new MemorySchedule(); $locks = new MemoryScheduleLocks();
$jobCalls = []; $bucketCalls = []; $logs = []; $webhooks = []; $messages = [];
$enabled = true; $failJob = false; $failBucket = false;
$work = new RunScheduledWork($repo, $locks, $clock,
    static function() use (&$enabled): bool { return $enabled; },
    static function(string $key) use (&$jobCalls, &$failJob): int {
        $jobCalls[] = $key;
        if ($failJob) throw new RuntimeException('job failed');
        return 7;
    },
    static function(array $bucket) use (&$bucketCalls, &$failBucket): array {
        $bucketCalls[] = $bucket['id'];
        if ($failBucket) throw new RuntimeException('bucket failed');
        return ['pushed'=>2, 'failed'=>1, 'skipped'=>3, 'denied'=>1];
    },
    static function(string $event, array $context, bool $warning) use (&$logs): void { $logs[] = [$event,$context,$warning]; },
    static function(string $event, array $context) use (&$webhooks): void { $webhooks[] = [$event,$context]; },
    static function(string $message) use (&$messages): void { $messages[] = $message; });
$repo->jobs = [['id'=>4,'job_key'=>'poll_messages','interval_seconds'=>0,'run_count'=>2]];
$work->jobs();
scheduleAssert($jobCalls === ['poll_messages'] && $repo->jobRuns[1]['items_processed'] === 7, 'Due job not executed/accounted');
scheduleAssert($repo->jobUpdates[4]['next_run_at'] === $clock->format(1001) && $repo->jobUpdates[4]['run_count'] === 3, 'Success interval minimum/count changed');
scheduleAssert($locks->held === [], 'Job or scheduler lock leaked');
$failJob = true; $work->jobs();
scheduleAssert($repo->jobRuns[1]['status'] === 'failed' && $repo->jobUpdates[4]['next_run_at'] === $clock->format(1030), 'Failure delay changed');
scheduleAssert(!isset($repo->jobUpdates[4]['run_count']) && $webhooks === [['job_failed',['job'=>'poll_messages']]], 'Failure count or notification changed');
$locks->blocked = ['latra-job-poll_messages']; $jobCalls = []; $work->jobs();
scheduleAssert($jobCalls === [] && $locks->held === [], 'Busy job executed or scheduler lock leaked');
$locks->blocked = []; $enabled = false; $work->jobs(); $work->buckets();
scheduleAssert($repo->cleanups === 0 && $jobCalls === [] && $locks->held === [], 'Disabled scheduler ran work');
$enabled = true; $repo->failInsert = true;
try { $work->jobs(); throw new LogicException('Expected insert failure'); }
catch (RuntimeException $error) { scheduleAssert($error->getMessage() === 'insert failed', 'Wrong insertion failure'); }
scheduleAssert($locks->held === [], 'Failed job-run creation leaked advisory lock');
$repo->failInsert = false;
$bucket = ['id'=>8,'run_count'=>5,'fail_count'=>4,'consecutive_failures'=>2,'run_on_demand'=>1,
    'interval_seconds'=>999,'cooldown_seconds'=>999];
$repo->buckets = [$bucket]; $work->buckets();
scheduleAssert($repo->cleanups === 1 && $bucketCalls === [8], 'Due buckets or orphan cleanup missing');
scheduleAssert($repo->bucketUpdates[8]['next_run_at'] === $clock->format(1180) && $repo->bucketUpdates[8]['run_on_demand'] === 0, 'Owner fixed bucket cadence changed');
scheduleAssert($repo->bucketRuns[1]['triggered_by'] === 'manual' && $repo->bucketRuns[1]['total_count'] === 7
    && $repo->bucketRuns[1]['skipped_count'] === 4 && $repo->bucketRuns[1]['status'] === 'completed_failed', 'Bucket accounting changed');
scheduleAssert($repo->bucketUpdates[8]['fail_count'] === 5 && $repo->bucketUpdates[8]['consecutive_failures'] === 3, 'Bucket failures lost');
$failBucket = true; $work->bucket($bucket);
scheduleAssert($repo->bucketRuns[1]['status'] === 'failed' && $repo->bucketUpdates[8]['last_error'] === 'bucket failed' && $locks->held === [], 'Thrown bucket error not recorded/released');
$repo->failReserve = true;
try { $work->bucket($bucket); throw new LogicException('Expected reservation failure'); }
catch (RuntimeException $error) { scheduleAssert($error->getMessage() === 'reservation failed', 'Wrong reservation failure'); }
scheduleAssert($locks->held === [], 'Failed reservation leaked bucket lock');
scheduleAssert(SchedulePolicy::jobDelay(-1) === 1 && SchedulePolicy::jobDelay(180) === 180, 'Job timing policy changed');
echo "PASS scheduler locks, enable gate, run accounting, retry/cadence and failed-setup cleanup\n";
