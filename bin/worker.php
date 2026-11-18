<?php

require_once __DIR__ . '/../config.php';

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () { fwrite(STDERR, "worker stop\n"); exit(0); });
    pcntl_signal(SIGINT, static function () { exit(0); });
}

Schema::ensure();
fwrite(STDOUT, "[" . date(DATE_FORMAT) . "] worker started pid=" . getmypid() . "\n");

$cadence = new \Latra\Application\Scheduling\WorkerCadence();
$nextJobsAt = hrtime(true) + $cadence->jobIntervalNanos();
while (true) {
    try {
        Scheduler::tickBuckets();
    } catch (Throwable $e) {
        fwrite(STDERR, "[" . date(DATE_FORMAT) . "] bucket tick error: " . $e->getMessage() . "\n");
        Logger::error('worker.bucket_tick', ['error' => $e->getMessage()]);
    }
    try {
        RelayRuntime::cycle();
    } catch (Throwable $e) {
        fwrite(STDERR, "[" . date(DATE_FORMAT) . "] event engine error: " . $e->getMessage() . "\n");
        Logger::error('worker.event_engine', ['error' => $e->getMessage()]);
    }
    sleep($cadence->sleepSeconds(EmergencyHandler::activeCount()));

    if (hrtime(true) >= $nextJobsAt) {
        $nextJobsAt = hrtime(true) + $cadence->jobIntervalNanos();
        try {
            Scheduler::tickJobs();
        } catch (Throwable $e) {
            fwrite(STDERR, "[" . date(DATE_FORMAT) . "] job tick error: " . $e->getMessage() . "\n");
            Logger::error('worker.job_tick', ['error' => $e->getMessage()]);
        }
    }
}
