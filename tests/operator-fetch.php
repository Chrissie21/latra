<?php
/** Operator fetch is relay-only; it never opens a retained source or imports directly. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\Operations\OperatorReadRepository;
use Latra\Application\Operations\FetchOperatorMessages;
function fetchAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$repo=new class implements OperatorReadRepository {
    public array $lookups=[];
    public function vehicle(int $id): ?array {
        $this->lookups[]=$id;
        return $id===1 ? ['id'=>1,'vehicle_reg_no'=>'SYNTHETIC','wialon_unit_id'=>123] : null;
    }
    public function device(int $id): ?array { return null; }
    public function latestMessageWithIdentity(int $id): ?array { return null; }
    public function receipt(int $id): ?array { return null; }
    public function message(int $id): ?array { return null; }
    public function activityStatistics(): array { return []; }
    public function offlineSeconds(): int { return 900; }
};
$polls=[];
$poll=static function(array $vehicle,int $window,int $limit) use (&$polls): array {
    $polls[]=[$vehicle['id'],$window,$limit];
    return ['fetched'=>3,'rejected'=>1,'inserted'=>2,'queued'=>3];
};
$fetch=new FetchOperatorMessages($repo,$poll);
$result=$fetch->one(1);
fetchAssert($polls===[[1,86400,100]] && $result['queued']===3 && $result['fetched']===3,
    'Single fetch bypassed complete-window bounds or lost collection accounting');
fetchAssert(str_contains($result['message'],'3 source reports collected')
    && str_contains($result['message'],'1 newly held for review'),'Single fetch message changed');
try { $fetch->one(0); throw new LogicException('Missing vehicle id accepted'); }
catch (RuntimeException $error) { fetchAssert($error->getMessage()==='Vehicle ID required','Vehicle id contract changed'); }
try { $fetch->one(9); throw new LogicException('Unknown vehicle accepted'); }
catch (RuntimeException $error) { fetchAssert($error->getMessage()==='Vehicle not found','Unknown vehicle contract changed'); }

$events=[];
$fetch->bulk([1,999],static function(array $event) use (&$events): void { $events[]=$event; });
fetchAssert(array_column($events,'type')===['init','progress','progress','done'] && $events[1]['queued']===3
    && $events[2]['status']==='fail' && end($events)===['type'=>'done','success'=>1,'failed'=>1],
    'Bulk relay progress or independent missing-vehicle handling changed');
fetchAssert($polls===[[1,86400,100],[1,86400,100]],'Bulk fetch used different bounds');

$failing=new FetchOperatorMessages($repo,static function(array $vehicle,int $window,int $limit): array {
    throw new RuntimeException('synthetic collection failure');
});
$events=[];
$failing->bulk([1],static function(array $event) use (&$events): void { $events[]=$event; });
fetchAssert(array_column($events,'type')===['init','progress','done']
    && $events[1]['status']==='fail' && end($events)===['type'=>'done','success'=>0,'failed'=>1],
    'Failed collection must be isolated to its vehicle');
try { $fetch->bulk([],static function(array $event): void {}); throw new LogicException('Empty selection accepted'); }
catch (RuntimeException $error) { fetchAssert($error->getMessage()==='No vehicle IDs provided','Empty selection contract changed'); }
echo "PASS operator fetch is relay-only with complete-window bounds, progress and isolated failures\n";
