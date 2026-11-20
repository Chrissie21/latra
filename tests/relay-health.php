<?php
/** Active queue health must not silently report retired message history as relay state. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
class Database {
    public static PDO $pdo;
    public static function fetch(string $sql): ?array {
        return self::$pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
$driver = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
Database::$pdo = new $driver('sqlite::memory:');
Database::$pdo->exec("CREATE TABLE automation_runs(started_at TEXT);
    CREATE TABLE automation_buckets(is_enabled INTEGER);
    CREATE TABLE message_log(latra_status TEXT);
    CREATE TABLE relay_pending(status TEXT);
    INSERT INTO automation_runs VALUES ('2026-11-19 12:00:00');
    INSERT INTO automation_buckets VALUES (1),(0);
    INSERT INTO message_log VALUES ('pending'),('pending'),('pending'),('manual'),('dead');
    INSERT INTO relay_pending VALUES ('pending'),('pending'),('review');");
$repository = new Latra\Infrastructure\Persistence\DatabaseOperatorActionRepository();
$health = $repository->schedulerHealth();
if ($health !== ['last_run_at'=>'2026-11-19 12:00:00','enabled_buckets'=>1,'backlog'=>2,'dead_letter'=>1]) {
    throw new RuntimeException('Scheduler health does not describe the active relay queue: ' . json_encode($health));
}
Database::$pdo->exec('DELETE FROM relay_pending');
$empty = $repository->schedulerHealth();
if ($empty['backlog'] !== 0 || $empty['dead_letter'] !== 0) {
    throw new RuntimeException('Historical messages became an active backlog after relay drain');
}
echo "PASS scheduler health counts pending/review relay work and ignores retained history\n";
