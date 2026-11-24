<?php
namespace Latra\Application\Scheduling;

use Closure;
use Latra\Domain\Scheduling\SchedulePolicy;
use Throwable;

/** Coordinates due work; storage, clock, locks and job effects are supplied at the boundary. */
final class RunScheduledWork
{
    private Closure $enabled;
    private Closure $executeJob;
    private Closure $executeBucket;
    private Closure $log;
    private Closure $notify;
    private Closure $output;

    public function __construct(
        private ScheduleRepository $repository,
        private ScheduleLocks $locks,
        private ScheduleClock $clock,
        callable $enabled, callable $executeJob, callable $executeBucket,
        callable $log, callable $notify, callable $output,
    ) {
        $this->enabled = Closure::fromCallable($enabled);
        $this->executeJob = Closure::fromCallable($executeJob);
        $this->executeBucket = Closure::fromCallable($executeBucket);
        $this->log = Closure::fromCallable($log);
        $this->notify = Closure::fromCallable($notify);
        $this->output = Closure::fromCallable($output);
    }

    public function jobs(): void
    {
        if (!$this->locks->acquire('latra-scheduler')) {
            ($this->output)('[' . $this->stamp() . "] Scheduler already running.\n");
            return;
        }
        try {
            if (!(($this->enabled)())) {
                ($this->output)('[' . $this->stamp() . "] Scheduler disabled.\n");
                return;
            }
            foreach ($this->repository->dueJobs() as $job) $this->job($job);
        } finally {
            $this->release('latra-scheduler');
        }
    }

    private function job(array $job): void
    {
        $key = $job['job_key'];
        $lock = 'latra-job-' . $key;
        if (!$this->locks->acquire($lock)) return;
        try {
            // Setup belongs inside lock cleanup too: an insert failure must not retain ownership.
            $started = $this->clock->now();
            $runId = $this->repository->startJob(['job_key'=>$key, 'status'=>'running', 'started_at'=>$this->stamp()]);
            try {
                $items = ($this->executeJob)($key);
                $this->repository->updateJobRun($runId, [
                    'status'=>'ok', 'finished_at'=>$this->stamp(),
                    'duration_ms'=>(int) round(($this->clock->now() - $started) * 1000), 'items_processed'=>$items,
                ]);
                $this->repository->updateJob((int) $job['id'], [
                    'last_run_at'=>$this->stamp(),
                    'next_run_at'=>$this->stamp(SchedulePolicy::jobDelay((int) $job['interval_seconds'])),
                    'last_status'=>'ok', 'last_error'=>null, 'run_count'=>(int) $job['run_count'] + 1,
                ]);
            } catch (Throwable $error) {
                ($this->log)('job.failed', ['job'=>$key, 'error'=>$error->getMessage()], false);
                try {
                    $this->repository->updateJobRun($runId, [
                        'status'=>'failed', 'finished_at'=>$this->stamp(),
                        'duration_ms'=>(int) round(($this->clock->now() - $started) * 1000), 'error'=>$error->getMessage(),
                    ]);
                    $this->repository->updateJob((int) $job['id'], [
                        'last_run_at'=>$this->stamp(), 'next_run_at'=>$this->stamp(SchedulePolicy::failedJobDelay()),
                        'last_status'=>'failed', 'last_error'=>$error->getMessage(),
                    ]);
                    ($this->notify)('job_failed', ['job'=>$key]);
                } catch (Throwable $bookkeepingError) {
                    // The job already failed; losing its bookkeeping must still be visible.
                    ($this->log)('job.bookkeeping_failed', ['job'=>$key, 'error'=>$bookkeepingError->getMessage()], true);
                }
            }
        } finally {
            $this->release($lock);
        }
    }

    public function buckets(): void
    {
        if (!$this->locks->acquire('latra-scheduler-buckets')) {
            ($this->output)('[' . $this->stamp() . "] Bucket scheduler already running.\n");
            return;
        }
        try {
            if (!(($this->enabled)())) return;
            $this->repository->orphanTimedOutBuckets();
            foreach ($this->repository->dueBuckets() as $bucket) $this->bucket($bucket);
        } catch (Throwable $error) {
            ($this->log)('bucket.tick', ['error'=>$error->getMessage()], false);
        } finally {
            $this->release('latra-scheduler-buckets');
        }
    }

    public function bucket(array $bucket): void
    {
        $id = (int) $bucket['id'];
        $lock = 'latra-bucket-' . $id;
        if (!$this->locks->acquire($lock)) return;
        try {
            // Reserve the same fixed cadence before executing, including manual triggers.
            $this->repository->updateBucket($id, [
                'next_run_at'=>$this->stamp(SchedulePolicy::bucketDelay()),
                'run_count'=>(int) $bucket['run_count'] + 1, 'run_on_demand'=>0,
            ]);
            $started = $this->clock->now();
            $runId = null;
            try {
                $runId = $this->repository->startBucket([
                    'bucket_id'=>$id, 'status'=>'running',
                    'triggered_by'=>(int) ($bucket['run_on_demand'] ?? 0) === 1 ? 'manual' : 'schedule',
                    'started_at'=>$this->stamp(),
                ]);
                $stats = ($this->executeBucket)($bucket);
                $result = SchedulePolicy::bucketResult($stats);
                $failed = $result['failed']; $skipped = $result['skipped'];
                $this->repository->updateBucketRun($runId, [
                    'status'=>$failed ? 'completed_failed' : 'completed', 'finished_at'=>$this->stamp(),
                    'duration_seconds'=>(int) round($this->clock->now() - $started),
                    'total_count'=>$result['total'], 'success_count'=>$result['success'], 'failed_count'=>$failed,
                    'manual_count'=>0, 'skipped_count'=>$skipped, 'error_message'=>$result['reason'],
                ]);
                $this->repository->updateBucket($id, [
                    'last_run_at'=>$this->stamp(), 'fail_count'=>(int) $bucket['fail_count'] + ($failed > 0 ? $failed : 0),
                    'consecutive_failures'=>$failed > 0 ? (int) $bucket['consecutive_failures'] + 1 : 0,
                    'last_error'=>$failed ? "$failed failed, $skipped skipped" : $result['reason'],
                ]);
                if ($skipped > 0) ($this->log)('bucket.partial', ['bucket'=>$id, 'skipped'=>$skipped, 'denied'=>(int) ($stats['denied'] ?? 0)], true);
            } catch (Throwable $error) {
                ($this->log)('bucket.error', ['bucket'=>$id, 'error'=>$error->getMessage()], false);
                if ($runId) $this->repository->updateBucketRun($runId, [
                    'status'=>'failed', 'finished_at'=>$this->stamp(), 'error_message'=>$error->getMessage(),
                ]);
                $this->repository->updateBucket($id, [
                    'last_run_at'=>$this->stamp(), 'fail_count'=>(int) $bucket['fail_count'] + 1,
                    'consecutive_failures'=>(int) $bucket['consecutive_failures'] + 1, 'last_error'=>$error->getMessage(),
                ]);
            }
        } finally {
            $this->release($lock);
        }
    }

    private function stamp(int $delay = 0): string { return $this->clock->format($this->clock->now() + $delay); }
    private function release(string $lock): void
    {
        try { $this->locks->release($lock); } catch (Throwable $error) {
            ($this->log)('scheduler.lock_release_failed', ['lock'=>$lock, 'error'=>$error->getMessage()], true);
        }
    }
}
