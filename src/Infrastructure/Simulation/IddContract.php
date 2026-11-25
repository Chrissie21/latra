<?php
declare(strict_types=1);

/**
 * LATRA / SUMATRA VTS Web API — IDD rev 1.14 contract, encoded once.
 *
 * Source: `LATRA VTS API SPECIFICATION.md` (transcribed from
 * `IDD_GPS_Location_rev1.14-5-1-1-3-1-3.pdf`, BSMART, 2024-04-19).
 *
 * This file is a helper, not a suite: it is required by the mock receiver and by
 * the contract tests so both apply exactly the same rules. It performs no I/O.
 *
 * Deliberate strictness choices, all traceable to the IDD:
 *  - every scalar item value is a JSON string in every sample, so strings are required;
 *  - `timestamp` is epoch milliseconds (Note 2), not a formatted date;
 *  - `items` must contain a multiple of three entries (3*N BGate rule), 200 maximum;
 *  - `addon_info` is optional and must be `""` for activities without addon data
 *    ("put "" if not match any activity");
 *  - `fuel_info` is required for activity 16 only.
 */

/** @return array<int,string> activity_id => name */
function latra_idd_activities(): array
{
    return [
        1 => 'Movement/Logging',
        2 => 'Engine ON',
        3 => 'Engine OFF',
        4 => 'Speeding',
        5 => 'Hash Braking',
        6 => 'Hash Turning',
        7 => 'Hash Acceleration',
        8 => 'Panic Button (Driver)',
        9 => 'Internal Battery Low',
        10 => 'External Power Disconnected',
        11 => 'Excessive Idle',
        12 => 'Accident',
        13 => 'Panic Button (Passenger)',
        14 => 'Device Tempering',
        15 => 'Black Box Data Logging',
        16 => 'Fuel data report',
        17 => 'Invalid Scan',
        18 => 'Engine Start',
        19 => 'Engine Stop',
        20 => 'Enter Boundary',
        21 => 'Leave Boundary',
        22 => 'Enter Checkpoint',
        23 => 'Leave Checkpoint',
        24 => 'Ibutton Scan (Regular)',
    ];
}

/** Activities whose report must carry addon_info. */
function latra_idd_addon_activities(): array
{
    return [2, 3, 9, 10, 17, 24];
}

/** Required addon_info keys per activity. */
function latra_idd_addon_keys(int $activityId): array
{
    return match ($activityId) {
        2 => ['idleTime', 'v_driver_identification_no'],
        3 => ['distance_travelled', 'trip_duration', 'avgSpeed', 'maxSpeed'],
        9, 10 => ['ext_power_voltage', 'int_battery_voltage'],
        17, 24 => ['v_driver_identification_no'],
        default => [],
    };
}

/** Hardware fault codes from Note 1 / Table 2. */
function latra_idd_hardware_faults(): array
{
    return [0 => 'No Fault', 1 => 'Probe Cable Failed', 2 => 'Probe Cable Intermediate',
        3 => 'Reserved', 4 => 'Sensor Coupling Layer abnormal', 5 => 'Detected fuel level zero',
        6 => 'Reserved', 7 => 'Reserved', 8 => 'Reserved', 9 => 'Reserved'];
}

/** Fields every item must contain, in IDD order. */
function latra_idd_required_item_fields(): array
{
    return ['latitude', 'longitude', 'altitude', 'timestamp', 'horizontal_speed',
        'bearing', 'satellite_count', 'HDOP', 'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC',
        'activity_id'];
}

/**
 * Validate one request body against the IDD.
 *
 * @param array $payload decoded JSON body
 * @param array $options three_n (bool, default true), strict_strings (bool, default true),
 *                       max_items (int, default 200)
 * @return array{errors:string[],warnings:string[]}
 */
function latra_idd_validate(array $payload, array $options = []): array
{
    $threeN = (bool) ($options['three_n'] ?? true);
    $strictStrings = (bool) ($options['strict_strings'] ?? true);
    $maxItems = (int) ($options['max_items'] ?? 200);
    $errors = [];
    $warnings = [];

    $known = ['vehicle_reg_no', 'type', 'imei', 'items'];
    foreach (array_keys($payload) as $key) {
        if (!in_array($key, $known, true)) {
            $warnings[] = "unknown top-level field: $key";
        }
    }

    if (!isset($payload['vehicle_reg_no']) || !is_string($payload['vehicle_reg_no'])
        || trim($payload['vehicle_reg_no']) === '') {
        $errors[] = 'vehicle_reg_no is required and must be a non-empty string';
    }
    if (($payload['type'] ?? null) !== 'poi') {
        $errors[] = 'type must be "poi"';
    }
    if (!isset($payload['imei']) || !is_string($payload['imei'])
        || preg_match('/^\d{8,20}$/', $payload['imei']) !== 1) {
        $errors[] = 'imei is required and must be 8-20 digits';
    }

    $items = $payload['items'] ?? null;
    if (!is_array($items) || $items === [] || !array_is_list($items)) {
        $errors[] = 'items must be a non-empty JSON array';
        return ['errors' => $errors, 'warnings' => $warnings];
    }
    if (count($items) > $maxItems) {
        $errors[] = 'items exceeds the maximum of ' . $maxItems;
    }
    if ($threeN && count($items) % 3 !== 0) {
        $errors[] = 'items must contain 3*N data points (got ' . count($items) . ')';
    }

    foreach ($items as $index => $item) {
        $path = "items[$index]";
        if (!is_array($item)) {
            $errors[] = "$path must be an object";
            continue;
        }

        foreach (latra_idd_required_item_fields() as $field) {
            if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                $errors[] = "$path.$field is required";
            }
        }

        if ($strictStrings) {
            foreach (latra_idd_required_item_fields() as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null
                    && !is_string($item[$field])) {
                    $errors[] = "$path.$field must be a string";
                }
            }
        }

        $number = static function (string $field) use ($item, $path, &$errors): ?float {
            $value = $item[$field] ?? null;
            if ($value === null || $value === '' || !is_numeric($value)) {
                $errors[] = "$path.$field must be numeric";
                return null;
            }
            return (float) $value;
        };

        $latitude = $number('latitude');
        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
            $errors[] = "$path.latitude out of range";
        }
        $longitude = $number('longitude');
        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
            $errors[] = "$path.longitude out of range";
        }
        $number('altitude');
        $number('horizontal_speed');
        if(isset($item['vertical_speed'])) $number('vertical_speed');
        $bearing = $number('bearing');
        if ($bearing !== null && ($bearing < 0 || $bearing > 360)) {
            $errors[] = "$path.bearing out of range";
        }
        $number('satellite_count');
        $number('HDOP');
        $number('RSSI');
        $number('LAC');
        $number('Cell_ID');
        $number('MCC');

        foreach (['altitude','satellite_count','RSSI','LAC','Cell_ID','MCC','activity_id','timestamp'] as $field) {
            if (isset($item[$field]) && (!is_scalar($item[$field]) || !preg_match('/^-?\d+$/D',(string)$item[$field]))) {
                $errors[] = "$path.$field must be an integer";
            }
        }
        $timestamp = $item['timestamp'] ?? null;
        if ($timestamp !== null && $timestamp !== '') {
            if (!is_numeric($timestamp)) {
                $errors[] = "$path.timestamp must be epoch milliseconds";
            } elseif ((float) $timestamp < 946684800000) {
                $errors[] = "$path.timestamp is not epoch milliseconds (before 2000-01-01)";
            }
        }

        $d2d3 = $item['d2d3'] ?? null;
        if ($d2d3 !== null && $d2d3 !== '' && !in_array((string) $d2d3, ['2', '3'], true)) {
            $errors[] = "$path.d2d3 must be 2 or 3";
        }

        $activityId = $item['activity_id'] ?? null;
        if ($activityId !== null && $activityId !== '') {
            if (!is_numeric($activityId) || !isset(latra_idd_activities()[(int) $activityId])) {
                $errors[] = "$path.activity_id must be 1-24";
            }
        }
        $activity = is_numeric($activityId) ? (int) $activityId : 0;

        $mgsId = $item['MGS_ID'] ?? null;
        if ($mgsId !== null && $mgsId !== '') {
            $digits = is_string($mgsId) ? ltrim($mgsId, '0') : '';
            if (!is_string($mgsId) || !ctype_digit($mgsId) || strlen($digits)>20
                || (strlen($digits)===20 && strcmp($digits,'18446744073709551615')>0)) {
                $errors[] = "$path.MGS_ID must be an unsigned 64-bit integer";
            }
        }

        if (array_key_exists('odometer', $item) && $item['odometer'] !== null
            && $item['odometer'] !== '' && !is_numeric($item['odometer'])) {
            $errors[] = "$path.odometer must be numeric";
        }

        // addon_info: required for listed activities, "" otherwise.
        $addon = $item['addon_info'] ?? null;
        if(in_array($activity,latra_idd_addon_activities(),true)) {
            if($addon!==null && $addon!=='' && !is_array($addon)) $errors[]="$path.addon_info must be an object when supplied";
            if(is_array($addon) && isset($addon['v_driver_identification_no'])
                && (!is_string($addon['v_driver_identification_no']) || !preg_match('/^[0-9A-Fa-f]{16}$/D',$addon['v_driver_identification_no']))) {
                $errors[]="$path.addon_info.v_driver_identification_no must be 16 hex digits";
            }
        } elseif(!array_key_exists('addon_info',$item)) {
            $warnings[]="$path.addon_info is absent; the IDD asks for \"\" when no activity data applies";
        } elseif($addon!==null && $addon!=='') $errors[]="$path.addon_info must be \"\" when activity $activity has no addon data";

        // fuel_info: required for activity 16, not expected otherwise.
        $fuel = $item['fuel_info'] ?? null;
        if ($activity === 16) {
            if (!is_array($fuel) || $fuel === []) {
                $errors[] = "$path.fuel_info is required for activity 16";
            } else {
                foreach (['validFlag', 'signalLevel', 'softStatus', 'hardFault',
                             'fuelLevel', 'rtFuelLevel', 'tankTemp', 'channel'] as $key) {
                    if (!array_key_exists($key, $fuel) || $fuel[$key] === null || $fuel[$key] === '') {
                        $errors[] = "$path.fuel_info.$key is required for activity 16";
                    }
                }
                foreach (['validFlag','signalLevel','softStatus','hardFault','fuelLevel','rtFuelLevel','tankTemp','channel'] as $key) {
                    if (!is_string($fuel[$key] ?? null) || !preg_match('/^-?\d+$/D', $fuel[$key])) {
                        $errors[] = "$path.fuel_info.$key must be an integer string";
                    }
                }
                if (isset($fuel['signalLevel']) && is_numeric($fuel['signalLevel'])
                    && ((int) $fuel['signalLevel'] < 0 || (int) $fuel['signalLevel'] > 99)) {
                    $errors[] = "$path.fuel_info.signalLevel must be 0-99";
                }
                if (isset($fuel['hardFault']) && is_numeric($fuel['hardFault'])
                    && !isset(latra_idd_hardware_faults()[(int) $fuel['hardFault']])) {
                    $errors[] = "$path.fuel_info.hardFault must be 0-9";
                }
            }
        } elseif ($fuel !== null && $fuel !== '' && $fuel !== []) {
            $warnings[] = "$path.fuel_info present for activity $activity";
        }
    }

    return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
}

/**
 * The documented response body: status "1" successful / "0" failed, epoch-ms timestamp.
 *
 * @return array{status:string,timestamp:string,message?:string}
 */
function latra_idd_response(bool $ok, ?string $message = null): array
{
    $body = [
        'status' => $ok ? '1' : '0',
        'timestamp' => (string) (int) round(microtime(true) * 1000),
    ];
    if (!$ok && $message !== null && $message !== '') {
        $body['message'] = $message;
    }
    return $body;
}

/**
 * A minimal conforming item for tests and the mock: every required field present.
 *
 * @return array<string,string>
 */
function latra_idd_sample_item(int $activityId = 1, array $overrides = []): array
{
    $item = [
        'latitude' => '-6.79124',
        'longitude' => '39.1',
        'altitude' => '21',
        'timestamp' => '1541603095967',
        'horizontal_speed' => '80',
        'vertical_speed' => '0',
        'bearing' => '150',
        'satellite_count' => '9',
        'HDOP' => '1',
        'd2d3' => '3',
        'RSSI' => '0',
        'LAC' => '123',
        'Cell_ID' => '12345',
        'MGS_ID' => '12345',
        'MCC' => '635',
        'activity_id' => (string) $activityId,
    ];
    if (in_array($activityId, latra_idd_addon_activities(), true)) {
        $item['addon_info'] = match ($activityId) {
            2 => ['idleTime' => '1231', 'v_driver_identification_no' => 'D70000127F886501'],
            3 => ['distance_travelled' => '505.2', 'trip_duration' => '400',
                'avgSpeed' => '60', 'maxSpeed' => '85'],
            9, 10 => ['ext_power_voltage' => '24.22', 'int_battery_voltage' => '3.89'],
            17, 24 => ['v_driver_identification_no' => 'FFFFFFFFFFFFFFFF'],
            default => [],
        };
    } else {
        $item['addon_info'] = '';
    }
    if ($activityId === 16) {
        $item['fuel_info'] = ['validFlag' => '0', 'signalLevel' => '75', 'softStatus' => '0',
            'hardFault' => '0', 'fuelLevel' => '2100', 'rtFuelLevel' => '2134',
            'tankTemp' => '312', 'channel' => '1'];
    }
    return array_merge($item, $overrides);
}

/**
 * A conforming request body. `count` items are produced; the default is 3 to satisfy 3*N.
 */
function latra_idd_sample_request(int $activityId = 1, int $count = 3, array $itemOverrides = []): array
{
    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $items[] = latra_idd_sample_item($activityId, $itemOverrides);
    }
    return [
        'vehicle_reg_no' => 'T123ABC',
        'type' => 'poi',
        'imei' => '100000000004000',
        'items' => $items,
    ];
}
