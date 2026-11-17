<?php
/** One scheduler/worker instance at a time; a stale or dead owner is taken over. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
use Latra\Infrastructure\Scheduling\SingleInstanceLock;
function lockAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$dir = sys_get_temp_dir() . '/latra-lock-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$lockFile = $dir . '/cron.pid';
$pidFile = $lockFile . '.pid';
$hasPosix = function_exists('posix_kill');
try {
    $lock = new SingleInstanceLock($lockFile, 120);
    lockAssert($lock->acquire(), 'First writer was not granted the lock');
    lockAssert(is_file($lockFile) && (int) file_get_contents($lockFile) > 0, 'Lock timestamp was not written');
    lockAssert((int) file_get_contents($pidFile) === getmypid(), 'Lock owner pid was not recorded');

    if ($hasPosix) {
        lockAssert(!(new SingleInstanceLock($lockFile, 120))->acquire(), 'Live owner lost the lock');
    }

    // A stale timestamp is taken over even while the recorded pid still answers.
    file_put_contents($lockFile, time() - 121);
    lockAssert((new SingleInstanceLock($lockFile, 120))->acquire(), 'Stale lock was not taken over');

    // A dead owner is taken over even when the timestamp is fresh.
    if ($hasPosix) {
        file_put_contents($lockFile, time());
        file_put_contents($pidFile, 2147483646);
        lockAssert((new SingleInstanceLock($lockFile, 120))->acquire(), 'Dead owner blocked the lock');
    }

    $lock->release();
    lockAssert(!file_exists($lockFile) && !file_exists($pidFile), 'Release left lock files behind');
    lockAssert((new SingleInstanceLock($lockFile, 120))->acquire(), 'Released lock could not be reacquired');
} finally {
    @unlink($pidFile);
    @unlink($lockFile);
    @rmdir($dir);
}
echo "PASS single-instance lock records its owner, rejects a live peer and takes over a stale/dead one\n";
