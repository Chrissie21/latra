<?php
declare(strict_types=1);
require __DIR__.'/../includes/autoload.php';
use Latra\Domain\Ingestion\LatraPayloadPolicy as Policy;
use Latra\Domain\Ingestion\SatelliteModePolicy;
function expectRequired(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$vehicle=['id'=>1,'vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345'];
$message=['wialon_msg_time'=>time()-60,'lat'=>-6.8,'lng'=>39.2,'altitude'=>0,'speed'=>0,'bearing'=>0,
    'satellite_count'=>8,'hdop'=>1,'d2d3'=>3,'rssi'=>0,'lac'=>0,'cell_id'=>0,'mcc'=>640,'mgs_id'=>1];
$fields=['lat'=>'latitude','lng'=>'longitude','altitude'=>'altitude','speed'=>'horizontal_speed',
    'bearing'=>'bearing','satellite_count'=>'satellite_count','hdop'=>'HDOP','d2d3'=>'d2d3',
    'rssi'=>'RSSI','lac'=>'LAC','cell_id'=>'Cell_ID','mcc'=>'MCC','mgs_id'=>'MGS_ID','wialon_msg_time'=>'timestamp'];
foreach($fields as $source=>$wire){
    foreach([null,'','null'] as $empty){
        $missing=$message;$missing[$source]=$empty;
        $cached=$vehicle+$message; // cached measurements must not fill a missing report field
        $payload=Policy::buildPayload($cached,$missing,1);
        expectRequired(Policy::validatePayload($payload)!==[],"missing $source was made sendable");
        expectRequired(($payload['items'][0][$wire]??null)===null || $payload['items'][0][$wire]==='',"missing $source was fabricated");
    }
}
expectRequired(Policy::validatePayload(Policy::buildPayload($vehicle,$message,1))===[],'real measured zero was treated as missing');
expectRequired(SatelliteModePolicy::fromMessage(['z'=>10],[])===null,'unknown fix mode was invented as 2D');
expectRequired(Policy::toEpochMs(null)==='' && Policy::toEpochMs('not-a-date')==='','missing/invalid time was replaced by now');
$optional=Policy::buildPayload($vehicle,$message,2);
expectRequired(Policy::validatePayload($optional)===[],'optional addon/vertical speed became mandatory');
expectRequired($optional['items'][0]['odometer']==='0','documented optional odometer default lost');
$fuel=['unit'=>'mm','validFlag'=>'0','signalLevel'=>'75','softStatus'=>'0','hardFault'=>'0',
    'fuelLevel'=>'2100','rtFuelLevel'=>'2134','tankTemp'=>'312'];
foreach(array_keys($fuel) as $key){
    $missing=$fuel;unset($missing[$key]);
    $p=Policy::buildPayload($vehicle,$message,16,[],Policy::buildFuelInfo([],$missing));
    expectRequired(Policy::validatePayload($p)!==[],"missing fuel $key was fabricated");
}
$fullFuel=Policy::buildFuelInfo([],$fuel);
expectRequired($fullFuel['channel']==='1' && Policy::validatePayload(Policy::buildPayload($vehicle,$message,16,[],$fullFuel))===[],
    'documented channel default or complete fuel failed');
echo "PASS missing required fields stay missing, measured zero survives, optional fields and explicit IDD defaults respected\n";
