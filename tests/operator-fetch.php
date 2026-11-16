<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\Operations\OperatorReadRepository;
use Latra\Application\Operations\FetchOperatorMessages;
use Latra\Application\Operations\RetainedMessageSource;
function fetchAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$repo=new class implements OperatorReadRepository {
    public function vehicle(int $id): ?array { return $id===1 ? ['id'=>1,'vehicle_reg_no'=>'SYNTHETIC','wialon_unit_id'=>123] : null; }
    public function device(int $id): ?array { return null; }
    public function latestMessageWithIdentity(int $id): ?array { return null; }
    public function receipt(int $id): ?array { return null; }
    public function message(int $id): ?array { return null; }
    public function activityStatistics(): array { return []; }
    public function offlineSeconds(): int { return 900; }
};
$source=new class implements RetainedMessageSource {
    public bool $opens=true;public bool $fail=false;public int $closed=0;public array $calls=[];
    public function open(): bool { return $this->opens; }
    public function close(): void { $this->closed++; }
    public function messages(int $unit,int $from,int $to,int $limit): array {
        $this->calls[]=[$unit,$from,$to,$limit];
        if ($this->fail) throw new RuntimeException('synthetic failure');
        return [['t'=>1000]];
    }
};
$polls=[];$imports=[];
$factory=static fn(): RetainedMessageSource => $source;
$poll=static function(array $vehicle,int $window,int $limit) use (&$polls): array { $polls[]=[$vehicle['id'],$window,$limit];return ['fetched'=>3,'rejected'=>1,'inserted'=>2,'queued'=>3]; };
$import=static function(array $vehicle,array $messages) use (&$imports): array { $imports[]=[$vehicle,$messages];return ['inserted'=>2,'rejected'=>1]; };
$relay=new FetchOperatorMessages($repo,true,$poll,$import,$factory,static fn(): int => 100000);
fetchAssert($relay->one(1)['queued']===3 && $polls===[[1,86400,100]] && $source->calls===[], 'Operator relay fetch bypassed bounds or opened retained source');
$events=[];$relay->bulk([1,999],static function(array $event) use (&$events): void { $events[]=$event; });
fetchAssert(array_column($events,'type')===['init','progress','progress','done'] && $events[1]['queued']===3
    && $events[2]['status']==='fail' && end($events)===['type'=>'done','success'=>1,'failed'=>1], 'Bulk relay progress or independent missing-vehicle handling changed');
$legacy=new FetchOperatorMessages($repo,false,$poll,$import,$factory,static fn(): int => 100000);
$result=$legacy->one(1);
fetchAssert($result===['success'=>true,'message'=>'3 messages fetched and stored (1 invalid/rejected)']
    && $source->calls===[[123,13600,100000,100]] && $source->closed===1 && count($imports)===1, 'Legacy import-only source bounds/accounting changed');
$source->fail=true;
try { $legacy->one(1);throw new LogicException('Missing source failure'); } catch (RuntimeException $error) {}
fetchAssert($source->closed===2, 'Failed source read leaked a session');
$events=[];$legacy->bulk([1,999],static function(array $event) use (&$events): void { $events[]=$event; });
fetchAssert(end($events)===['type'=>'done','success'=>0,'failed'=>2] && $source->closed===3, 'Failed legacy bulk work lost progress/session cleanup');
$source->opens=false;$events=[];$legacy->bulk([1],static function(array $event) use (&$events): void { $events[]=$event; });
fetchAssert(array_column($events,'type')===['init','error'], 'Bulk login failure contract changed');
echo "PASS operator single/bulk relay fetch, import-only fallback, work bounds, progress and source-session cleanup\n";
