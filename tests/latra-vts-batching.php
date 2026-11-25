<?php
declare(strict_types=1);
// Reuse the SQL/lock fixture; its own acceptance/rollback regressions run first.
require __DIR__ . '/relay-delivery-service.php';
require __DIR__ . '/latra-idd.php';
use Latra\Application\Delivery\DeliverPendingReport;
use Latra\Infrastructure\Persistence\PdoRelayDeliveryRepository;
use Latra\Domain\Delivery\VtsBatchPolicy;

if (getenv('LATRA_RELAY_TEST_DSN')) {
    if (getenv('LATRA_RELAY_TEST_ALLOW_RESET') !== '1'
        || !preg_match('/dbname=latra_test_relay_app_[a-z0-9_]+(?:;|$)/', getenv('LATRA_RELAY_TEST_DSN'))) {
        throw new RuntimeException('Require explicit reset of disposable native fixture');
    }
    $database = new PDO(getenv('LATRA_RELAY_TEST_DSN'), getenv('LATRA_RELAY_TEST_USER') ?: 'root',
        getenv('LATRA_RELAY_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach (['relay_pending','relay_identity','relay_units'] as $table) $database->exec("DROP TABLE IF EXISTS $table");
    $database->exec('CREATE TABLE relay_units (unit VARCHAR(64) PRIMARY KEY, attempted_time BIGINT NULL, delivered_time BIGINT NULL) ENGINE=InnoDB');
    $database->exec('CREATE TABLE relay_identity (delivery_key VARCHAR(64) PRIMARY KEY, state VARCHAR(16) NOT NULL) ENGINE=InnoDB');
    $database->exec('CREATE TABLE relay_pending (id BIGINT AUTO_INCREMENT PRIMARY KEY, delivery_key VARCHAR(64) UNIQUE, unit VARCHAR(64), source_time BIGINT, payload MEDIUMTEXT, status VARCHAR(16), attempts INT, next_attempt BIGINT NULL, last_error VARCHAR(500) NULL) ENGINE=InnoDB');
}
function addVts(PDO $db, string $unit, int $n, int $items=1): void {
    $key = "$unit-$n";
    $exists = $db->prepare('SELECT unit FROM relay_units WHERE unit=?'); $exists->execute([$unit]);
    if (!$exists->fetchColumn()) $db->prepare('INSERT INTO relay_units (unit) VALUES (?)')->execute([$unit]);
    $db->prepare('INSERT INTO relay_identity (delivery_key,state) VALUES (?,?)')->execute([$key,'pending']);
    $payload=latra_idd_sample_request(1,$items);
    foreach ($payload['items'] as $i=>&$item) $item['MGS_ID']=(string)($n*32+$i);
    unset($item);
    $db->prepare('INSERT INTO relay_pending (delivery_key,unit,source_time,payload,status,attempts,next_attempt) VALUES (?,?,?,?,?,?,?)')
        ->execute([$key,$unit,$n,json_encode($payload),'pending',0,0]);
}
$repo = new PdoRelayDeliveryRepository($database, null, true);
$delivery = new DeliverPendingReport($repo, 3, static fn()=>1);
$never = static fn()=>throw new RuntimeException('Incomplete batch was sent');
addVts($database,'vts',1); addVts($database,'vts',2);
deliveryServiceExpect($delivery->deliver('vts',100,$never)==='deferred', 'two points must wait');
deliveryServiceExpect((int)$database->query("SELECT SUM(attempts) FROM relay_pending WHERE unit='vts'")->fetchColumn()===0,'waiting consumed attempts');
addVts($database,'vts',3); addVts($database,'vts',4);
$sent=[];
$send=static function($body,$key)use(&$sent){$sent[]=[$body,$key]; return ['code'=>503];};
deliveryServiceExpect($delivery->deliver('vts',100,$send)==='pending','failure must retain batch');
$payload=json_decode($sent[0][0],true);
deliveryServiceExpect(count($payload['items'])===3 && !isset($payload[VtsBatchPolicy::META]),'wire batch is invalid');
deliveryServiceExpect(latra_idd_validate($payload)['errors']===[],'wire fails strict IDD');
// A new report and repository instance must not change a frozen retry.
addVts($database,'vts',5);
$delivery = new DeliverPendingReport(new PdoRelayDeliveryRepository($database,null,true),3,static fn()=>1);
$accept=static function($body,$key)use(&$sent){$sent[]=[$body,$key];return ['code'=>200,'data'=>['status'=>'1']];};
deliveryServiceExpect($delivery->deliver('vts',101,$accept)==='sent','retry not accepted');
deliveryServiceExpect($sent[0]===$sent[1],'retry body/key changed after new collection');
deliveryServiceExpect((int)$database->query("SELECT COUNT(*) FROM relay_pending WHERE unit='vts'")->fetchColumn()===2,'tail lost');
deliveryServiceExpect((int)$database->query("SELECT COUNT(*) FROM relay_identity WHERE delivery_key IN ('vts-1','vts-2','vts-3') AND state='delivered'")->fetchColumn()===3,'batch members not finalized');
// Eligibility gates apply to all batch activities before any HTTP.
addVts($database,'vts',6);
deliveryServiceExpect($delivery->deliver('vts',102,$never,static fn()=>false)==='deferred','disabled batch sent');
// Cleanup failure rolls back every member and a restart retries the identical body.
$faultRepo=new PdoRelayDeliveryRepository($database,static fn()=>throw new RuntimeException('batch cleanup drill'),true);
try {(new DeliverPendingReport($faultRepo,3,static fn()=>1))->deliver('vts',103,$accept); throw new LogicException('cleanup did not fail');}
catch(RuntimeException $e){deliveryServiceExpect($e->getMessage()==='batch cleanup drill','wrong fault');}
deliveryServiceExpect((int)$database->query("SELECT COUNT(*) FROM relay_pending WHERE unit='vts'")->fetchColumn()===3,'partial group cleanup committed');
deliveryServiceExpect($delivery->deliver('vts',104,$accept)==='sent' && $sent[2]===$sent[3], 'cleanup retry changed body');
// Never rewrite a body that already had an attempt before batching was enabled.
addVts($database,'old',1);$database->exec("UPDATE relay_pending SET attempts=1 WHERE unit='old'");
deliveryServiceExpect($delivery->deliver('old',200,$never)==='review','attempted old body was rewritten');
// A process failing after reservation must leave a frozen group for the next worker.
for($i=1;$i<=3;$i++) addVts($database,'crash',$i);
$crash = new DeliverPendingReport(new PdoRelayDeliveryRepository($database,null,true),3,static fn()=>1,
    static function($stage){if($stage==='attempt')throw new RuntimeException('after reservation');});
try{$crash->deliver('crash',250,$never);throw new LogicException('crash not injected');}
catch(RuntimeException $e){deliveryServiceExpect($e->getMessage()==='after reservation','wrong crash');}
$stored = $database->query("SELECT payload FROM relay_pending WHERE delivery_key='crash-1'")->fetchColumn();
$expected = VtsBatchPolicy::wireBody($stored);
deliveryServiceExpect($delivery->deliver('crash',251,static function($body)use($expected){
    deliveryServiceExpect($body===$expected,'reservation crash changed body');return ['code'=>200,'data'=>['status'=>'1']];
})==='sent','frozen crash group failed recovery');
// Largest legal batch and an incomplete tail; no copies used to reach 3*N.
for($i=1;$i<=200;$i++)addVts($database,'large',$i);
$largeBody='';$capture=static function($body)use(&$largeBody){$largeBody=$body;return ['code'=>200,'data'=>['status'=>'1']];};
deliveryServiceExpect($delivery->deliver('large',300,$capture)==='sent','198-point batch failed');
$large=json_decode($largeBody,true)['items'];
deliveryServiceExpect(count($large)===198 && count(array_unique(array_column($large,'MGS_ID')))===198,'batch padded or exceeded limit');
deliveryServiceExpect($delivery->deliver('large',301,$never)==='deferred','two-item tail sent');
// Older invalid members cannot hide behind a valid head during batch assembly.
for($i=1;$i<=3;$i++)addVts($database,'invalid-member',$i);
$invalid=latra_idd_sample_request(1,1);unset($invalid['items'][0]['HDOP']);
$database->prepare('UPDATE relay_pending SET payload=? WHERE delivery_key=?')->execute([json_encode($invalid),'invalid-member-3']);
deliveryServiceExpect($delivery->deliver('invalid-member',350,$never)==='review','invalid later member reached HTTP');
deliveryServiceExpect((int)$database->query("SELECT COUNT(*) FROM relay_pending WHERE unit='invalid-member' AND status='review'")->fetchColumn()===3,'invalid batch not retained as a whole');
// Exhaustion retains all batch members together for review.
for($i=1;$i<=3;$i++)addVts($database,'exhaust',$i);
$oneTry=new DeliverPendingReport(new PdoRelayDeliveryRepository($database,null,true),1,static fn()=>1);
deliveryServiceExpect($oneTry->deliver('exhaust',400,static fn()=>['code'=>503])==='review','failure not retained');
deliveryServiceExpect((int)$database->query("SELECT COUNT(*) FROM relay_pending WHERE unit='exhaust' AND status='review'")->fetchColumn()===3,'review group split');
echo "PASS strict VTS batching, tail retention, stable retries/restart, all-member gates, atomic cleanup and exhaustion\n";
