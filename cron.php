<?php
/**
 * Scheduler tick. Prefer the long-running worker:
 *   php bin/worker.php
 * Fallback (one crontab line):
 *   * * * * * /usr/bin/php /path/to/cron.php
 */
require_once __DIR__ . '/config.php';

// If the DB just came back (or went down), wait briefly instead of dying.
if (!Database::await(3, 2)) {
    echo '[' . date(DATE_FORMAT) . "] Database unavailable; skipping cycle.\n";
    exit;
}
Schema::ensure();

// Lock so cron jobs do not overlap.
$lock = new \Latra\Infrastructure\Scheduling\SingleInstanceLock(LOGS_DIR . '/cron.pid', 120);
if ($lock->acquire()) {
    try {
        Scheduler::tickBuckets();
        Scheduler::tickJobs();
        RelayRuntime::cycle();
    } catch (Throwable $e) {
        echo '[' . date(DATE_FORMAT) . '] Cycle error: ' . $e->getMessage() . "\n";
        error_log('cron.cycle_error: ' . $e->getMessage());
    } finally {
        $lock->release();
    }
} else {
    echo "[" . date(DATE_FORMAT) . "] Another cron.php is active; skipping.\n";
}
echo "[" . date(DATE_FORMAT) . "] Scheduler tick complete.\n";
