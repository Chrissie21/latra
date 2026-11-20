<?php
/** Crash recovery must close abandoned run evidence while preserving a live job owner. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
$driver = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
$pdo = new $driver('sqlite::memory:');
$function = method_exists($pdo, 'createFunction') ? $pdo->createFunction(...) : $pdo->sqliteCreateFunction(...);
$held = ['latra-job-live'=>true];
$function('NOW', static fn(): string => '2026-11-19 12:00:00');
$function('CONCAT', static fn(string $a, string $b): string => $a . $b);
$function('IS_FREE_LOCK', static function(string $name) use (&$held): int { return isset($held[$name]) ? 0 : 1; });
$pdo->exec("CREATE TABLE jobs(id INTEGER, locked_until TEXT);
    CREATE TABLE job_runs(id INTEGER,job_key TEXT,status TEXT,finished_at TEXT,error TEXT);
    INSERT INTO jobs VALUES (1,'2026-11-19 11:00:00'),(2,'2026-11-19 13:00:00');
    INSERT INTO job_runs VALUES (1,'dead','running',NULL,NULL),(2,'live','running',NULL,NULL),
      (3,'done','ok','2026-11-19 10:00:00',NULL);");
$repo = new Latra\Infrastructure\Persistence\PdoMaintenanceRepository($pdo);
if ($repo->reconcileJobLocks() !== 2) throw new RuntimeException('Abandoned run was not reconciled with the expired legacy lock');
$rows = $pdo->query('SELECT * FROM job_runs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if ($rows[0]['status'] !== 'orphaned' || $rows[0]['finished_at'] === null || !$rows[0]['error']
    || $rows[1]['status'] !== 'running' || $rows[2]['status'] !== 'ok') {
    throw new RuntimeException('Crash recovery hid an active owner or lost abandoned-run evidence');
}
unset($held['latra-job-live']);
if ($repo->reconcileJobLocks() !== 1 || $repo->reconcileJobLocks() !== 0) {
    throw new RuntimeException('Recovery failed after lock release or was not idempotent');
}
if ($pdo->query('SELECT locked_until FROM jobs WHERE id=2')->fetchColumn() !== '2026-11-19 13:00:00') {
    throw new RuntimeException('Future legacy job lock was cleared');
}
echo "PASS orphaned scheduler runs close only after ownership is gone; repeat recovery is harmless\n";
