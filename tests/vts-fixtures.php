<?php
declare(strict_types=1);
/** Explicit synthetic measurements for tests whose subject is not missing-data handling. */
function vtsTestParams(array $overrides=[]):array {
    return array_replace(['hdop'=>1,'d2d3'=>3,'rssi'=>0,'lac'=>123,'cell_id'=>456,'mcc'=>640,
        'fuel_unit'=>'mm','fuel_signal'=>75,'fuel_valid_flag'=>0,'fuel_soft_status'=>0,'fuel_hard_fault'=>0,
        'rt_fuel'=>2100,'tank_temp'=>31.2],$overrides);
}
function vtsTestMessage(array $overrides=[]):array {
    return array_replace(['wialon_msg_time'=>time()-60,'lat'=>-6.8,'lng'=>39.2,'altitude'=>0,'speed'=>0,'bearing'=>0,
        'satellite_count'=>8,'hdop'=>1,'d2d3'=>3,'rssi'=>0,'lac'=>123,'cell_id'=>456,'mcc'=>640,'mgs_id'=>1],$overrides);
}
