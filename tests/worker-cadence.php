<?php
/** Worker cadence stays an inspectable rule instead of a loop-local constant. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
use Latra\Application\Scheduling\WorkerCadence;
function cadenceAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$cadence = new WorkerCadence();
cadenceAssert($cadence->sleepSeconds(0) === 5, 'Idle worker cadence changed');
cadenceAssert($cadence->sleepSeconds(1) === 2 && $cadence->sleepSeconds(9) === 2,
    'High-frequency emergency cadence changed');
cadenceAssert($cadence->jobIntervalSeconds() === 15 && $cadence->jobIntervalNanos() === 15_000_000_000,
    'Job tick interval changed');
echo "PASS worker keeps the 15s job tick and the 5s/2s emergency-driven poll cadence\n";
