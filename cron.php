<?php
/**
 * Scheduler tick. Prefer the long-running worker:
 *   php bin/worker.php
 * Fallback (one crontab line):
 *   * * * * * /usr/bin/php /path/to/cron.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/WialonAPI.php';
require_once __DIR__ . '/includes/LatraMapper.php';
require_once __DIR__ . '/includes/Automation.php';
require_once __DIR__ . '/includes/GpsValidator.php';
require_once __DIR__ . '/includes/EventDetector.php';
require_once __DIR__ . '/includes/GpsMonitor.php';
require_once __DIR__ . '/includes/DeviceMonitor.php';
require_once __DIR__ . '/includes/EmergencyHandler.php';

// If the DB just came back (or went down), wait briefly instead of dying.
for ($attempt = 1; $attempt <= 3; $attempt++) {
    if (Database::available()) {
        break;
    }
    if ($attempt < 3) {
        sleep(2);
    }
}
if (!Database::available()) {
    echo '[' . date(DATE_FORMAT) . "] Database unavailable; skipping cycle.\n";
} else {
    Schema::ensure();
}

// Lock so cron jobs do not overlap.
define('CRON_STALE_SECONDS', 120);
$lockFile = LOGS_DIR . '/cron.pid';
$isLeader = false;
if (file_exists($lockFile)) {
    $age = time() - (int) file_get_contents($lockFile);
    $pid = (int) (file_exists($lockFile . '.pid') ? file_get_contents($lockFile . '.pid') : 0);
    $alive = false;
    if ($pid > 0 && function_exists('posix_kill')) {
        $alive = @posix_kill($pid, 0);
    }
    if ($age > CRON_STALE_SECONDS || !$alive) {
        $isLeader = true;
    }
} else {
    $isLeader = true;
}
if ($isLeader) {
    file_put_contents($lockFile, time());
    file_put_contents($lockFile . '.pid', getmypid());
    try {
        Scheduler::tickBuckets();
        Scheduler::tickJobs();
        EventEngine::runCycle();
    } catch (Throwable $e) {
        echo '[' . date(DATE_FORMAT) . '] Cycle error: ' . $e->getMessage() . "\n";
        error_log('cron.cycle_error: ' . $e->getMessage());
    } finally {
        @unlink($lockFile . '.pid');
        @unlink($lockFile);
    }
} else {
    echo "[" . date(DATE_FORMAT) . "] Another cron.php is active; skipping.\n";
}
echo "[" . date(DATE_FORMAT) . "] Scheduler tick complete.\n";
