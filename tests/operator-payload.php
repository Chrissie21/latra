<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\Operations\OperatorPayloadRepository;
use Latra\Application\Operations\LatestVehicleSource;
use Latra\Application\Operations\BuildOperatorPayload;
function payloadAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$repo=new class implements OperatorPayloadRepository {
    public ?array $latest=['id'=>1,'wialon_msg_time'=>2000000000,'lat'=>-6.8,'lng'=>39.2,'speed'=>0,'raw_data'=>'{}'];
    public function vehicle(int $id): ?array { return $id===1 ? ['id'=>1,'wialon_unit_id'=>123,'vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345'] : null; }
    public function latestMessage(int $id): ?array { return $this->latest; }
    public function recentMessages(int $id,int $limit): array { return $this->latest ? [$this->latest] : []; }
};
$source=new class implements LatestVehicleSource {
    public int $calls=0;public bool $fail=false;
    public function latest(int $unit,int $from,int $to): ?array {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('Synthetic source unavailable');
        return ['unit'=>null,'message'=>['t'=>1999998000,'pos'=>['x'=>39.2,'y'=>-6.8,'s'=>0],'p'=>[]]];
    }
};
$work=new BuildOperatorPayload($repo,$source,static fn(int $id,int $at): array => [],static fn(): int => 2000000000);
$result=$work->build(1,2,'single');
payloadAssert($result['meta']===['source'=>'stored','source_time'=>2000000000,'is_recent'=>true] && $source->calls===0, 'Fresh stored source triggered a live fetch or lost provenance');
$repo->latest['wialon_msg_time']=1999900000;
$result=$work->build(1,2,'single');
payloadAssert($result['meta']===['source'=>'live','source_time'=>1999998000,'is_recent'=>false]
    && $result['payload']['vehicle_reg_no']==='SYNTHETIC' && $result['payload']['imei']==='123456789012345', 'Live provenance invented recency or erased known owner identity');
$source->fail=true;$result=$work->build(1,2,'single');
payloadAssert($result['meta']['source']==='stored' && !$result['meta']['is_recent'], 'Unavailable live source lost stored fallback');
$calls=$source->calls;$result=$work->build(1,2,'bulk');
payloadAssert(count($result['payload']['items'])===1 && $result['meta']===null && $source->calls===$calls, 'Bulk preview changed source/work policy');
echo "PASS operator payload stored/live fallback, identity, truthful recency and bounded bulk composition\n";
