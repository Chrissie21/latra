<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Ingestion\PollScheduledVehicles;
use Latra\Application\Ingestion\ScheduledVehicleRepository;
use Latra\Domain\Collection\Vehicle;
$repo = new class implements ScheduledVehicleRepository {
    public function ordinaryVehicles(): array { return [new Vehicle(id: 1), new Vehicle(id: 2), new Vehicle(id: 3)]; }
    public function emergencyVehicles(): array { return [new Vehicle(id: 9), new Vehicle(id: 10)]; }
};
$calls=[];$warnings=[];$flushes=[];
$job = new PollScheduledVehicles($repo,
    static function(Vehicle $v,int $window,int $limit) use (&$calls):array {
        $calls[]=[$v->id,$window,$limit];
        if ($v->id===2 || $v->id===10) throw new RuntimeException('fixture');
        return ['inserted'=>2,'rejected'=>1];
    },
    static function(string $event,array $ctx) use (&$warnings):void { $warnings[]=[$event,$ctx]; },
    static function(bool $emergency) use (&$flushes):int { $flushes[]=$emergency; return 4; });
if ($job->ordinary(180)!==6 || $calls!==[[1,180,80],[2,180,80],[3,180,80]]
    || count($warnings)!==1 || $warnings[0][0]!=='poll.vehicle_failed'
    || $warnings[0][1]['vehicle']!==2 || !isset($warnings[0][1]['error']))
    throw new RuntimeException('Ordinary polling contracts changed');
if ($job->emergency()!==6 || array_slice($calls,3)!==[[9,5,20],[10,5,20]]
    || $flushes!==[true] || count($warnings)!==2 || $warnings[1][0]!=='emergency.poll_failed'
    || $warnings[1][1]['vehicle']!==10 || array_key_exists('error',$warnings[1][1]))
    throw new RuntimeException('Emergency polling contracts changed');
echo "PASS scheduled polling bounds, accounting, independent failures and emergency flush\n";
