<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

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

    public static function mapVehicle(array $wialonUnit): array
    {
        $position = $wialonUnit['pos'] ?? [];
        $lastMessage = $wialonUnit['lmsg'] ?? [];
        $params = $lastMessage['p'] ?? [];
        $source = !empty($lastMessage['pos']) ? $lastMessage['pos'] : $position;

        return [
            'wialon_unit_id' => $wialonUnit['id'] ?? null,
            'vehicle_reg_no' => self::extractRegistration($wialonUnit),
            'name' => $wialonUnit['nm'] ?? null,
            'imei' => $wialonUnit['uid'] ?? $wialonUnit['uid2'] ?? null,
            'hw_type' => $wialonUnit['hw'] ?? null,
            'phone' => $wialonUnit['ph'] ?? null,
            'lat' => is_numeric($source['y'] ?? null) ? round((float) $source['y'], 8) : null,
            'lng' => is_numeric($source['x'] ?? null) ? round((float) $source['x'], 8) : null,
            'altitude' => is_numeric($source['z'] ?? null) ? (int) $source['z'] : null,
            'speed' => is_numeric($source['s'] ?? null) ? round((float) $source['s'], 2) : null,
            'bearing' => is_numeric($source['c'] ?? null) ? round((float) $source['c'], 2) : null,
            'satellite_count' => is_numeric($source['sc'] ?? null) ? (int) $source['sc'] : null,
            'hdop' => is_numeric($params['hdop'] ?? null) ? round((float) $params['hdop'], 2) : null,
            'd2d3' => SatelliteModePolicy::fromMessage($source, $params),
            'rssi' => is_numeric($params['rssi'] ?? null) ? (int) $params['rssi'] : (is_numeric($params['gsm'] ?? null) ? (int) $params['gsm'] : null),
            'lac' => is_numeric($params['lac'] ?? null) ? (int) $params['lac']
                : (isset($params['gsm_lac']) ? (int) $params['gsm_lac']
                    : (isset($params['lbs_lac']) ? (int) $params['lbs_lac'] : null)),
            'cell_id' => is_numeric($params['cell_id'] ?? null) ? (int) $params['cell_id']
                : (isset($params['cellid']) ? (int) $params['cellid']
                    : (isset($params['cid']) ? (int) $params['cid']
                        : (isset($params['gsm_cellid']) ? (int) $params['gsm_cellid']
                            : (isset($params['gsm_cell_id']) ? (int) $params['gsm_cell_id'] : null)))),
            'mcc' => is_numeric($params['mcc'] ?? null) ? (int) $params['mcc'] : null,
            'mgs_id' => isset($lastMessage['t']) ? (int) $lastMessage['t'] : (isset($position['t']) ? (int) $position['t'] : null),
            'last_message_time' => isset($lastMessage['t']) ? (int) $lastMessage['t'] : (isset($position['t']) ? (int) $position['t'] : null),
        ];
    }

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
    public static function buildPayload(array $vehicle, array $message, int $activityId,
        array $addonInfo = [], array $fuelInfo = []): array
    {
        $item = [
            'latitude'=>self::format($message['lat'] ?? null,'string'),
            'longitude'=>self::format($message['lng'] ?? null,'string'),
            'timestamp'=>self::toEpochMs($message['wialon_msg_time'] ?? $message['last_message_time'] ?? null),
            'activity_id'=>(string)$activityId,
            'odometer'=>self::format($message['odometer'] ?? null,'string','0'),
            'addon_info'=>in_array($activityId,self::ADDON_ACTIVITIES,true) && $addonInfo ? $addonInfo : '',
        ];
        foreach (['altitude'=>'altitude','horizontal_speed'=>'speed','bearing'=>'bearing',
            'satellite_count'=>'satellite_count','HDOP'=>'hdop','d2d3'=>'d2d3','RSSI'=>'rssi',
            'LAC'=>'lac','Cell_ID'=>'cell_id','MGS_ID'=>'mgs_id','MCC'=>'mcc'] as $wire=>$source) {
            $item[$wire]=self::format($message[$source] ?? null,'string');
        }
        // Examples include vertical_speed, but the required-field table does not require it.
        if (self::format($message['vertical_speed'] ?? null,'string') !== null) {
            $item['vertical_speed']=self::format($message['vertical_speed'],'string');
        }
        if ($activityId===self::ACTIVITY_FUEL_REPORT && $fuelInfo) $item['fuel_info']=$fuelInfo;
        return ['vehicle_reg_no'=>$vehicle['vehicle_reg_no'] ?? null,'type'=>'poi',
            'imei'=>$vehicle['imei'] ?? null,'items'=>[$item]];
    }

    /** addon_info is optional. Only supplied measurements or documented sentinels are included. */
    public static function buildAddonInfo(int $activityId,array $wialonParams=[],array $customData=[]): ?array
    {
        $driver=$customData['driverId'] ?? $wialonParams['driver_id'] ?? $wialonParams['tco_driver1_id'] ?? $wialonParams['pass'] ?? null;
        $values=match($activityId) {
            2=>['idleTime'=>$customData['idleTime'] ?? $wialonParams['idle_time'] ?? null,
                'v_driver_identification_no'=>$driver],
            3=>['distance_travelled'=>$customData['distance'] ?? null,
                'trip_duration'=>$customData['duration'] ?? null,
                'avgSpeed'=>$customData['avgSpeed'] ?? null,'maxSpeed'=>$customData['maxSpeed'] ?? null],
            9,10=>['ext_power_voltage'=>$customData['extVolt'] ?? $wialonParams['pwr_ext'] ?? null,
                'int_battery_voltage'=>$customData['intVolt'] ?? $wialonParams['pwr_int'] ?? null],
            17,24=>['v_driver_identification_no'=>$driver ?? 'FFFFFFFFFFFFFFFF'],
            default=>[],
        };
        $output=[];
        foreach($values as $key=>$value) {
            $formatted=self::format($value,'string');
            if($formatted!==null) $output[$key]=$formatted;
        }
        return $output ?: null;
    }

    public const MILLIMETRE_UNITS=['mm','millimetre','millimetres','millimeter','millimeters'];

    /** Fuel data is required for activity 16. No sensor values or healthy statuses are invented. */
    public static function buildFuelInfo(array $wialonParams=[],array $customData=[]): array
    {
        $unit=strtolower(trim((string)($customData['unit'] ?? $wialonParams['fuel_unit'] ?? $wialonParams['unit'] ?? '')));
        $mm=in_array($unit,self::MILLIMETRE_UNITS,true);
        $level=$mm ? self::millimetres($customData['fuelLevel'] ?? $wialonParams['fuel'] ?? $wialonParams['fuel_level'] ?? null) : null;
        $rt=$mm ? self::millimetres($customData['rtFuelLevel'] ?? $wialonParams['rt_fuel'] ?? null) : null;
        return [
            'validFlag'=>self::format($customData['validFlag'] ?? $wialonParams['fuel_valid_flag'] ?? null,'string'),
            'signalLevel'=>self::format(self::boundedInt($customData['signalLevel'] ?? $wialonParams['fuel_signal'] ?? null,0,99),'string'),
            'softStatus'=>self::format($customData['softStatus'] ?? $wialonParams['fuel_soft_status'] ?? null,'string'),
            'hardFault'=>self::format($customData['hardFault'] ?? $wialonParams['fuel_hard_fault'] ?? null,'string'),
            'fuelLevel'=>self::format($level,'string'), 'rtFuelLevel'=>self::format($rt,'string'),
            'tankTemp'=>self::format($customData['tankTemp'] ?? self::tenths($wialonParams['tank_temp'] ?? $wialonParams['fuel_temp'] ?? null),'string'),
            // The IDD explicitly specifies compartment default 1.
            'channel'=>self::format($customData['channel'] ?? $wialonParams['fuel_channel'] ?? null,'string','1'),
        ];
    }

    public static function detectActivity(array $wialonParams, array $previousState = []): int
    {
        if (isset($wialonParams['event'])) {
            $event = strtolower((string) $wialonParams['event']);
            $eventMap = [
                'engine_on' => 2, 'engine start' => 2, 'ignition_on' => 2,
                'engine_off' => 3, 'engine stop' => 3, 'ignition_off' => 3,
                'speeding' => 4, 'overspeed' => 4,
                'harsh_braking' => 5, 'harshbrake' => 5,
                'harsh_turning' => 6, 'harsturn' => 6,
                'harsh_acceleration' => 7, 'harshaccel' => 7,
                'panic' => 8, 'sos' => 8, 'alarm' => 8,
                'battery_low' => 9, 'low_battery' => 9,
                'power_disconnected' => 10, 'ext_power_off' => 10,
                'excessive_idle' => 11, 'long_idle' => 11,
                'accident' => 12, 'crash' => 12,
                'passenger_panic' => 13,
                'tamper' => 14, 'tampering' => 14, 'antenna_cut' => 14,
                'black_box' => 15,
                'fuel' => 16, 'fuel_data' => 16,
                'invalid_scan' => 17, 'invalid_ibutton' => 17,
                'boundary_enter' => 20, 'geofence_in' => 20,
                'boundary_exit' => 21, 'geofence_out' => 21,
                'checkpoint_enter' => 22,
                'checkpoint_exit' => 23,
                'ibutton' => 24, 'driver_scan' => 24,
            ];
            foreach ($eventMap as $key => $activityId) {
                if (strpos($event, $key) !== false) {
                    return $activityId;
                }
            }
        }
        if (isset($wialonParams['pass']) || isset($wialonParams['tco_driver1_id'])) {
            return 24;
        }
        if (isset($wialonParams['fuel']) || isset($wialonParams['fuel_level'])) {
            return 16;
        }
        return 1;
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

    private static function extractRegistration(array $unit): string
    {
        foreach ($unit['flds'] ?? [] as $field) {
            $name = strtolower($field['n'] ?? '');
            if (in_array($name, ['reg_no', 'registration', 'plate', 'plate_no', 'vehicle_reg'])) {
                return strtoupper(trim($field['v'] ?? ''));
            }
        }
        $name = strtoupper(trim($unit['nm'] ?? ''));
        if (preg_match('/^[A-Z0-9\-]{3,20}$/', $name)) {
            return $name;
        }
        return '';
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
