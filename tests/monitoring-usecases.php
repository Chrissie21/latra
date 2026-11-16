<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\DeviceStatus\RefreshGpsHealth;
use Latra\Application\DeviceStatus\MarkOfflineDevices;
use Latra\Application\DeviceStatus\DeviceStatusClock;
use Latra\Application\DeviceStatus\DeviceStatusEffects;
use Latra\Infrastructure\Persistence\PdoHealthRepository;
function healthAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE vehicles (id INTEGER PRIMARY KEY, is_active INTEGER, vehicle_reg_no TEXT, imei TEXT)');
$pdo->exec('CREATE TABLE message_log (id INTEGER PRIMARY KEY, vehicle_id INTEGER, wialon_msg_time INTEGER)');
$pdo->exec('CREATE TABLE gps_health (vehicle_id INTEGER PRIMARY KEY, last_gps_time INTEGER, last_gps_at TEXT, last_comm_time INTEGER, status TEXT, minutes_since INTEGER)');
$pdo->exec('CREATE TABLE device_status (vehicle_id INTEGER PRIMARY KEY, is_online INTEGER, last_comm_time INTEGER, last_comm_at TEXT, status_changed_at TEXT, internal_battery_low INTEGER, external_power_disconnected INTEGER, tampered INTEGER)');
$now=100000;
for ($id=1;$id<=6;$id++) $pdo->prepare('INSERT INTO vehicles VALUES (?,?,?,?)')->execute([$id,$id===6?0:1,'SYNTHETIC-'.$id,'synthetic']);
foreach ([1=>180,2=>181,3=>900,4=>901,6=>0] as $id=>$age) $pdo->prepare('INSERT INTO message_log (vehicle_id,wialon_msg_time) VALUES (?,?)')->execute([$id,$now-$age]);
$clock=new class implements DeviceStatusClock {
    public function now(): string { return 'synthetic now'; }
    public function at(int $timestamp): string { return gmdate('Y-m-d H:i:s',$timestamp); }
};
$repo=new PdoHealthRepository($pdo);
$refresh=new RefreshGpsHealth($repo,$clock,static fn(): int => $now);
healthAssert($refresh->run() === ['ok'=>1,'stale'=>2,'offline'=>1,'unknown'=>1], 'GPS threshold boundaries or active selection changed');
$unknown=$pdo->query('SELECT * FROM gps_health WHERE vehicle_id=5')->fetch(PDO::FETCH_ASSOC);
healthAssert((int)$unknown['last_gps_time']===0 && $unknown['minutes_since']===null && $unknown['status']==='unknown', 'Missing GPS source representation changed');
healthAssert($repo->freshCount()===1 && $repo->gpsSummary()===['ok'=>1,'stale'=>2,'offline'=>1,'unknown'=>1]
    && array_column($repo->staleVehicles(),'minutes_since')===[15,15,3], 'GPS summaries or stale ordering changed');
$refresh->run(); healthAssert((int)$pdo->query('SELECT COUNT(*) FROM gps_health')->fetchColumn()===5, 'GPS update inserted duplicates');
$pdo->exec("INSERT INTO device_status VALUES (1,1,99099,'old',NULL,0,0,0),(2,1,99100,'old',NULL,1,0,0),(3,1,NULL,'old',NULL,0,1,0),(4,0,1,'old',NULL,0,0,1)");
$effects=new class($pdo) implements DeviceStatusEffects {
    public array $events=[];
    public function __construct(private PDO $pdo) {}
    public function recordTransition(int $vehicleId,string $type,string $detail): void {
        healthAssert((int)$this->pdo->query('SELECT is_online FROM device_status WHERE vehicle_id='.$vehicleId)->fetchColumn()===0, 'Effect occurred before offline write');
        $this->events[]=[$vehicleId,$type,$detail];
    }
    public function statusChanged(int $vehicleId,array $status): void { throw new RuntimeException('Unexpected status webhook'); }
};
$mark=new MarkOfflineDevices($repo,$effects,$clock,static fn(): int => $now);
$pdo->beginTransaction();
healthAssert($mark->run()===1 && $effects->events===[[1,'DEVICE_OFFLINE','Device lost connection (heartbeat expired)']], 'Offline transition eligibility/evidence changed');
$pdo->rollBack();
healthAssert((int)$pdo->query('SELECT is_online FROM device_status WHERE vehicle_id=1')->fetchColumn()===1, 'Monitoring adapter escaped caller transaction');
healthAssert($mark->run()===1 && $mark->run()===0, 'Offline transition repeated without a new heartbeat');
healthAssert($repo->deviceSummary()===['online'=>2,'battery_low'=>1,'power_disconnected'=>1,'tampered'=>1,'total'=>4]
    && count($repo->deviceStatusList(2))===2, 'Device summary or bounded status query changed');
echo "PASS monitoring policies, missing evidence, threshold edges, persistence, transaction ownership and offline transitions\n";
