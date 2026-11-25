<?php
declare(strict_types=1);
require __DIR__.'/../includes/autoload.php';
require __DIR__.'/../includes/DurableRelay.php';
use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Application\Collection\QueueManualRelayPayload;
use Latra\Infrastructure\Persistence\PdoRelayDeliveryRepository;
use Latra\Application\Delivery\DeliverPendingReport;
function checkReview(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
if(class_exists('Pdo\\Sqlite')) { class RequiredReviewBase extends Pdo\Sqlite {} }
else { class RequiredReviewBase extends PDO {} }
class RequiredReviewPDO extends RequiredReviewBase {
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','INSERT IGNORE INTO','OCTET_LENGTH(payload)'],['','INSERT OR IGNORE INTO','LENGTH(payload)'],$query),$options);
    }
}
$file=tempnam(sys_get_temp_dir(),'latra-required-review-');
function openReviewDb(string $file):PDO {
    $db=new RequiredReviewPDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $register=method_exists($db,'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
    $db->$register('GET_LOCK',static fn()=>1);
    $db->$register('IS_USED_LOCK',static fn()=>1);
    $db->$register('CONNECTION_ID',static fn()=>1);
    $db->$register('RELEASE_LOCK',static fn()=>1);
    $db->$register('GREATEST',static fn(...$v)=>max($v));
    return $db;
}
try {
$db=openReviewDb($file);
$db->exec('CREATE TABLE relay_units(unit TEXT PRIMARY KEY,collected_through INTEGER,delivered_time INTEGER,attempted_time INTEGER,state_json TEXT)');
$db->exec('CREATE TABLE relay_identity(id INTEGER PRIMARY KEY AUTOINCREMENT,delivery_key TEXT UNIQUE,unit TEXT,source_time INTEGER,state TEXT,source_key TEXT)');
$db->exec('CREATE TABLE relay_pending(id INTEGER PRIMARY KEY AUTOINCREMENT,delivery_key TEXT UNIQUE,unit TEXT,source_time INTEGER,payload TEXT,status TEXT,attempts INTEGER DEFAULT 0,next_attempt INTEGER DEFAULT 0,last_error TEXT)');
$db->exec('CREATE TABLE relay_activity_identity(source_key TEXT,activity_id INTEGER,delivery_key TEXT,PRIMARY KEY(source_key,activity_id))');
$vehicle=['id'=>1,'wialon_unit_id'=>100,'vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345'];
$now=time()-120;
$raw=['t'=>$now,'pos'=>['x'=>39.2,'y'=>-6.8,'z'=>0,'s'=>0,'c'=>0,'sc'=>8],
    'p'=>['hdop'=>1,'d2d3'=>3,'rssi'=>0,'lac'=>0,'cell_id'=>0,'mcc'=>640]];
$mapper=static fn($v)=>new RelayPayloadMapper($v,[1=>true],[1],80,2,3200,'generic');
$cases=['pos.y','pos.x','pos.z','pos.s','pos.c','pos.sc','p.hdop','p.d2d3','p.rssi','p.lac','p.cell_id','p.mcc','t','imei','vehicle_reg_no'];
$sends=0;
foreach($cases as $index=>$field){
    $message=$raw;$owner=$vehicle;
    if(str_contains($field,'.')){[$part,$key]=explode('.',$field);unset($message[$part][$key]);}
    elseif($field==='t')unset($message['t']);else $owner[$field]=null;
    $map=$mapper($owner);$unit='wialon:'.(1000+$index).':poll';
    $queue=new DurableRelay($db,3,static fn()=>1,100,1048576,null,true);
    $fetch=static fn()=>['count'=>1,'messages'=>[$message]];
    $result=$queue->collectWindow($unit,$now,$now,1,1,1048576,$fetch,[$map,'map']);
    checkReview($result['rejected']===1 && $result['inserted']===0,"$field not held for review");
    $statement=$db->prepare('SELECT * FROM relay_pending WHERE unit=?');$statement->execute([$unit]);$row=$statement->fetch(PDO::FETCH_ASSOC);$statement->closeCursor();
    checkReview($row['status']==='review' && $row['next_attempt']===null && (int)$row['attempts']===0,"$field scheduled for retry");
    checkReview(json_decode($row['payload'],true)==$message,"$field raw evidence changed");
    checkReview(str_contains($row['last_error'],'required') || str_contains($row['last_error'],'numeric'),"$field lacks review reason");
    $again=$queue->collectWindow($unit,$now,$now,1,1,1048576,$fetch,[$map,'map']);
    checkReview($again['skipped']===1 && $again['queued']===0,"$field duplicate review created");
    checkReview($queue->deliver($unit,$now+100,static function()use(&$sends){$sends++;return [];})==='blocked',"$field was delivered");
}
foreach(['fuel_unit','fuel_level','rt_fuel','fuel_signal','fuel_valid_flag','fuel_soft_status','fuel_hard_fault','tank_temp'] as $i=>$field) {
    $message=$raw;
    $message['p']+=['event'=>'fuel','fuel_unit'=>'mm','fuel_level'=>2100,'rt_fuel'=>2134,'fuel_signal'=>75,
        'fuel_valid_flag'=>0,'fuel_soft_status'=>0,'fuel_hard_fault'=>0,'tank_temp'=>31.2];
    unset($message['p'][$field]);
    $map=new RelayPayloadMapper($vehicle,[1=>true,16=>true],[1,16],80,2,3200,'generic');
    $q=new DurableRelay($db,3,static fn()=>1,100,1048576,null,true);
    $result=$q->collectWindow('wialon:'.(2000+$i).':poll',$now,$now,1,1,1048576,
        static fn()=>['count'=>1,'messages'=>[$message]],[$map,'map']);
    checkReview($result['rejected']===1 && $result['inserted']===0,"missing fuel $field was sendable");
}
checkReview($sends===0,'held data made an HTTP attempt');
$count=(int)$db->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn();
$statement=null;$queue=null;$db=null;$db=openReviewDb($file);
checkReview((int)$db->query("SELECT COUNT(*) FROM relay_pending WHERE status='review'")->fetchColumn()===$count,'review evidence did not survive restart');
// Revalidating without supplying the missing measurements cannot release a held record.
$queue=new DurableRelay($db,3,static fn()=>1,100,1048576,null,true);
$held=$queue->recoverReviews('wialon:1007',static fn($source)=>$mapper($vehicle)->map($source,[]));
checkReview($held['recovered']===0 && $held['held']===1,'review was released while required data was still missing');
$fixed=$queue->recoverReviews('wialon:1007',static function($source)use($mapper,$vehicle){
    $source['p']['d2d3']=3;return $mapper($vehicle)->map($source,[]);
});
checkReview($fixed['recovered']===1,'explicitly corrected and validated review did not recover');
// Invalid manual input is retained with its original payload, even when GPS time/IMEI is absent.
$manual=['type'=>'poi','vehicle_reg_no'=>'SYNTHETIC','items'=>[['latitude'=>'-6.8']]];
$result=(new QueueManualRelayPayload($queue,1048576))->queue($vehicle,$manual,['sens'=>[['v'=>50,'u'=>'l']]]);
checkReview(!$result['success'] && $result['review'] && $result['queued']===1,'manual missing data was discarded');
$stored=$db->query("SELECT payload FROM relay_pending WHERE unit LIKE 'manual:%'")->fetchColumn();
checkReview(json_decode($stored,true)['payload']==$manual,'manual review lost original submission');
checkReview(json_decode($stored,true)['source_evidence']['sens'][0]['u']==='l','fuel review lost raw unit evidence');
// Scheduled fuel with missing GPS/fuel details must reach durable review, retaining sensor units.
$fuelSource=new class implements \Latra\Application\Ingestion\FuelReportSource {
    public bool $closed=false;
    public function open():bool{return true;}
    public function read(int $unitId):?array{return ['source'=>'synthetic-fuel','sens'=>[['t'=>'fuel','v'=>50,'u'=>'l']]];}
    public function close():void{$this->closed=true;}
};
$fuelRepo=new class($vehicle) implements \Latra\Application\Ingestion\FuelReportRepository {
    public function __construct(private array $vehicle){}
    public function vehicles():array{return [$this->vehicle+['fuel_level_sensor'=>'fuel']];}
};
$manualQueue=new QueueManualRelayPayload($queue,1048576);
$fuelJob=new \Latra\Application\Ingestion\SubmitFuelReports($fuelRepo,$fuelSource,
    static fn($v,$p,$evidence)=>$manualQueue->queue($v,$p,$evidence),static fn()=>null,true);
checkReview($fuelJob->run()===0 && $fuelSource->closed,'incomplete fuel was claimed sendable or source leaked');
$fuelRow=$db->query('SELECT status,payload FROM relay_pending ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$fuelRaw=json_decode($fuelRow['payload'],true);
checkReview($fuelRow['status']==='review' && $fuelRaw['source_evidence']['sens'][0]['v']===50
    && $fuelRaw['source_evidence']['sens'][0]['u']==='l' && $fuelRaw['payload']['items'][0]['timestamp']==='',
    'incomplete scheduled fuel was discarded, fabricated, or lost raw calibration evidence');
// API use-case validation must route incomplete custom input to the real durable queue.
$operatorRepo=new class($vehicle) implements \Latra\Application\Operations\OperatorActionRepository {
    public function __construct(private array $owner){}
    public function vehicle(int $id):?array{return $this->owner;}
    public function messageForRelay(int $id):?array{return null;}
    public function messageForSubmission(int $id):?array{return null;}
    public function latestMessage(int $id):?array{return null;}
    public function pushLog(int $id):?array{return null;}
    public function deletePushLog(int $id):void{}
    public function activityMapping(int $v,int $a):?array{return null;}
    public function insertActivityMapping(int $v,int $a,int $e):void{}
    public function updateActivityMapping(int $id,int $e):void{}
    public function setVehicleActive(int $id,int $a):void{}
    public function schedulerHealth():array{return [];}
};
$operator=new \Latra\Application\Operations\SubmitOperatorPayload($operatorRepo,
    static fn()=>[],[\Latra\Domain\Ingestion\LatraPayloadPolicy::class,'validatePayload'],[$manualQueue,'queue']);
$incomplete=['imei'=>'','vehicle_reg_no'=>'SYNTHETIC','items'=>[['latitude'=>'-6.8']]];
\Latra\Domain\Collection\RelayIdentityPolicy::assertOwns($vehicle,$incomplete);
$custom=$operator->fromCustomPayload(1,json_encode($incomplete));
$customRow=$db->query('SELECT status,payload FROM relay_pending ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
checkReview(!$custom['success'] && $custom['review'] && $customRow['status']==='review'
    && json_decode($customRow['payload'],true)['payload']==$incomplete,
    'API custom input was rejected before retention or its missing identity was filled');
// Already queued/retried invalid bodies are quarantined without a send or body rewrite.
$key=str_repeat('a',64);$payload=['type'=>'poi','vehicle_reg_no'=>'SYNTHETIC','items'=>array_fill(0,3,['activity_id'=>'1'])];
$db->exec("INSERT INTO relay_units(unit) VALUES ('wialon:5000:poll')");
$db->prepare('INSERT INTO relay_identity(delivery_key,unit,source_time,state) VALUES (?,?,?,?)')->execute([$key,'wialon:5000:poll',$now,'pending']);
$db->prepare('INSERT INTO relay_pending(delivery_key,unit,source_time,payload,status,attempts,next_attempt) VALUES (?,?,?,?,?,?,?)')->execute([$key,'wialon:5000:poll',$now,json_encode($payload),'pending',1,0]);
$delivery=new DeliverPendingReport(new PdoRelayDeliveryRepository($db,null,true),3,static fn()=>1);
checkReview($delivery->deliver('wialon:5000:poll',$now+100,static function()use(&$sends){$sends++;return [];})==='review' && $sends===0,'legacy retry bypassed required data validation');
$body=$db->query("SELECT payload FROM relay_pending WHERE delivery_key='$key'")->fetchColumn();
checkReview(json_decode($body,true)===$payload,'reviewed retry body was overwritten');
echo "PASS every missing required source field durably reviewed, raw evidence/replay/restart retained, explicit recovery and manual/retry delivery blocked\n";
}finally{$db=null;@unlink($file);}
