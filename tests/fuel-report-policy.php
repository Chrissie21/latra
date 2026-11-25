<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Domain\Ingestion\FuelReportPolicy;
$vehicle=['fuel_level_sensor'=>'fuel'];
$data=['sens'=>[['t'=>'fuel','v'=>0,'u'=>'l']], 'pos'=>['t'=>100,'x'=>39.2,'y'=>-6.8]];
$result=FuelReportPolicy::read($vehicle,$data);
if ($result['fuel']['fuel_level']!==0 || $result['source']['wialon_msg_time']!==100
    || FuelReportPolicy::read($vehicle,null)['reason']!=='no_unit_data'
    || FuelReportPolicy::read($vehicle,['sens'=>[]])['fuel']['fuel_level']!==null
    || FuelReportPolicy::read($vehicle,['sens'=>$data['sens']])['source']['wialon_msg_time']!==null) {
    throw new RuntimeException('Fuel policy invented source evidence or lost zero');
}
echo "PASS fuel-report live evidence, configured sensor and zero preservation\n";
