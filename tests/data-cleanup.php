<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\Maintenance\ClearGeneratedData;
use Latra\Infrastructure\Persistence\PdoDataCleanupRepository;
use Latra\Domain\Maintenance\DataCleanupPolicy;
function cleanupAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE vehicles (id INTEGER PRIMARY KEY,vehicle_reg_no TEXT,imei TEXT,is_active INTEGER)');
$pdo->exec('CREATE TABLE push_log (id INTEGER PRIMARY KEY,vehicle_id INTEGER,response_status TEXT,created_at TEXT)');
$pdo->exec('CREATE TABLE emergency_events (id INTEGER PRIMARY KEY,vehicle_id INTEGER,event_type TEXT,status TEXT,started_at TEXT)');
$pdo->exec('CREATE TABLE emergency_queue (id INTEGER PRIMARY KEY,vehicle_id INTEGER,event_id TEXT,emergency_event_id INTEGER,created_at TEXT,FOREIGN KEY(emergency_event_id) REFERENCES emergency_events(id))');
$pdo->exec("INSERT INTO vehicles VALUES (1,'SYNTHETIC','synthetic',0),(2,'OTHER','other',1)");
$pdo->exec("INSERT INTO push_log VALUES (1,1,'1','2026-01-01 00:00:00'),(2,1,'0','2026-01-01 00:00:00'),(3,2,'1','2026-01-01 00:00:00')");
$work=new ClearGeneratedData(new PdoDataCleanupRepository($pdo),static fn(): int => 2000000000);
cleanupAssert($work->clear('push_log',['vehicle'=>'SYNTHETIC','response_status'=>'1'])===['deleted'=>1]
    && $pdo->query('SELECT id FROM push_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===[2,3], 'Response-status/vehicle filter deleted unrelated evidence');
cleanupAssert($work->clear('push_log',['vehicle'=>'absent'])===['deleted'=>0], 'Missing vehicle search cleared all history');
$pdo->exec("INSERT INTO emergency_events VALUES (1,1,'fixture','active','2026-01-01'),(2,2,'fixture','active','2026-01-01')");
$pdo->exec("INSERT INTO emergency_queue VALUES (1,1,'fixture',1,'2026-01-01 00:00:00'),(2,1,'fixture',1,'2026-02-01 00:00:00'),(3,2,'fixture',2,'2026-02-01 00:00:00')");
cleanupAssert($work->clear('emergency_queue',['vehicle'=>'SYNTHETIC','from'=>'2026-02-01','to'=>'2026-02-01'])===['deleted'=>1]
    && (int)$pdo->query('SELECT COUNT(*) FROM emergency_queue')->fetchColumn()===2, 'Queue date bounds ignored');
$pdo->beginTransaction();
cleanupAssert($work->clear('emergency_events',['vehicle'=>'SYNTHETIC'])===['deleted'=>1,'queue_deleted'=>1], 'Event/child cleanup accounting changed');
$pdo->rollBack();
cleanupAssert((int)$pdo->query('SELECT COUNT(*) FROM emergency_events')->fetchColumn()===2
    && (int)$pdo->query('SELECT COUNT(*) FROM emergency_queue')->fetchColumn()===2, 'Cleanup escaped caller transaction');
$pdo->exec('CREATE TABLE protected_event_reference (id INTEGER PRIMARY KEY,event_id INTEGER REFERENCES emergency_events(id))');
$pdo->exec('INSERT INTO protected_event_reference VALUES (1,1)');
try { $work->clear('emergency_events',['vehicle'=>'SYNTHETIC']);throw new LogicException('Expected parent-delete failure'); }
catch (PDOException $error) {}
cleanupAssert(!$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM emergency_queue')->fetchColumn()===2,
    'Failed parent deletion lost child rows or leaked its transaction');
try { $work->clear('push_log',['event_type'=>'unsupported']);throw new LogicException('Unsupported restriction silently ignored'); }
catch (InvalidArgumentException $error) {}
cleanupAssert($work->clear('push_log',['imei'=>'absent'])===['deleted'=>0], 'IMEI-only restriction was discarded');
foreach (['vehicles','vault',"push_log; DROP TABLE vehicles"] as $bad) {
    try { $work->clear($bad,[]);throw new LogicException('Protected/unknown category accepted'); }
    catch (InvalidArgumentException $error) {}
}
cleanupAssert(!in_array('vehicles',DataCleanupPolicy::RESET_ORDER,true) && (int)$pdo->query('SELECT is_active FROM vehicles WHERE id=1')->fetchColumn()===0, 'Cleanup changed owner vehicle eligibility');
$fake=new class implements \Latra\Application\Maintenance\DataCleanupRepository {
    public array $tables=[];public int $counters=0;
    public function clearCategory(string $category,array $criteria): array { return ['deleted'=>0]; }
    public function resetGenerated(string $table): int { $this->tables[]=$table;return 1; }
    public function resetBucketCounters(): void { $this->counters++; }
};
$reset=(new ClearGeneratedData($fake,static fn(): int => 0))->resetAll();
cleanupAssert($reset===['deleted'=>count(DataCleanupPolicy::RESET_ORDER)] && $fake->tables===DataCleanupPolicy::RESET_ORDER
    && $fake->counters===1, 'Explicit reset escaped its generated-table whitelist');
echo "PASS generated-data cleanup whitelist, vehicle/status/date filters, dependent deletion and caller rollback\n";
