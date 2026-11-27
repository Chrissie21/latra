<?php
require_once __DIR__.'/vts-fixtures.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

class IngestionPolicyDatabase
{
    private static ?PDO $database = null;

    public static function fetchAll(string $sql, array $parameters = []): array
    {
        return [
            ['is_enabled' => 1, 'device_type' => '*', 'param_key' => 'battery_level', 'canonical_key' => 'battery_pct'],
            ['is_enabled' => 1, 'device_type' => 'FMB920', 'param_key' => 'v_ext', 'canonical_key' => 'pwr_ext'],
            ['is_enabled' => 1, 'device_type' => 'OTHER', 'param_key' => 'wrong', 'canonical_key' => 'pwr_ext'],
        ];
    }

    public static function getInstance(): PDO
    {
        if (self::$database === null) {
            self::$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            self::$database->exec('CREATE TABLE param_aliases (
                is_enabled INTEGER, device_type TEXT, param_key TEXT, canonical_key TEXT
            )');
            $statement = self::$database->prepare('INSERT INTO param_aliases VALUES (?,?,?,?)');
            foreach (self::fetchAll('') as $alias) {
                $statement->execute([
                    $alias['is_enabled'],
                    $alias['device_type'],
                    $alias['param_key'],
                    $alias['canonical_key'],
                ]);
            }
        }
        return self::$database;
    }
}
class_alias(IngestionPolicyDatabase::class, 'Database');

$vehicle = new \Latra\Domain\Collection\Vehicle(
    id: 42,
    hw_type: 'FMB920',
    imei: '123456789012345',
    vehicle_reg_no: 'SYNTHETIC',
);
$sourceTime = time() - 60;
$source = [
    't' => $sourceTime,
    'rt' => $sourceTime + 1,
    'pos' => ['x' => 39.123456789, 'y' => -6.123456789, 'z' => 0, 's' => 0, 'c' => 0, 'sc' => 0],
    'p' => [
        'battery_level' => 0.5,
        'v_ext' => 0,
        'odometer' => 0,
        'driver_id' => 'driver-7',
        'hdop' => 0,
        'rssi' => 0,
        'lac' => 0,
        'cell_id' => 0,
        'mcc' => 0,
    ],
];
$sourceReader = new \Latra\Infrastructure\Wialon\WialonSourceReader();
$normalized = $sourceReader->decodeMessage($vehicle, $source, ParamResolver::enabledAliases());
if ($normalized->latitude !== -6.12345679 || $normalized->longitude !== 39.12345679
    || $normalized->speedKmh !== 0.0 || $normalized->fixMode !== null
    || $normalized->batteryPct !== 50.0 || $normalized->batteryMv !== null
    || $normalized->odometerKm !== 0.0 || $normalized->driverId !== 'driver-7'
    || $normalized->signalParameters['pwr_ext'] !== 0
    || $sourceReader->decodeParameters(['pwr_int' => 12.5])->batteryMv !== 12500.0
    || $sourceReader->decodeParameters(['battery' => 150])->batteryPct !== 100.0) {
    throw new RuntimeException('Source decoding changed canonical data or zero handling');
}

$validationMessage = $normalized->toValidationArray($vehicle->imei, $vehicle->vehicle_reg_no);
$valid = \Latra\Domain\Ingestion\GpsValidationPolicy::validate($validationMessage);
if (!$valid['valid'] || \Latra\Domain\Ingestion\GpsValidationPolicy::statusOf($valid) !== 'valid') {
    throw new RuntimeException('Valid decoded message failed domain validation');
}
$invalid = \Latra\Domain\Ingestion\GpsValidationPolicy::validate(array_replace($validationMessage, ['lat' => 91, 'bearing' => 360]));
if ($invalid['valid'] || \Latra\Domain\Ingestion\GpsValidationPolicy::statusOf($invalid) !== 'failed'
    || array_column($invalid['errors'], 'field') !== ['lat', 'bearing']) {
    throw new RuntimeException('GPS validation ranges or error ordering changed');
}

$reader = new \Latra\Infrastructure\Wialon\WialonSignalReader();
$event = \Latra\Domain\Ingestion\ActivityDetectionPolicy::detect($reader->readSource(['event' => 'battery_low']));
if ($event['activity_id'] !== \Latra\Domain\Ingestion\ActivityDetectionPolicy::BATTERY_LOW
    || $reader->readSource(['sensor' => 1], 'sensor')->engineOn() !== true
    || $reader->readSource(['status' => 2048], 'status:11')->engineOn() !== true
    || $reader->readSource(['io_66' => 0])->externalPowerPresent() !== false
) {
    throw new RuntimeException('Activity detection policy behavior or compatibility constants changed');
}
$transition = \Latra\Domain\Ingestion\ActivityDetectionPolicy::detect(
    $reader->readSource(['io_239' => 1], 'io_239'),
    ['engine_status' => 'off'],
    ['activity_enabled' => [\Latra\Domain\Ingestion\ActivityDetectionPolicy::ENGINE_ON => true]],
);
if ($transition['activity_id'] !== \Latra\Domain\Ingestion\ActivityDetectionPolicy::ENGINE_ON) {
    throw new RuntimeException('Engine transition decision changed');
}

$unit = $sourceReader->unitFields([
    'id' => 77,
    'nm' => 'unit-name',
    'uid' => '123456789012345',
    'hw' => 'FMB920',
    'lmsg' => ['t' => 1000, 'pos' => ['x' => 39, 'y' => -6, 'z' => 0], 'p' => ['hdop' => 1.5]],
    'pos' => ['t' => 900, 'x' => 1, 'y' => 1],
    'flds' => [['n' => 'plate', 'v' => ' tz-123 ']],
]);
if ($unit['vehicle_reg_no'] !== 'TZ-123' || $unit['lat'] !== -6.0 || $unit['lng'] !== 39.0
    || $unit['last_message_time'] !== 1000 || $unit['d2d3'] !== null
    || LatraMapper::mapVehicle([
        'id' => 77,
        'nm' => 'not a plate name',
        'flds' => [],
    ])["vehicle_reg_no"] !== '') {
    throw new RuntimeException('Vehicle payload normalization or registration fallback changed');
}

$fuelInfo = LatraMapper::buildFuelInfo(vtsTestParams(['fuel_level'=>125,'rt_fuel'=>125,'fuel_signal'=>60]));
$payload = LatraMapper::buildPayload(
    $vehicle,
    vtsTestMessage(['wialon_msg_time'=>$sourceTime,'lat'=>-6.1,'lng'=>39.1,'speed'=>0,'mgs_id'=>0]),
    16,
    [],
    $fuelInfo,
);
if ($payload['items'][0]['latitude'] !== '-6.1' || $payload['items'][0]['horizontal_speed'] !== '0'
    || $payload['items'][0]['MGS_ID'] !== '0' || $payload['items'][0]['addon_info'] !== ''
    || $payload['items'][0]['fuel_info'] !== $fuelInfo
    || LatraMapper::validatePayload($payload) !== []
    || LatraMapper::buildFuelInfo(['fuel_level'=>125])['fuelLevel'] !== null) {
    throw new RuntimeException('LATRA payload policy changed formatting, activity blocks, or fuel units');
}
$missingFuel = $payload;
unset($missingFuel['items'][0]['fuel_info']);
if (!in_array('items[0].fuel_info is required for activity 16', LatraMapper::validatePayload($missingFuel), true)
    || LatraMapper::activityRequiresAddon(16)
    || LatraMapper::getActivityName(24) !== 'iButton Scan (Regular)') {
    throw new RuntimeException('LATRA payload validation or activity metadata changed');
}
$orderedErrors = LatraMapper::validatePayload([
    'items' => [[
        'latitude' => '',
        'longitude' => null,
        'timestamp' => '',
        'activity_id' => 16,
        'altitude' => 'unknown',
    ]],
]);
if (array_slice($orderedErrors, 0, 6) !== [
    'vehicle_reg_no is required',
    'imei is required',
    'items[0].latitude is required',
    'items[0].longitude is required',
    'items[0].timestamp is required',
    'items[0].altitude must be numeric',
] || !in_array('items[0].HDOP is required', $orderedErrors, true)
    || end($orderedErrors) !== 'items[0].fuel_info is required for activity 16') {
    throw new RuntimeException('LATRA payload validation error order changed');
}

echo "PASS domain normalization, activity detection, GPS and LATRA payload policies, and global adapters\n";
