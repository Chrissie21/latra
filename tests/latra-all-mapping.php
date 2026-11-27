<?php
declare(strict_types=1);
require_once __DIR__.'/vts-fixtures.php';
require __DIR__ . '/../includes/autoload.php';
require __DIR__ . '/latra-idd.php';
use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Domain\Collection\Vehicle;
use Latra\Infrastructure\Wialon\WialonSignalReader;
use Latra\Infrastructure\Wialon\WialonSourceReader;

function verify(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$vehicle = new Vehicle(id: 1, imei: '123456789012345', vehicle_reg_no: 'SYNTHETIC', engine_param: 'ignition');
$mapper = new RelayPayloadMapper($vehicle, array_fill_keys(range(1,24), true), range(1,24), 80, 1, 3200, 'generic', new WialonSignalReader(), new WialonSourceReader());
$events = [1=>'', 2=>'engine_on', 3=>'engine_off', 4=>'speeding', 5=>'harsh_braking', 6=>'harsh_turning',
    7=>'harsh_acceleration', 8=>'panic', 9=>'battery_low', 10=>'power_disconnected', 11=>'excessive_idle',
    12=>'accident', 13=>'passenger_panic', 14=>'tamper', 15=>'black_box', 16=>'fuel', 17=>'invalid_scan',
    18=>'engine_start', 19=>'engine_stop', 20=>'boundary_enter', 21=>'boundary_exit', 22=>'checkpoint_enter',
    23=>'checkpoint_exit', 24=>'ibutton'];
$now = time()-120;
function raw(array $p): array {
    global $now;
    return ['t'=>$now, 'pos'=>['x'=>39.1,'y'=>-6.8,'z'=>10,'s'=>90,'c'=>50,'sc'=>9], 'p'=>vtsTestParams($p)];
}
foreach ($events as $id=>$event) {
    $p = ['event'=>$event, 'driver_id'=>'D70000127F886501', 'fuel_level'=>2100, 'fuel_unit'=>'mm',
        'fuel_signal'=>75, 'tank_temp'=>31.2, 'd2d3'=>3, 'vertical_speed'=>2.5, 'odometer'=>234.5];
    $state = $id===3 ? ['trip'=>['from'=>$now-600,'distance'=>5,'sum'=>100,'count'=>2,'max'=>80]] : [];
    $mapped = $mapper->map(raw($p), $state);
    $items = array_column($mapped['payload']['items'], null, 'activity_id');
    verify(isset($items[$id]), "activity $id missing");
    $verdict = latra_idd_validate($mapped['payload'], ['three_n'=>false]);
    verify($verdict['errors']===[] && $verdict['warnings']===[], "activity $id: ".json_encode($verdict));
    verify($items[$id]['vertical_speed']==='2.5' && $items[$id]['odometer']==='234.5', 'measurements lost');
}
$many = $mapper->map(raw(['panic'=>1,'passenger_panic'=>1,'accident'=>1,'checkpoint_enter'=>1,'fuel_level'=>0]), []);
$ids = array_map('intval', array_column($many['payload']['items'],'activity_id'));
foreach ([8,12,13,16,22] as $id) verify(in_array($id,$ids,true), "simultaneous activity $id lost");
$inactive = raw(['invalid_scan'=>0, 'panic'=>null, 'passenger_panic'=>'0']); $inactive['pos']['s']=0;
$off = $mapper->map($inactive, []);
verify(!in_array('17',array_column($off['payload']['items'],'activity_id'),true), 'inactive invalid scan emitted');
$on = $mapper->map(raw(['event'=>'engine_on']), []);
$endRaw=raw(['event'=>'engine_off']); $endRaw['t']+=60;
$end=$mapper->map($endRaw,$on['state']);
verify(in_array('3',array_column($end['payload']['items'],'activity_id'),true), 'explicit trip events lost without ignition IO');
$ambiguous = raw(['event'=>'panic_cleared','io_104'=>1,'io_233'=>1,'io_8'=>1,'ho'=>1]); $ambiguous['pos']['s']=0;
verify(array_column($mapper->map($ambiguous,[])['payload']['items'],'activity_id')===['1'], 'unverified hardware or event substring fabricated activity');
$forged = $many['payload']; $forged['__relay_batch_v1'] = [['id'=>99]];
verify(\Latra\Domain\Ingestion\LatraPayloadPolicy::validatePayload($forged)!==[], 'caller injected batch manifest');
echo "PASS all 24 activities through real mapper, all fields, simultaneous signals and inactive flags\n";
