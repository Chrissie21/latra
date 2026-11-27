<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

use Latra\Domain\Collection\Vehicle;

final class LatraPayloadPolicy
{
    public const ACTIVITY_MOVEMENT = 1;
    public const ACTIVITY_ENGINE_ON = 2;
    public const ACTIVITY_ENGINE_OFF = 3;
    public const ACTIVITY_SPEEDING = 4;
    public const ACTIVITY_HASH_BRAKING = 5;
    public const ACTIVITY_HASH_TURNING = 6;
    public const ACTIVITY_HASH_ACCEL = 7;
    public const ACTIVITY_PANIC_DRIVER = 8;
    public const ACTIVITY_BATTERY_LOW = 9;
    public const ACTIVITY_POWER_DISCONNECT = 10;
    public const ACTIVITY_EXCESSIVE_IDLE = 11;
    public const ACTIVITY_ACCIDENT = 12;
    public const ACTIVITY_PANIC_PASSENGER = 13;
    public const ACTIVITY_TAMPERING = 14;
    public const ACTIVITY_BLACK_BOX = 15;
    public const ACTIVITY_FUEL_REPORT = 16;
    public const ACTIVITY_INVALID_SCAN = 17;
    public const ACTIVITY_ENGINE_START = 18;
    public const ACTIVITY_ENGINE_STOP = 19;
    public const ACTIVITY_ENTER_BOUNDARY = 20;
    public const ACTIVITY_LEAVE_BOUNDARY = 21;
    public const ACTIVITY_ENTER_CHECKPOINT = 22;
    public const ACTIVITY_LEAVE_CHECKPOINT = 23;
    public const ACTIVITY_IBUTTON_REGULAR = 24;

    public const ACTIVITIES = [
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
        14 => 'Device Tampering',
        15 => 'Black Box Data Logging',
        16 => 'Fuel Data Report',
        17 => 'Invalid Scan',
        18 => 'Engine Start',
        19 => 'Engine Stop',
        20 => 'Enter Boundary',
        21 => 'Leave Boundary',
        22 => 'Enter Checkpoint',
        23 => 'Leave Checkpoint',
        24 => 'iButton Scan (Regular)',
    ];

    public const HARDWARE_FAULTS = [
        0 => 'No Fault',
        1 => 'Probe Cable Failed (connection fail)',
        2 => 'Probe Cable Intermediate (connection lose)',
        3 => 'Reserved',
        4 => 'Sensor Coupling Layer abnormal',
        5 => 'Detected fuel level zero',
        6 => 'Reserved',
        7 => 'Reserved',
        8 => 'Reserved',
        9 => 'Reserved',
    ];

    /**
     * Fields the IDD requires on every item, in the documented order.
     *
     * @return string[]
     */
    public const REQUIRED_ITEM_FIELDS = [
        'latitude', 'longitude', 'altitude', 'timestamp', 'horizontal_speed',
        'bearing', 'satellite_count', 'HDOP', 'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC',
        'activity_id',
    ];

    /** Activities with documented optional addon_info shapes (IDD §3.1.1). */
    public const ADDON_ACTIVITIES = [2, 3, 9, 10, 17, 24];

    /** Documented addon_info keys per activity, when supplied. */
    public const ADDON_KEYS = [
        2 => ['idleTime', 'v_driver_identification_no'],
        3 => ['distance_travelled', 'trip_duration', 'avgSpeed', 'maxSpeed'],
        9 => ['ext_power_voltage', 'int_battery_voltage'],
        10 => ['ext_power_voltage', 'int_battery_voltage'],
        17 => ['v_driver_identification_no'],
        24 => ['v_driver_identification_no'],
    ];

    /** Required `fuel_info` keys (activity 16). */
    public const FUEL_KEYS = [
        'validFlag', 'signalLevel', 'softStatus', 'hardFault',
        'fuelLevel', 'rtFuelLevel', 'tankTemp', 'channel',
    ];

    /** Numeric item fields checked for presence and numeric form, in IDD order. */
    private const NUMERIC_ITEM_FIELDS = [
        'altitude', 'horizontal_speed', 'bearing', 'satellite_count', 'HDOP',
        'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC',
    ];

    /** Missing required source values remain null so validation retains them for review. */
    public static function buildPayload(Vehicle $vehicle, TelemetryMessage $message, int $activityId,
        array $addonInfo = [], array $fuelInfo = []): LatraPayload
    {
        $fields = [
            'latitude' => self::format($message->latitude, 'string'),
            'longitude' => self::format($message->longitude, 'string'),
            'timestamp' => self::toEpochMs($message->messageTime),
            'odometer' => self::format($message->odometerKm, 'string', '0'),
            'altitude' => self::format($message->altitudeM, 'string'),
            'horizontal_speed' => self::format($message->speedKmh, 'string'),
            'bearing' => self::format($message->bearingDeg, 'string'),
            'satellite_count' => self::format($message->satelliteCount, 'string'),
            'HDOP' => self::format($message->hdop, 'string'),
            'd2d3' => self::format($message->fixMode, 'string'),
            'RSSI' => self::format($message->rssi, 'string'),
            'LAC' => self::format($message->lac, 'string'),
            'Cell_ID' => self::format($message->cellId, 'string'),
            'MGS_ID' => self::format($message->messageId, 'string'),
            'MCC' => self::format($message->mcc, 'string'),
            // Examples include vertical_speed, but the required-field table does not require it.
            'vertical_speed' => self::format($message->verticalSpeedMps, 'string'),
        ];
        $item = new LatraItem(
            activityId: (string) $activityId,
            fields: $fields,
            addon: in_array($activityId, self::ADDON_ACTIVITIES, true) && $addonInfo ? $addonInfo : null,
            fuel: $activityId === self::ACTIVITY_FUEL_REPORT ? ($fuelInfo ?: null) : null,
        );
        return new LatraPayload($vehicle->vehicle_reg_no, $vehicle->imei, [$item]);
    }

    /** addon_info is optional. Only supplied measurements or documented sentinels are included. */
    public static function buildAddonInfo(int $activityId, ?TelemetryMessage $message = null,
        array $customData = []): ?array
    {
        $message ??= new TelemetryMessage();
        $driver = $customData['driverId'] ?? $message->driverId;
        $values = match ($activityId) {
            2 => ['idleTime' => $customData['idleTime'] ?? $message->idleTimeSeconds,
                'v_driver_identification_no' => $driver],
            3 => ['distance_travelled' => $customData['distance'] ?? null,
                'trip_duration' => $customData['duration'] ?? null,
                'avgSpeed' => $customData['avgSpeed'] ?? null,
                'maxSpeed' => $customData['maxSpeed'] ?? null],
            9, 10 => ['ext_power_voltage' => $customData['extVolt'] ?? $message->externalPowerVolts,
                'int_battery_voltage' => $customData['intVolt'] ?? $message->internalBatteryVolts],
            17, 24 => ['v_driver_identification_no' => $driver ?? 'FFFFFFFFFFFFFFFF'],
            default => [],
        };
        $output = [];
        foreach ($values as $key => $value) {
            $formatted = self::format($value, 'string');
            if ($formatted !== null) {
                $output[$key] = $formatted;
            }
        }
        return $output ?: null;
    }

    public const MILLIMETRE_UNITS=['mm','millimetre','millimetres','millimeter','millimeters'];

    /** Fuel data is required for activity 16. No sensor values or healthy statuses are invented. */
    public static function buildFuelInfo(?FuelEvidence $evidence = null, array $customData = []): array
    {
        $evidence ??= new FuelEvidence();
        $unit = strtolower(trim((string) ($customData['unit'] ?? $evidence->unit ?? '')));
        $mm = in_array($unit, self::MILLIMETRE_UNITS, true);
        $level = $mm
            ? self::millimetres($customData['fuelLevel'] ?? $evidence->level)
            : null;
        $rt = $mm
            ? self::millimetres($customData['rtFuelLevel'] ?? $evidence->rtLevel)
            : null;
        return [
            'validFlag' => self::format($customData['validFlag'] ?? $evidence->validFlag, 'string'),
            'signalLevel' => self::format(
                self::boundedInt($customData['signalLevel'] ?? $evidence->signalLevel, 0, 99),
                'string',
            ),
            'softStatus' => self::format($customData['softStatus'] ?? $evidence->softStatus, 'string'),
            'hardFault' => self::format($customData['hardFault'] ?? $evidence->hardFault, 'string'),
            'fuelLevel' => self::format($level, 'string'),
            'rtFuelLevel' => self::format($rt, 'string'),
            'tankTemp' => self::format(
                $customData['tankTemp'] ?? self::tenths($evidence->tankTempC),
                'string',
            ),
            // The IDD explicitly specifies compartment default 1.
            'channel' => self::format($customData['channel'] ?? $evidence->channel, 'string', '1'),
        ];
    }

    public static function toEpochMs(mixed $timestamp): string
    {
        if (is_numeric($timestamp)) {
            if (strlen((string) $timestamp) > 10) {
                return (string) $timestamp;
            }
            return (string) ((int) $timestamp * 1000);
        }
        if (is_string($timestamp)) {
            $epoch = strtotime($timestamp);
            return $epoch ? (string) ($epoch * 1000) : '';
        }
        return '';
    }

    public static function activityRequiresAddon(int $activityId): bool
    {
        return false; // The IDD marks addon_info optional for every activity.
    }

    public static function getActivityName(int $activityId): string
    {
        return self::ACTIVITIES[$activityId] ?? 'Unknown';
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $latitudeDelta = deg2rad($lat2 - $lat1);
        $longitudeDelta = deg2rad($lng2 - $lng1);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;
        return 2 * $earthRadius * asin(sqrt($a));
    }

    /**
     * Validates one request body against the IDD item rules.
     *
     * The `3*N` batch rule is deliberately not enforced here: this method is used
     * to reject a single mapped item before it is queued, and the request-level
     * rule belongs to the transport that assembles batches.
     *
     * @return string[] ordered error messages
     */
    public static function validatePayload(array $payload): array
    {
        $errors = [];
        if (array_key_exists('__relay_batch_v1', $payload)) $errors[] = 'Reserved relay batch metadata';
        if (empty($payload['vehicle_reg_no'])) {
            $errors[] = 'vehicle_reg_no is required';
        }
        if (!is_string($payload['imei'] ?? null) || !preg_match('/^\d{8,20}$/', $payload['imei'])) {
            $errors[] = 'imei is required';
        }
        if (empty($payload['items']) || !is_array($payload['items'])) {
            $errors[] = 'items array is required';
            return $errors;
        }
        if (!array_is_list($payload['items']) || count($payload['items']) > 200) $errors[] = 'items must be a list of at most 200';
        foreach ($payload['items'] as $index => $item) {
            $path = "items[$index]";
            if (!is_array($item)) {
                $errors[] = "$path must be an object";
                continue;
            }
            foreach (['latitude', 'longitude', 'timestamp', 'activity_id'] as $field) {
                if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                    $errors[] = "$path.$field is required";
                }
            }
            foreach (self::NUMERIC_ITEM_FIELDS as $field) {
                if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                    $errors[] = "$path.$field is required";
                } elseif (!is_numeric($item[$field])) {
                    $errors[] = "$path.$field must be numeric";
                }
            }
            if (($payload['type'] ?? null) !== 'poi') $errors[] = 'type must be poi';
            foreach (self::REQUIRED_ITEM_FIELDS as $field) {
                if (!is_string($item[$field] ?? null) || !is_numeric($item[$field])) {
                    $errors[] = "$path.$field must be a numeric string";
                }
            }
            foreach (['latitude'=>[-90,90], 'longitude'=>[-180,180]] as $field=>[$min,$max]) {
                if (isset($item[$field]) && is_numeric($item[$field])
                    && ((float)$item[$field] < $min || (float)$item[$field] > $max)) $errors[] = "$path.$field out of range";
            }
            foreach (['altitude','satellite_count','RSSI','LAC','Cell_ID','MCC','activity_id','timestamp'] as $field) {
                if (isset($item[$field]) && (!is_string($item[$field]) || !preg_match('/^-?\d+$/D',$item[$field]))) {
                    $errors[] = "$path.$field must be an integer string";
                }
            }
            $mgs = $item['MGS_ID'] ?? null;
            if (!is_string($mgs) || !ctype_digit($mgs) || strlen(ltrim($mgs,'0')) > 20
                || (strlen(ltrim($mgs,'0')) === 20 && strcmp(ltrim($mgs,'0'),'18446744073709551615') > 0)) {
                $errors[] = "$path.MGS_ID must be an unsigned 64-bit integer";
            }
            $activityId = (int) ($item['activity_id'] ?? 0);
            if (!isset(self::ACTIVITIES[$activityId])) {
                $errors[] = "$path.activity_id must be 1-24";
            }
            if (isset($item['d2d3']) && $item['d2d3'] !== ''
                && !in_array((string) $item['d2d3'], ['2', '3'], true)) {
                $errors[] = "$path.d2d3 must be 2 or 3";
            }
            if (isset($item['bearing']) && is_numeric($item['bearing'])
                && ((float) $item['bearing'] < 0 || (float) $item['bearing'] > 360)) {
                $errors[] = "$path.bearing out of range";
            }
            if (isset($item['timestamp']) && is_numeric($item['timestamp']) && (float) $item['timestamp'] < 946684800000) {
                $errors[] = "$path.timestamp must be epoch milliseconds";
            }

            $addon = $item['addon_info'] ?? null;
            if (in_array($activityId,self::ADDON_ACTIVITIES,true)) {
                if ($addon!==null && $addon!=='' && !is_array($addon)) $errors[]="$path.addon_info must be an object when supplied";
                if (is_array($addon)) {
                    foreach($addon as $key=>$value) {
                        if($value===null || $value==='') $errors[]="$path.addon_info.$key is missing";
                    }
                    if(isset($addon['v_driver_identification_no']) && (!is_string($addon['v_driver_identification_no'])
                        || !preg_match('/^[0-9A-Fa-f]{16}$/D',$addon['v_driver_identification_no']))) {
                        $errors[]="$path.addon_info.v_driver_identification_no must be 16 hex digits";
                    }
                }
            } elseif ($addon!==null && $addon!=='') $errors[]="$path.addon_info must be \"\" when activity $activityId has no addon data";
            foreach(['vertical_speed','odometer'] as $optional) {
                if(isset($item[$optional]) && !is_numeric($item[$optional])) $errors[]="$path.$optional must be numeric";
            }

            $fuel = $item['fuel_info'] ?? null;
            if ($activityId === self::ACTIVITY_FUEL_REPORT) {
                if (!is_array($fuel) || $fuel === []) {
                    $errors[] = "$path.fuel_info is required for activity 16";
                } else {
                    foreach (self::FUEL_KEYS as $key) {
                        if (!array_key_exists($key, $fuel) || $fuel[$key] === null || $fuel[$key] === '') {
                            $errors[] = "$path.fuel_info.$key is required for activity 16";
                        }
                    }
                    foreach (self::FUEL_KEYS as $field) {
                        if (!is_string($fuel[$field] ?? null) || !preg_match('/^-?\d+$/D',$fuel[$field])) {
                            $errors[] = "$path.fuel_info.$field must be an integer string";
                        }
                    }
                    if (isset($fuel['signalLevel']) && is_numeric($fuel['signalLevel'])
                        && ((int) $fuel['signalLevel'] < 0 || (int) $fuel['signalLevel'] > 99)) {
                        $errors[] = "$path.fuel_info.signalLevel must be 0-99";
                    }
                    if (isset($fuel['hardFault']) && is_numeric($fuel['hardFault'])
                        && !isset(self::HARDWARE_FAULTS[(int) $fuel['hardFault']])) {
                        $errors[] = "$path.fuel_info.hardFault must be 0-9";
                    }
                }
            }
        }
        return $errors;
    }

    /** Millimetres as the IDD requires: an integer, or null when there is no measurement. */
    private static function millimetres(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null' || !is_numeric($value)) {
            return null;
        }
        $millimetres = (float) $value;
        return $millimetres < 0 ? null : (int) round($millimetres);
    }

    /** Celsius × 10 as the IDD requires, or null when there is no measurement. */
    private static function tenths(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null' || !is_numeric($value)) {
            return null;
        }
        return (string) (int) round((float) $value * 10);
    }

    private static function boundedInt(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || $value === 'null' || !is_numeric($value)) {
            return null;
        }
        $int = (int) round((float) $value);
        return $int < $min || $int > $max ? null : $int;
    }

    private static function format(mixed $value, string $type, mixed $default = null): mixed
    {
        if ($value === null || $value === '' || $value === 'null') {
            return $default;
        }
        return match ($type) {
            'int' => (int) $value,
            'float' => round((float) $value, 2),
            default => (string) $value,
        };
    }
}
