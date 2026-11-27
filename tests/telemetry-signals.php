<?php
declare(strict_types=1);
/** Canonical telemetry evidence: flag rules, decoding, payload results and review retention. */
require_once __DIR__ . '/vts-fixtures.php';
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\ActivityDetectionPolicy;
use Latra\Domain\Ingestion\DeviceStatusPolicy;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Domain\Ingestion\RelayActivitySignals;
use Latra\Infrastructure\Wialon\WialonSignalReader;
use Latra\Infrastructure\Wialon\WialonSourceReader;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(callable $action, string $contains = ''): void
{
    try {
        $action();
    } catch (Throwable $error) {
        if ($contains !== '' && !str_contains($error->getMessage(), $contains)) {
            throw new RuntimeException('Rejection reason changed: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected rejection');
}

$reader = new WialonSignalReader();
$detectSource = static function (array $parameters, array $previous = [], array $context = []) use ($reader): int {
    $speed = isset($context['speed']) ? (float) $context['speed'] : null;
    return (int) ActivityDetectionPolicy::detect(
        $reader->readSource($parameters, null, $speed), $previous, $context
    )['activity_id'];
};

/* Strict matching accepts only explicit on-values; loose matching keeps first-present truthiness. */
foreach ([1, '1', true] as $value) {
    check($reader->read(['panic' => $value])->strictFlag('panic'), 'Strict flag rejected an on-value');
}
foreach ([2, 0, '0', false, null, ''] as $value) {
    check(!$reader->read(['panic' => $value])->strictFlag('panic'), 'Strict flag accepted an off-value');
}
$conflict = $reader->readSource(['panic' => 0, 'sos' => 1]);
check($conflict->strictFlag('panic') === true, 'Strict matching did not inspect a later alias');
check($conflict->looseFlag('panic_driver') === false, 'Loose matching did not stop at the first present alias');
check($reader->readSource(['tamper' => null])->looseFlag('tamper') === true, 'Loose null was treated as absent');
check($reader->readSource(['tamper' => ''])->looseFlag('tamper') === true, 'Loose empty string was treated as absent');
check($reader->readSource(['tamper' => false])->looseFlag('tamper') === false, 'Loose false was treated as on');
$relayConflict = $reader->read(['battery_low' => 0, 'low_battery' => 1]);
check($relayConflict->strictFlag('battery_low') === true
    && $relayConflict->looseFlag('battery_low') === false,
    'Relay strict/loose alias evidence was collapsed');

/* Named strict detection inspects every alias, while loose detection keeps its own first key. */
check(in_array(ActivityDetectionPolicy::PANIC_DRIVER, array_keys(
    RelayActivitySignals::detect($reader->read(['panic' => 0, 'sos' => 1]))
), true), 'Strict named detection lost a later alias');
check($detectSource(['battery_low' => 0, 'low_battery' => 1]) === ActivityDetectionPolicy::MOVEMENT,
    'Loose detection lost first-present precedence');

/* Configured and default engine sources, including adapter-side bit decoding. */
check($reader->read(['sensor' => 1], null, 'sensor')->engineOn() === true, 'Configured engine source lost');
check($reader->read(['sensor' => 0], null, 'sensor')->engineOn() === false, 'Configured engine false lost');
check($reader->read(['status' => 2048], null, 'status:11')->engineOn() === true, 'Engine bit decoding lost');
check($reader->read(['io_239' => 1], null, 'missing')->engineOn() === true,
    'Missing configured source did not use the default keys');
check($reader->read(['ignition' => 0, 'io_239' => 1])->engineOn() === false,
    'Default engine source precedence changed');
check($reader->read([])->engineOn() === null, 'Absent engine evidence became a value');

// The filtered detector and the mapper's configured engine source are independent views.
$filteredEngine = $reader->read(['pass' => 1, 'ignition' => 0], null, 'pass');
check($filteredEngine->engineOn() === true, 'Configured engine source was filtered out');
check(ActivityDetectionPolicy::detect($filteredEngine, ['engine_status' => 'on'])['activity_id']
    === ActivityDetectionPolicy::ENGINE_OFF, 'Detector lost its filtered engine fallback');

/* Explicit position speed wins; the detector's parameter-bag fallback remains available. */
check($reader->read(['speed' => 90])->speedKmh() === 90.0, 'Parameter-bag speed fallback lost');
check($reader->read(['speed' => 90], null, null, 40.0)->speedKmh() === 40.0,
    'Explicit speed context lost precedence');

/* G-force precedence, signed forms, scale and absence. */
check($reader->readSource(['ho' => -0.5, 'io_79' => 1000])->gForce() === -0.5, 'ho precedence lost');
check($reader->readSource(['io_79' => 500])->gForce() === 0.5, 'Unsigned g-force scale lost');
check($reader->readSource(['io_80' => 4294966796])->gForce() === -0.5, 'Signed g-force form lost');
check($reader->readSource([])->gForce() === null, 'Absent g-force became a value');
check($detectSource(['ho' => -0.5]) === ActivityDetectionPolicy::HASH_BRAKING, 'g-force detection lost');
check($detectSource(['ho' => 0.5]) === ActivityDetectionPolicy::HASH_ACCEL, 'g-force acceleration lost');

/* Relay rails: profile-specific IO precedence, units, zero and failure behavior. */
check($reader->read(['io_66' => 12500, 'pwr_ext' => 9.9], 'teltonika-fmb')->externalPowerVolts() === 12.5,
    'FMB IO rail did not take precedence or scale from millivolts');
check($reader->read(['io_66' => 12500, 'pwr_ext' => 12.5], 'generic')->externalPowerVolts() === 12.5,
    'Generic profile used profile-specific IO');
check($reader->read(['io_67' => 4100], 'teltonika-fmb')->internalBatteryVolts() === 4.1,
    'Internal battery voltage scale lost');
check($reader->read(['io_66' => 0], 'teltonika-fmb')->externalPowerVolts() === 0.0,
    'Reported zero rail became absence');
check($reader->read([], 'teltonika-fmb')->externalPowerVolts() === null, 'Absent rail became a value');
check($reader->read(['io_66' => null, 'pwr_ext' => 12.0], 'teltonika-fmb')->externalPowerVolts() === null,
    'Present null IO did not suppress the named rail');
rejects(static fn() => $reader->read(['pwr_ext' => 'broken'], 'generic'), 'Invalid voltage');
rejects(static fn() => $reader->read(['io_66' => -1], 'teltonika-fmb'), 'Invalid voltage');

/* Device-status units are decoded separately from relay voltage rules. */
check($reader->readDeviceStatus(['pwr_ext' => 0.9])->externalPowerMv() === 900.0,
    'Device millivolt heuristic changed');
check($reader->readDeviceStatus(['pwr_ext' => 12500])->externalPowerMv() === 12500.0,
    'Device millivolt value was rescaled');
check($reader->readDeviceStatus(['pwr_ext' => null, 'ext_power' => 1.0])->externalPowerMv() === 1000.0,
    'Device rail precedence lost');
check($reader->readDeviceStatus(['pwr_int' => 3.1, 'io_67' => 3000])->internalBatteryMv() === 3100.0,
    'Device internal rail precedence lost');
check($reader->readDeviceStatus(['battery' => 0.1])->batteryPct() === 10.0, 'Battery percentage scale lost');
check($reader->readDeviceStatus(['battery' => 1])->batteryPct() === 100.0, 'Battery 1.0 conversion lost');
check($reader->readDeviceStatus(['battery' => 150])->batteryPct() === 100.0, 'Battery percentage was not clamped');
check($reader->readDeviceStatus(['battery' => -5])->batteryPct() === 0.0, 'Negative battery percentage was not clamped');

/* FMB enums stay behind the explicit profile and keep their own value types. */
$vehicle = new Vehicle(id: 1, imei: '123456789012345', vehicle_reg_no: 'SYNTHETIC', engine_param: 'io_239');
$mapper = static fn(string $profile): RelayPayloadMapper => new RelayPayloadMapper(
    $vehicle, array_fill_keys(range(1, 24), true), range(1, 24), 80, 2, 3200, $profile,
    new WialonSignalReader(), new WialonSourceReader()
);
$now = time() - 120;
$raw = static function (array $parameters, int $offset = 0) use ($now): array {
    return ['t' => $now + $offset,
        'pos' => ['x' => 39.2, 'y' => -6.8, 'z' => 10, 's' => 20, 'c' => 90, 'sc' => 8],
        'p' => vtsTestParams($parameters)];
};
$ids = static function (array $mapped): array {
    return array_map('intval', array_column($mapped['payload']['items'], 'activity_id'));
};
$fmb = $mapper('teltonika-fmb');
foreach ([1 => ActivityDetectionPolicy::HASH_ACCEL, 2 => ActivityDetectionPolicy::HASH_BRAKING,
    3 => ActivityDetectionPolicy::HASH_TURNING] as $code => $activity) {
    check(in_array($activity, $ids($fmb->map($raw(['io_253' => $code]), [])), true),
        "FMB manoeuvre $code lost");
}
check(!in_array(ActivityDetectionPolicy::HASH_BRAKING, $ids($fmb->map($raw(['io_253' => 4]), [])), true),
    'Unsupported FMB manoeuvre fabricated an activity');
check($ids($fmb->map($raw(['io_252' => true, 'io_251' => true]), [])) === [1],
    'Boolean FMB toggles were accepted as enums');
check(in_array(ActivityDetectionPolicy::POWER_DISC, $ids($fmb->map($raw(['io_252' => '1']), [])), true)
    && in_array(ActivityDetectionPolicy::EXCESSIVE_IDLE, $ids($fmb->map($raw(['io_251' => 1]), [])), true),
    'FMB power/idle enums lost');
check($ids($mapper('generic')->map($raw(['io_253' => 2, 'io_252' => 1, 'io_251' => 1]), [])) === [1],
    'Generic profile applied FMB enums');

/* Event matching preserves exact/substring, case, whitespace and overlapping-keyword behavior. */
check($detectSource(['event' => 'PANIC']) === ActivityDetectionPolicy::PANIC_DRIVER, 'Event case changed');
check($detectSource(['event' => ' panic ']) === ActivityDetectionPolicy::PANIC_DRIVER, 'Event whitespace changed');
check($detectSource(['event' => 'passenger_panic']) === ActivityDetectionPolicy::PANIC_DRIVER,
    'Substring priority changed (panic before passenger_panic)');
$passenger = $ids($fmb->map($raw(['event' => 'passenger_panic']), []));
check(in_array(ActivityDetectionPolicy::PANIC_PASSENGER, $passenger, true)
    && !in_array(ActivityDetectionPolicy::PANIC_DRIVER, $passenger, true),
    'Exact relay event mapping lost');
$spaced = $ids($fmb->map($raw(['event' => ' passenger_panic ']), []));
check(in_array(ActivityDetectionPolicy::PANIC_PASSENGER, $spaced, true)
    && in_array(ActivityDetectionPolicy::PANIC_DRIVER, $spaced, true),
    'Trimmed exact and untrimmed substring event evidence were collapsed');
check($ids($fmb->map($raw(['event' => 'panic_cleared']), [])) === [1],
    'Unrecognised event substring fabricated a relay activity');
$simultaneous = $ids($fmb->map($raw(['panic' => 1, 'passenger_panic' => 1, 'accident' => 1]), []));
foreach ([ActivityDetectionPolicy::PANIC_DRIVER, ActivityDetectionPolicy::PANIC_PASSENGER,
    ActivityDetectionPolicy::ACCIDENT] as $activity) {
    check(in_array($activity, $simultaneous, true), "Simultaneous activity $activity lost");
}

/* Device thresholds and transitions retain their strict comparison points. */
$status = static fn(array $parameters, array $previous = []): array
    => DeviceStatusPolicy::evaluate($reader->readDeviceStatus($parameters), $previous);
$atThreshold = $status(['pwr_int' => 3.2, 'pwr_ext' => 1.0, 'battery' => 20]);
check($atThreshold['internal_battery_low'] === 0 && $atThreshold['external_power_disconnected'] === 0,
    'Device thresholds became inclusive');
$below = $status(['pwr_int' => 3.199, 'pwr_ext' => 0.999, 'battery' => 19], [
    'is_online' => 1, 'internal_battery_low' => 0,
    'external_power_disconnected' => 0, 'tampered' => 0,
]);
check($below['internal_battery_low'] === 1 && $below['external_power_disconnected'] === 1
    && array_column($below['events'], 'type') === ['BATTERY_LOW', 'POWER_DISCONNECTED'],
    'Device low-status transitions changed');
check($status(['pwr_ext' => 0])['external_power_disconnected'] === 0,
    'Zero external power became a device disconnect');
$initial = $status(['pwr_int' => 3.1]);
check($initial['events'] === [] && $initial['changed'] === false, 'Initial device status created a transition');

/* Hardware-only channels cannot fabricate relay activities; zero voltage alone is not unplug. */
$hardwareOnly = ['io_79' => -460, 'io_80' => -460, 'ho' => -0.5, 'io_104' => 1, 'io_105' => 1,
    'io_233' => 1, 'io_8' => 1, 'io_335' => 1, 'io_123' => 1,
    'pass' => 'DRIVER', 'tco_driver1_id' => 'DRIVER'];
check($ids($fmb->map($raw($hardwareOnly), [])) === [1],
    'Hardware-only channels fabricated an activity');
check($ids($fmb->map($raw(['io_66' => 0, 'io_67' => 0]), [])) === [1],
    'Zero voltage alone fabricated an unplug activity');

/* All activities, simultaneous events, missing-data review and trip/state transitions. */
$events = [1 => '', 2 => 'engine_on', 3 => 'engine_off', 4 => 'speeding', 5 => 'harsh_braking',
    6 => 'harsh_turning', 7 => 'harsh_acceleration', 8 => 'panic', 9 => 'battery_low',
    10 => 'power_disconnected', 11 => 'excessive_idle', 12 => 'accident', 13 => 'passenger_panic',
    14 => 'tamper', 15 => 'black_box', 16 => 'fuel', 17 => 'invalid_scan', 18 => 'engine_start',
    19 => 'engine_stop', 20 => 'boundary_enter', 21 => 'boundary_exit', 22 => 'checkpoint_enter',
    23 => 'checkpoint_exit', 24 => 'ibutton'];
$payloadMapper = new RelayPayloadMapper($vehicle, array_fill_keys(range(1, 24), true), range(1, 24),
    80, 1, 3200, 'teltonika-fmb', new WialonSignalReader(), new WialonSourceReader());
foreach ($events as $activity => $event) {
    $parameters = ['event' => $event, 'fuel_level' => 2100, 'driver_id' => 'D70000127F886501'];
    $message = $raw($parameters);
    if ($activity === ActivityDetectionPolicy::SPEEDING) $message['pos']['s'] = 90;
    $mapped = $payloadMapper->map($message, []);
    check(in_array($activity, $ids($mapped), true), "Activity $activity missing from payload");
    check(LatraPayloadPolicy::validatePayload($mapped['payload']) === [],
        "Activity $activity payload no longer validates");
}
$multi = $payloadMapper->map($raw(['panic' => 1, 'passenger_panic' => 1, 'accident' => 1,
    'checkpoint_enter' => 1, 'fuel_level' => 0]), []);
check(LatraPayloadPolicy::validatePayload($multi['payload']) === [], 'Simultaneous payload no longer validates');
check(array_column($multi['payload']['items'], 'activity_id') === ['1', '8', '12', '13', '16', '22'],
    'Simultaneous activity set or ordering changed');

$missing = $raw([]);
unset($missing['pos']['y']);
rejects(static fn() => $fmb->map($missing, []), 'lat: Coordinate is required');

$off = $fmb->map($raw(['io_239' => 0]), []);
$on = $fmb->map($raw(['io_239' => 1], 1), $off['state']);
check(in_array(ActivityDetectionPolicy::ENGINE_ON, $ids($on), true) && $on['state']['engine_status'] === 'on',
    'Engine-on state transition lost');
$trip = $fmb->map($raw(['io_239' => 0], 61), $on['state']);
$tripItems = array_column($trip['payload']['items'], null, 'activity_id');
check(isset($tripItems['3']) && $trip['state']['engine_status'] === 'off',
    'Engine-off state transition lost');
check(array_key_exists('trip', $trip['state']) && $trip['state']['trip'] === null,
    'Trip accumulator did not close on engine off');

echo "PASS canonical telemetry evidence, decoding, device status, payloads and relay restrictions\n";
