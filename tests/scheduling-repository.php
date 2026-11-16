<?php
/** Synthetic SQLite verifies persistence shapes and the real compatibility entry points. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
const DATE_FORMAT = 'Y-m-d H:i:s';
class Database {
    public static PDO $pdo;
    public static function getInstance(): PDO { return self::$pdo; }
}
class Schema { public static function config(string $key, string $default): string { return $default; } }
class Logger {
    public static function error(string $event, array $context): void { throw new RuntimeException($event . json_encode($context)); }
    public static function warn(string $event, array $context): void {}
}
class Webhooks { public static function emit(string $event, array $context): void {} }
class RelayRuntime {
    public static array $calls = [];
    public static function flush(int $limit, bool $emergency, int $seconds): int {
        self::$calls[] = [$limit,$emergency,$seconds]; return 3;
    }
}
class BucketRunner { public static function run(array $bucket): array { return ['pushed'=>2,'skipped'=>1]; } }
function scheduleDbAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdoClass = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
$pdo = new $pdoClass('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
Database::$pdo = $pdo;
$held = [];
$function = method_exists($pdo, 'createFunction') ? $pdo->createFunction(...) : $pdo->sqliteCreateFunction(...);
$function('GET_LOCK', static function(string $name, int $wait) use (&$held): int {
    if (isset($held[$name])) return 0;
    $held[$name] = true; return 1;
}, 2);
$function('RELEASE_LOCK', static function(string $name) use (&$held): int { unset($held[$name]); return 1; }, 1);
$function('NOW', static fn(): string => date(DATE_FORMAT), 0);
$function('FIELD', static function(...$values): int {
    $key = array_shift($values); $index = array_search($key, $values, true);
    return $index === false ? 0 : $index + 1;
});
$pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_key TEXT, is_enabled INTEGER, next_run_at TEXT, interval_seconds INTEGER, run_count INTEGER, last_run_at TEXT, last_status TEXT, last_error TEXT)');
$pdo->exec('CREATE TABLE job_runs (id INTEGER PRIMARY KEY, job_key TEXT, status TEXT, started_at TEXT, finished_at TEXT, duration_ms INTEGER, items_processed INTEGER, error TEXT)');
$pdo->exec('CREATE TABLE automation_buckets (id INTEGER PRIMARY KEY, is_enabled INTEGER, next_run_at TEXT, run_count INTEGER, run_on_demand INTEGER, last_run_at TEXT, fail_count INTEGER, consecutive_failures INTEGER, last_error TEXT)');
$pdo->exec('CREATE TABLE automation_runs (id INTEGER PRIMARY KEY, bucket_id INTEGER, status TEXT, triggered_by TEXT, started_at TEXT, finished_at TEXT, duration_seconds INTEGER, total_count INTEGER, success_count INTEGER, failed_count INTEGER, manual_count INTEGER, skipped_count INTEGER, error_message TEXT)');
$pdo->exec("INSERT INTO jobs VALUES (1,'flush_outbox',1,NULL,180,0,NULL,NULL,NULL), (2,'fleet_sync',0,NULL,180,0,NULL,NULL,NULL), (3,'poll_messages',1,'2999-01-01',180,0,NULL,NULL,NULL)");
$repo = new Latra\Infrastructure\Persistence\PdoScheduleRepository($pdo);
scheduleDbAssert(count($repo->dueJobs()) === 1, 'Repository ignored job enabled/due filter');
Scheduler::tickJobs();
$row = $pdo->query('SELECT * FROM jobs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$run = $pdo->query('SELECT * FROM job_runs')->fetch(PDO::FETCH_ASSOC);
scheduleDbAssert($row['last_status'] === 'ok' && (int)$row['run_count'] === 1 && $run['status'] === 'ok' && (int)$run['items_processed'] === 3, 'Compatibility job entry point lost persistence');
scheduleDbAssert(RelayRuntime::$calls === [[80,false,120]] && $held === [], 'Job dispatch budget/session locks changed');
$pdo->exec('INSERT INTO automation_buckets (id,is_enabled,run_count,run_on_demand,fail_count,consecutive_failures) VALUES (8,1,2,1,4,3)');
$bucket = $repo->dueBuckets()[0];
Scheduler::runBucket($bucket);
$row = $pdo->query('SELECT * FROM automation_buckets WHERE id = 8')->fetch(PDO::FETCH_ASSOC);
$run = $pdo->query('SELECT * FROM automation_runs')->fetch(PDO::FETCH_ASSOC);
scheduleDbAssert($run['status'] === 'completed' && $run['triggered_by'] === 'manual' && (int)$run['total_count'] === 3 && (int)$run['success_count'] === 2, 'Bucket persisted result changed');
scheduleDbAssert((int)$row['consecutive_failures'] === 0 && (int)$row['run_on_demand'] === 0 && (int)$row['run_count'] === 3 && $held === [], 'Bucket counters or lock cleanup changed');
echo "PASS scheduling repository due filters, captured-session adapters and real entry-point delegation\n";
