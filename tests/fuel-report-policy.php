<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Domain\Ingestion\FuelReportPolicy;
use Latra\Infrastructure\Persistence\VehicleRowDecoder;
use Latra\Infrastructure\Wialon\WialonSourceReader;
$vehicle=VehicleRowDecoder::decode(['fuel_level_sensor'=>'fuel']);
$reader=new WialonSourceReader();
$data=['sens'=>[['t'=>'fuel','v'=>0,'u'=>'l']], 'pos'=>['t'=>100,'x'=>39.2,'y'=>-6.8]];
$result=FuelReportPolicy::read($vehicle,$reader->decodeUnitData($data));
if ($result['fuel']->level!==0.0 || $result['source']->messageTime!==100
    || FuelReportPolicy::read($vehicle,null)['reason']!=='no_unit_data'
    || FuelReportPolicy::read($vehicle,$reader->decodeUnitData(['sens'=>[]]))['fuel']->level!==null
    || FuelReportPolicy::read($vehicle,$reader->decodeUnitData(['sens'=>$data['sens']]))['source']->messageTime!==null) {
    throw new RuntimeException('Fuel policy invented source evidence or lost zero');
}
echo "PASS fuel-report live evidence, configured sensor and zero preservation\n";
