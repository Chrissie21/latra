<?php
/** Run the real loop with a failed emergency-count read, then prove it cycles again. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = sys_get_temp_dir() . '/latra-worker-outage-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/bin', 0700);
copy(__DIR__ . '/../bin/worker.php', $root . '/bin/worker.php');
file_put_contents($root . '/config.php', <<<'PHP'
<?php
namespace Latra\Application\Scheduling {
    class WorkerCadence {
        public function jobIntervalNanos(): int { return PHP_INT_MAX - hrtime(true); }
        public function sleepSeconds(int $active): int {
            if ($active !== 0) throw new \LogicException('Unexpected fallback cadence');
            $GLOBALS['fallback_used'] = true;
            return 0;
        }
    }
}
namespace {
    const DATE_FORMAT = 'Y-m-d H:i:s';
    class Schema { public static function ensure(): void {} }
    class Scheduler {
        public static function tickBuckets(): void {
            static $cycles = 0;
            if (++$cycles === 2) {
                if (empty($GLOBALS['fallback_used']) || empty($GLOBALS['outage_logged'])) exit(2);
                echo "PASS worker survived failed emergency-count read\n";
                exit(0);
            }
        }
    }
    class RelayRuntime { public static function cycle(): void {} }
    class EmergencyHandler {
        public static function activeCount(): int { throw new \RuntimeException('Synthetic database unavailable'); }
    }
    class Logger {
        public static function error(string $event, array $context): void {
            if ($event === 'worker.cadence' && str_contains($context['error'] ?? '', 'Synthetic database unavailable')) {
                $GLOBALS['outage_logged'] = true;
            }
        }
    }
}
PHP);
try {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/worker.php') . ' 2>&1', $output, $status);
    if ($status !== 0 || !str_contains(implode("\n", $output), 'PASS worker survived')) {
        throw new RuntimeException('Worker died during an emergency-count outage: ' . implode("\n", $output));
    }
} finally {
    unlink($root . '/bin/worker.php');
    rmdir($root . '/bin');
    unlink($root . '/config.php');
    rmdir($root);
}
echo "PASS real worker loop logs failed cadence reads and continues with the idle interval\n";
