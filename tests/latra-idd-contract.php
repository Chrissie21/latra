<?php
declare(strict_types=1);

/**
 * Contract tests for the reconstructed LATRA / SUMATRA VTS IDD rev 1.14.
 *
 * Proves that the rules encoded in `tests/latra-idd.php` accept every documented
 * activity shape and reject the malformed cases the specification forbids.
 * The final block reports — without failing — where the current application
 * payload differs from the IDD, so those gaps stay visible until they are closed.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/latra-idd.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param string[] $errors */
function hasError(array $errors, string $needle): bool
{
    foreach ($errors as $error) {
        if (str_contains($error, $needle)) {
            return true;
        }
    }
    return false;
}

// 1. Every documented activity produces a conforming request.
foreach (array_keys(latra_idd_activities()) as $activityId) {
    $verdict = latra_idd_validate(latra_idd_sample_request($activityId));
    check($verdict['errors'] === [], "activity $activityId sample rejected: " . implode('; ', $verdict['errors']));
}

// 2. The 3*N rule and the 200 maximum.
check(hasError(latra_idd_validate(latra_idd_sample_request(1, 1))['errors'], '3*N'), 'single item must break 3*N');
check(hasError(latra_idd_validate(latra_idd_sample_request(1, 4))['errors'], '3*N'), 'four items must break 3*N');
check(latra_idd_validate(latra_idd_sample_request(1, 6))['errors'] === [], 'six items must pass 3*N');
check(hasError(latra_idd_validate(latra_idd_sample_request(1, 204))['errors'], 'maximum'), '204 items must exceed the maximum');

// 3. Envelope rules.
$badImei = latra_idd_sample_request();
$badImei['imei'] = 'ABC';
check(hasError(latra_idd_validate($badImei)['errors'], 'imei'), 'non-numeric IMEI must be rejected');

$badType = latra_idd_sample_request();
$badType['type'] = 'vehicle';
check(hasError(latra_idd_validate($badType)['errors'], 'poi'), 'type other than poi must be rejected');

$missingReg = latra_idd_sample_request();
unset($missingReg['vehicle_reg_no']);
check(hasError(latra_idd_validate($missingReg)['errors'], 'vehicle_reg_no'), 'missing registration must be rejected');

$unknown = latra_idd_sample_request();
$unknown['unexpected'] = 'value';
$unknownVerdict = latra_idd_validate($unknown);
check($unknownVerdict['errors'] === [] && count($unknownVerdict['warnings']) === 1, 'unknown top-level field is a warning, not an error');

// 4. Item field rules.
$missingLat = latra_idd_sample_request();
unset($missingLat['items'][0]['latitude']);
check(hasError(latra_idd_validate($missingLat)['errors'], 'latitude'), 'missing latitude must be rejected');

$numericLat = latra_idd_sample_request(1, 3, ['latitude' => -6.79124]);
check(hasError(latra_idd_validate($numericLat)['errors'], 'must be a string'), 'numeric latitude must fail the strict string rule');
check(latra_idd_validate($numericLat, ['strict_strings' => false])['errors'] === [], 'numeric latitude must pass in lenient mode');

$rangeLat = latra_idd_sample_request(1, 3, ['latitude' => '100']);
check(hasError(latra_idd_validate($rangeLat)['errors'], 'out of range'), 'latitude 100 must be out of range');

$badD2d3 = latra_idd_sample_request(1, 3, ['d2d3' => '4']);
check(hasError(latra_idd_validate($badD2d3)['errors'], 'd2d3'), 'd2d3 4 must be rejected');

$badActivity = latra_idd_sample_request(1, 3, ['activity_id' => '25']);
check(hasError(latra_idd_validate($badActivity)['errors'], 'activity_id'), 'activity 25 must be rejected');

$formattedTime = latra_idd_sample_request(1, 3, ['timestamp' => '2024-01-01 00:00:00']);
check(hasError(latra_idd_validate($formattedTime)['errors'], 'epoch milliseconds'), 'formatted timestamp must be rejected');

$secondsTime = latra_idd_sample_request(1, 3, ['timestamp' => '1541603095']);
check(hasError(latra_idd_validate($secondsTime)['errors'], 'epoch milliseconds'), 'epoch seconds must be rejected');

$badBearing = latra_idd_sample_request(1, 3, ['bearing' => '400']);
check(hasError(latra_idd_validate($badBearing)['errors'], 'bearing'), 'bearing 400 must be out of range');

$hugeMgs = latra_idd_sample_request(1, 3, ['MGS_ID' => '20000000000000000000']);
check(hasError(latra_idd_validate($hugeMgs)['errors'], 'MGS_ID'), 'MGS_ID beyond 64 bits must be rejected');

$maxMgs = latra_idd_sample_request(1, 3, ['MGS_ID' => '18446744073709551615']);
check(latra_idd_validate($maxMgs)['errors'] === [], 'maximum unsigned 64-bit MGS_ID must be accepted');

// 5. addon_info rules.
$missingAddon = latra_idd_sample_request(2);
unset($missingAddon['items'][0]['addon_info']);
check(latra_idd_validate($missingAddon)['errors']===[], 'optional addon_info may be absent');

$partialAddon = latra_idd_sample_request(2);
unset($partialAddon['items'][0]['addon_info']['idleTime']);
check(latra_idd_validate($partialAddon)['errors']===[], 'optional addon fields may be absent');

$badHex = latra_idd_sample_request(24);
$badHex['items'][0]['addon_info']['v_driver_identification_no'] = 'XYZ';
check(hasError(latra_idd_validate($badHex)['errors'], '16 hex digits'), 'non-hex driver number must be rejected');

$addonOnMovement = latra_idd_sample_request(1);
$addonOnMovement['items'][0]['addon_info'] = ['idleTime' => '1'];
check(hasError(latra_idd_validate($addonOnMovement)['errors'], 'must be ""'), 'addon_info on activity 1 must be rejected');

$absentAddon = latra_idd_sample_request(1);
unset($absentAddon['items'][0]['addon_info']);
$absentVerdict = latra_idd_validate($absentAddon);
check($absentVerdict['errors'] === [] && hasError($absentVerdict['warnings'], 'addon_info is absent'),
    'absent addon_info is a warning on activity 1');

// 6. fuel_info rules.
$missingFuel = latra_idd_sample_request(16);
unset($missingFuel['items'][0]['fuel_info']);
check(hasError(latra_idd_validate($missingFuel)['errors'], 'fuel_info is required'), 'activity 16 without fuel_info must be rejected');

$badSignal = latra_idd_sample_request(16);
$badSignal['items'][0]['fuel_info']['signalLevel'] = '150';
check(hasError(latra_idd_validate($badSignal)['errors'], 'signalLevel'), 'signalLevel 150 must be rejected');

$badFault = latra_idd_sample_request(16);
$badFault['items'][0]['fuel_info']['hardFault'] = '12';
check(hasError(latra_idd_validate($badFault)['errors'], 'hardFault'), 'hardFault 12 must be rejected');

$fuelOnMovement = latra_idd_sample_request(1);
$fuelOnMovement['items'][0]['fuel_info'] = ['validFlag' => '0'];
check(hasError(latra_idd_validate($fuelOnMovement)['warnings'], 'fuel_info present'), 'fuel_info on activity 1 is a warning');

// 7. Response body shape.
$ok = latra_idd_response(true);
check($ok['status'] === '1' && is_string($ok['timestamp']) && strlen($ok['timestamp']) === 13,
    'success response must be status "1" with epoch-ms timestamp');
check(!isset($ok['message']), 'success response must not carry a message');
$failed = latra_idd_response(false, 'reason');
check($failed['status'] === '0' && $failed['message'] === 'reason', 'failure response must be status "0" with a message');

echo "PASS IDD rev 1.14 contract accepts all 24 activity shapes and rejects malformed payloads\n";

// 8. The application's own payload must satisfy the IDD item rules.
//
// This block used to be informational. It is now a hard assertion so the gaps that
// were closed cannot silently return. The `3*N` batch rule is the single exception:
// it constrains the assembled request, not one item. Durable delivery now enforces
// it; tests/latra-vts-batching.php covers assembly, retention and recovery.
require __DIR__ . '/../includes/autoload.php';

$vehicle = new Latra\Domain\Collection\Vehicle(vehicle_reg_no: 'T123ABC', imei: '100000000004000');
$sourceDecoder = new Latra\Infrastructure\Wialon\WialonSourceReader();
$message = [
    'wialon_msg_time' => 1541603095967, 'lat' => -6.79124, 'lng' => 39.1, 'altitude' => 21,
    'speed' => 80, 'bearing' => 150, 'satellite_count' => 9, 'hdop' => 1, 'd2d3' => 3,
    'rssi' => 0, 'lac' => 123, 'cell_id' => 12345, 'mgs_id' => 12345, 'mcc' => 635,
];
$current = Latra\Domain\Ingestion\LatraPayloadPolicy::buildPayload(
    $vehicle, Latra\Domain\Ingestion\TelemetryMessage::fromStored($message), 1,
)->toWire();
$itemVerdict = latra_idd_validate($current, ['three_n' => false]);
check($itemVerdict['errors'] === [],
    'application payload must satisfy the IDD item rules: ' . implode('; ', $itemVerdict['errors']));
check($itemVerdict['warnings'] === [],
    'application payload must not rely on IDD warnings: ' . implode('; ', $itemVerdict['warnings']));
check(!isset($current['items'][0]['vertical_speed']) && $current['items'][0]['odometer'] === '0'
    && $current['items'][0]['addon_info'] === '',
    'absent measurements must be neutral values and addon_info must be "" on activity 1');

$batchVerdict = latra_idd_validate($current);
check($batchVerdict['errors'] === ['items must contain 3*N data points (got 1)'],
    'item validation must leave batching to the durable delivery stage: ' . implode('; ', $batchVerdict['errors']));

// A message carrying nothing but a fix must still produce a conforming item.
$sparse = Latra\Domain\Ingestion\LatraPayloadPolicy::buildPayload(
    $vehicle,
    Latra\Domain\Ingestion\TelemetryMessage::fromStored(
        ['wialon_msg_time' => 1541603095967, 'lat' => -6.79124, 'lng' => 39.1],
    ),
    1,
)->toWire();
$sparseVerdict = latra_idd_validate($sparse, ['three_n' => false]);
check($sparseVerdict['errors'] !== [],
    'a fix-only message must still produce a conforming item: ' . implode('; ', $sparseVerdict['errors']));

// Fuel units: millimetres as reported, tank temperature scaled by ten, no invented signal.
$fuel = Latra\Domain\Ingestion\LatraPayloadPolicy::buildFuelInfo($sourceDecoder->decodeFuelEvidence(
    ['fuel_unit'=>'mm','fuel_level'=>2100,'rt_fuel'=>2134,'fuel_signal'=>75,'tank_temp'=>31.2,'fuel_valid_flag'=>0,'fuel_soft_status'=>0,'fuel_hard_fault'=>0],
));
check($fuel['fuelLevel'] === '2100' && $fuel['rtFuelLevel'] === '2134' && $fuel['tankTemp'] === '312'
    && $fuel['signalLevel'] === '75' && $fuel['validFlag'] === '0',
    'fuel units must follow the IDD: millimetres, Celsius x 10, measured signal');
$noSignal = Latra\Domain\Ingestion\LatraPayloadPolicy::buildFuelInfo(
    $sourceDecoder->decodeFuelEvidence(['fuel_level' => 2100]),
);
check($noSignal['signalLevel'] === null && $noSignal['validFlag'] === null,
    'a missing signalLevel must not be invented as 75');
$litres = Latra\Domain\Ingestion\LatraPayloadPolicy::buildFuelInfo(
    $sourceDecoder->decodeFuelEvidence(['fuel_level' => 50, 'unit' => 'l']),
);
check($litres['validFlag'] === null && $litres['fuelLevel'] === null,
    'a litre reading must not be labelled as millimetres');
$fuelPayload = Latra\Domain\Ingestion\LatraPayloadPolicy::buildPayload(
    $vehicle, Latra\Domain\Ingestion\TelemetryMessage::fromStored($message), 16, [], $fuel,
)->toWire();
check(latra_idd_validate($fuelPayload, ['three_n' => false])['errors'] === [],
    'activity 16 payload must conform');

// d2d3 is a reported fix mode, never inferred from altitude or from the input-data flag.
check($sourceDecoder->decodeUnitData(['pos' => ['z' => 210, 'f' => 2]])?->fixMode === null,
    'altitude or the input-data flag must not claim a 3D fix');
check($sourceDecoder->decodeUnitData(['pos' => ['satellite_mode' => '3']])?->fixMode === 3
    && $sourceDecoder->decodeUnitData(['p' => ['d2d3' => '2']])?->fixMode === 2,
    'a reported fix mode must be honoured');

echo "PASS application payload conforms to the IDD item rules (3*N enforced by durable delivery)\n";
