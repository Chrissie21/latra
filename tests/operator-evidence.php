<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\Operations\OperatorReadRepository;
use Latra\Application\Operations\ReadDeviceSnapshot;
use Latra\Application\Operations\ReadSubmissionEvidence;
$repo=new class implements OperatorReadRepository {
    public function vehicle(int $id): ?array { return $id===1 ? ['id'=>1,'vehicle_reg_no'=>'SYNTHETIC','is_active'=>1,'imei'=>'fixture'] : null; }
    public function device(int $id): ?array { return ['is_online'=>1,'internal_battery_low'=>0,'external_power_disconnected'=>0,'tampered'=>0,'updated_at'=>'2033-05-18 03:30:00']; }
    public function latestMessageWithIdentity(int $id): ?array { return null; }
    public function receipt(int $id): ?array { return $id===1 ? ['response_body'=>'{"status":"1"}','payload'=>'{"items":[]}'] : null; }
    public function message(int $id): ?array { return $id===1 ? ['activity_id'=>2,'latra_payload'=>'','addon_info'=>'[]','fuel_info'=>'[]'] : null; }
    public function activityStatistics(): array { return [['activity_id'=>2,'c'=>3]]; }
    public function offlineSeconds(): int { return 900; }
};
function operatorAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$snapshot=(new ReadDeviceSnapshot($repo,static fn(): int => 2000000000))->read(1);
operatorAssert($snapshot['vehicle']['id']===1 && $snapshot['vehicle']['last_message_time']===null
    && $snapshot['device']['is_online']===true && $snapshot['latest_message']===null && $snapshot['stale']===false, 'Device snapshot invented missing data or changed staleness');
$evidence=new ReadSubmissionEvidence($repo,static fn(array $vehicle,array $source,int $activity,array $addon,array $fuel): array => ['items'=>[['activity_id'=>$activity]]]);
operatorAssert($evidence->response(1)===['status'=>'1'] && $evidence->payload(1,0)===['items'=>[]]
    && $evidence->payload(null,1)===['payload'=>['items'=>[['activity_id'=>2]]]]
    && $evidence->activityStatistics()===['labels'=>['Engine ON'],'values'=>[3]], 'Historical evidence response/projection changed');
foreach ([static fn() => $evidence->response(999),static fn() => $evidence->payload(null,999),
    static fn() => (new ReadDeviceSnapshot($repo,static fn(): int => 0))->read(999)] as $missing) {
    try { $missing();throw new LogicException('Missing evidence was accepted'); } catch (RuntimeException $error) {}
}
echo "PASS operator device snapshot, missing evidence, stored/generated payloads and historical activity projection\n";
