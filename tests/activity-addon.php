<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Ingestion\BuildActivityAddon;
use Latra\Infrastructure\Wialon\WialonSourceReader;
$decoder=new WialonSourceReader();
$events=[];
$work=new BuildActivityAddon(static function(string $event,array $context)use(&$events):void{$events[]=[$event,$context];});
$work->build(9,$decoder->decodeParameters([]));
if(array_column(array_column($events,1),'field')!==['ext_power_voltage','int_battery_voltage'])throw new RuntimeException('Missing voltage diagnostics changed');
$events=[];
$result=$work->build(9,$decoder->decodeParameters(['pwr_ext'=>0,'pwr_int'=>0]));
if($events!==[] || $result!==\Latra\Domain\Ingestion\LatraPayloadPolicy::buildAddonInfo(
    9,$decoder->decodeParameters(['pwr_ext'=>0,'pwr_int'=>0])
))throw new RuntimeException('Zero voltage was treated as missing');
$work->build(1,$decoder->decodeParameters([]));
if($events!==[])throw new RuntimeException('Non-voltage activity emitted diagnostics');
echo "PASS addon orchestration preserves missing/zero voltage diagnostics and domain output\n";
