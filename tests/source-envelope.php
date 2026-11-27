<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\FuelReportPolicy;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Domain\Ingestion\LatraPayload;
use Latra\Infrastructure\Wialon\WialonSourceReader;

function envelopeCheck(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException($reason);
    }
}

$reader = new WialonSourceReader();
$vehicle = new Vehicle(
    id: 1,
    imei: '123456789012345',
    vehicle_reg_no: 'SYNTHETIC',
    fuel_level_sensor: 'fuel',
);
$raw = [
    't' => 1700000000,
    'rt' => 1700000001,
    'pos' => [
        'x' => 39.123456789, 'y' => -6.123456789, 'z' => 0,
        's' => 0, 'c' => 0, 'sc' => 0, 'd2d3' => '3d',
    ],
    'p' => [
        'hdop' => 1.25, 'rssi' => 0, 'lac' => 12, 'cell_id' => 34, 'mcc' => 640,
        'odometer' => 123.45, 'vertical_speed' => 2.5, 'driver_id' => 'D1',
        'pwr_ext' => 13.8, 'pwr_int' => 3.7, 'battery' => 0.5,
        'fuel_unit' => 'mm', 'fuel_level' => 2100, 'rt_fuel' => 2134,
        'fuel_signal' => 75, 'fuel_valid_flag' => 0, 'fuel_soft_status' => 0,
        'fuel_hard_fault' => 0, 'tank_temp' => 31.2,
    ],
    'sens' => [
        ['t' => 'fuel', 'v' => 0, 'u' => 'l'],
        ['t' => 'other', 'v' => 5, 'u' => 'mm'],
    ],
];
$message = $reader->decodeMessage($vehicle, $raw);
envelopeCheck(LatraPayloadPolicy::buildAddonInfo(2, $reader->decodeParameters([
    'pass' => '1111111111111111', 'tco_driver1_id' => '2222222222222222',
])) === ['v_driver_identification_no' => '2222222222222222'],
    'Driver addon alias precedence changed');

// Decoder conversions must not make malformed fuel states eligible for delivery.
foreach (['fuel_valid_flag' => 'validFlag', 'fuel_soft_status' => 'softStatus',
    'fuel_hard_fault' => 'hardFault', 'fuel_channel' => 'channel'] as $sourceKey => $wireKey) {
    foreach ([0.5, 'invalid', false] as $invalid) {
        $bad = $raw;
        $bad['p'][$sourceKey] = $invalid;
        $decoded = $reader->decodeMessage($vehicle, $bad);
        $fuel = LatraPayloadPolicy::buildFuelInfo($decoded->fuel);
        $candidate = LatraPayloadPolicy::buildPayload($vehicle, $decoded, 16, [], $fuel)->toWire();
        envelopeCheck(LatraPayloadPolicy::validatePayload($candidate) !== [],
            "$sourceKey was converted into a valid fuel status or default");
    }
}
$fractionalSignal = $raw;
$fractionalSignal['p']['fuel_signal'] = 75.8;
envelopeCheck(LatraPayloadPolicy::buildFuelInfo(
    $reader->decodeMessage($vehicle, $fractionalSignal)->fuel,
)['signalLevel'] === '76', 'Fuel signal no longer uses the existing rounding policy');

// Missing optional bags are empty evidence, never warnings or a decoder TypeError.
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
try {
    foreach ([['t' => 1700000000], ['t' => 1700000000, 'p' => null, 'pos' => null]] as $sparse) {
        $decoded = $reader->decodeMessage($vehicle, $sparse);
        envelopeCheck($decoded->latitude === null && $decoded->hdop === null,
            'Missing source bags fabricated measurements');
    }
} finally {
    restore_error_handler();
}
$decodedUnit = $reader->decodeUnit(['id' => 10, 'lmsg' => ['t' => 1700000000]]);
envelopeCheck($decodedUnit->mgs_id === 1700000000, 'Unit decoding lost its source message identity');

envelopeCheck($message->messageTime === 1700000000 && $message->receivedTime === 1700000001,
    'message/received times were not decoded from the envelope');
envelopeCheck($message->latitude === -6.12345679 && $message->longitude === 39.12345679
    && $message->altitudeM === 0 && $message->speedKmh === 0.0 && $message->bearingDeg === 0.0
    && $message->satelliteCount === 0,
    'reported zero position values were changed or lost');
envelopeCheck($message->fixMode === 3 && $message->hdop === 1.25 && $message->rssi === 0
    && $message->lac === 12 && $message->cellId === 34 && $message->mcc === 640,
    'canonical measurements were not decoded');
envelopeCheck($message->odometerKm === 123.45 && $message->verticalSpeedMps === 2.5
    && $message->driverId === 'D1' && $message->batteryPct === 50.0
    && $message->externalPowerVolts === 13.8 && $message->internalBatteryVolts === 3.7,
    'configured measurements were not decoded');
envelopeCheck($message->fuel->unit === 'mm' && $message->fuel->level === 2100.0
    && $message->fuel->rtLevel === 2134.0 && $message->fuel->signalLevel === 75
    && $message->fuel->tankTempC === 31.2,
    'fuel facts and units were not decoded');
envelopeCheck($message->rawSource === $raw, 'raw source evidence was not retained verbatim');
envelopeCheck($message->signalParameters['pwr_ext'] === 13.8,
    'opaque signal parameters must stay available to the existing signal reader');

$missing = $reader->decodeMessage($vehicle, ['t' => 1700000000, 'pos' => ['x' => 39, 'y' => -6], 'p' => []]);
envelopeCheck($missing->fixMode === null && $missing->hdop === null && $missing->fuel->level === null,
    'absent source facts were invented');

$unitRaw = [
    'pos' => ['t' => 100, 'x' => 39.2, 'y' => -6.8],
    'sens' => [['t' => 'fuel', 'v' => 0, 'u' => 'l']],
];
$unitData = $reader->decodeUnitData($unitRaw);
envelopeCheck($unitData !== null && $unitData->messageTime === 100 && count($unitData->sensors) === 1,
    'unit-data envelope or sensor list was not decoded');
envelopeCheck($unitData->rawSource === $unitRaw, 'raw unit evidence was altered by field elevation');
$fuelReading = FuelReportPolicy::read($vehicle, $unitData);
envelopeCheck($fuelReading['source']->messageTime === 100 && $fuelReading['fuel']->level === 0.0
    && $fuelReading['fuel']->unit === 'l',
    'configured sensor selection lost a reported zero or its unit');
envelopeCheck(FuelReportPolicy::read($vehicle, null)['reason'] === 'no_unit_data',
    'a missing source was not classified explicitly');
$noSensor = $reader->decodeUnitData(['sens' => [['t' => 'other', 'v' => 5, 'u' => 'mm']]]);
envelopeCheck($noSensor !== null && FuelReportPolicy::read($vehicle, $noSensor)['fuel']->level === null,
    'an unconfigured sensor became a fuel measurement');

$addon = LatraPayloadPolicy::buildAddonInfo(9, $message);
envelopeCheck($addon === ['ext_power_voltage' => '13.8', 'int_battery_voltage' => '3.7'],
    'canonical addon evidence was not formatted for the wire');
$fuelInfo = LatraPayloadPolicy::buildFuelInfo($message->fuel);
envelopeCheck($fuelInfo['fuelLevel'] === '2100' && $fuelInfo['rtFuelLevel'] === '2134'
    && $fuelInfo['tankTemp'] === '312' && $fuelInfo['signalLevel'] === '75'
    && $fuelInfo['channel'] === '1' && $fuelInfo['fuelLevel'] !== null,
    'millimetre and Celsius conversion changed');

$stored = [
    'wialon_msg_time' => 1700000000, 'mgs_id' => 1700000000, 'lat' => -6.12345679,
    'lng' => 39.12345679, 'altitude' => 0, 'speed' => 0, 'bearing' => 0,
    'satellite_count' => 0, 'hdop' => 1.25, 'd2d3' => 3, 'rssi' => 0, 'lac' => 12,
    'cell_id' => 34, 'mcc' => 640,
    'raw_data' => json_encode(['p' => [
        'pwr_ext' => 13.8, 'pwr_int' => 3.7, 'fuel_unit' => 'mm',
        'fuel' => 2100, 'rt_fuel' => 2134, 'fuel_signal' => 75,
        'tank_temp' => 31.2, 'fuel_valid_flag' => 0,
        'fuel_soft_status' => 0, 'fuel_hard_fault' => 0,
    ]]),
];
$storedParameters = $reader->decodeStoredParameters($stored);
$storedMessage = \Latra\Domain\Ingestion\TelemetryMessage::fromStored(
    $stored,
    $storedParameters,
    $reader->decodeFuelEvidence($storedParameters),
);
envelopeCheck(LatraPayloadPolicy::buildAddonInfo(9, $storedMessage)
    === ['ext_power_voltage' => '13.8', 'int_battery_voltage' => '3.7']
    && LatraPayloadPolicy::buildFuelInfo($storedMessage->fuel) === $fuelInfo
    && LatraPayloadPolicy::buildPayload($vehicle, $storedMessage, 16, [], $fuelInfo)->toWire()['items'][0]['latitude']
        === '-6.12345679',
    'stored source evidence lost its canonical measurements or addon/fuel values');

$payload = LatraPayloadPolicy::buildPayload($vehicle, $message, 16, [], $fuelInfo);
envelopeCheck($payload instanceof LatraPayload, 'payload assembly did not produce a typed payload');
$wire = $payload->toWire();
envelopeCheck(array_keys($wire) === ['vehicle_reg_no', 'type', 'imei', 'items']
    && array_keys($wire['items'][0]) === [
        'latitude', 'longitude', 'timestamp', 'activity_id', 'odometer', 'addon_info',
        'altitude', 'horizontal_speed', 'bearing', 'satellite_count', 'HDOP',
        'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC', 'vertical_speed', 'fuel_info',
    ],
    'wire ordering changed');
envelopeCheck(LatraPayloadPolicy::validatePayload($wire) === [],
    'decoded canonical envelope did not form a sendable payload');

$incompleteMessage = $reader->decodeMessage($vehicle, ['t' => 1700000000, 'pos' => ['x' => 39, 'y' => -6], 'p' => []]);
$incomplete = LatraPayloadPolicy::buildPayload($vehicle, $incompleteMessage, 1)->toWire();
envelopeCheck($incomplete['items'][0]['HDOP'] === null && $incomplete['items'][0]['d2d3'] === null,
    'missing required fields were fabricated instead of retained for review');
envelopeCheck(LatraPayloadPolicy::validatePayload($incomplete) !== [],
    'incomplete source was incorrectly marked sendable');

echo "PASS source envelope decoding, sensor units and typed LATRA payload assembly\n";
