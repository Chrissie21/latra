<?php
/**
 * Job-key dispatch is an application decision: a scheduled key selects exactly
 * one registered job, and an unknown key is a no-op rather than a crash.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../includes/autoload.php';

use Latra\Application\Scheduling\ScheduledJobDispatch;

function dispatchAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runs = [];
$jobs = new ScheduledJobDispatch([
    'fleet_sync' => static function () use (&$runs): int {
        $runs[] = 'fleet_sync';
        return 7;
    },
    'retention' => static function () use (&$runs): int {
        $runs[] = 'retention';
        return 3;
    },
]);

dispatchAssert($jobs->run('fleet_sync') === 7, 'Known job did not return its result');
dispatchAssert($runs === ['fleet_sync'], 'Known job ran more than once or the wrong job ran');
dispatchAssert($jobs->run('retention') === 3 && $runs === ['fleet_sync', 'retention'],
    'Dispatch did not select the second registered job');
dispatchAssert($jobs->run('unknown') === 0 && $runs === ['fleet_sync', 'retention'],
    'Unknown job key must be a no-op');
dispatchAssert($jobs->keys() === ['fleet_sync', 'retention'], 'Registered keys changed');
dispatchAssert((new ScheduledJobDispatch([]))->run('anything') === 0, 'Empty registry must be a no-op');

echo "PASS scheduled job dispatch selects one registered job and ignores unknown keys\n";
