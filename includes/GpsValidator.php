<?php
/**
 * GPS validator.
 */
class GpsValidator {

    // Structured error codes (also used as alert categories).
    const MISSING_IMEI                = 'MISSING_IMEI';
    const MISSING_VEHICLE_REGISTRATION = 'MISSING_VEHICLE_REGISTRATION';
    const INVALID_COORDINATES         = 'INVALID_COORDINATES';
    const INVALID_TIMESTAMP           = 'INVALID_TIMESTAMP';
    const INVALID_SPEED               = 'INVALID_SPEED';
    const INVALID_BEARING             = 'INVALID_BEARING';
    const INVALID_ALTITUDE            = 'INVALID_ALTITUDE';
    const INVALID_SATELLITES          = 'INVALID_SATELLITES';
    const INVALID_HDOP                = 'INVALID_HDOP';
    const INVALID_GSM                 = 'INVALID_GSM';
    const INVALID_NUMERIC             = 'INVALID_NUMERIC';
    const VALIDATION_FAILED           = 'VALIDATION_FAILED';
    const UNKNOWN_ACTIVITY            = 'UNKNOWN_ACTIVITY';

    /**
     * Validate a normalized message record.
     *
     * @param array $msg  Normalized message (columns from message_log) + vehicle
     *                    context (vehicle_reg_no, imei) + raw params.
     * @return array      ['valid' => bool, 'errors' => [ ['field','value','reason','code'] ]]
     */
    public static function validate(array $msg): array {
        $errors = [];
        $msgTime = $msg['wialon_msg_time'] ?? null;

        // IMEI availability
        $imei = $msg['imei'] ?? null;
        if (empty($imei)) {
            $errors[] = self::err('imei', $imei, 'IMEI is missing', self::MISSING_IMEI);
        } elseif (!preg_match('/^\d{8,20}$/', (string)$imei)) {
            $errors[] = self::err('imei', $imei, 'IMEI format invalid', self::MISSING_IMEI);
        }

        // Vehicle registration availability
        $reg = $msg['vehicle_reg_no'] ?? null;
        if (empty($reg)) {
            $errors[] = self::err('vehicle_reg_no', $reg, 'Vehicle registration is missing', self::MISSING_VEHICLE_REGISTRATION);
        }

        // Latitude / longitude are required and must be in range
        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $coord => [$min, $max]) {
            $val = $msg[$coord] ?? null;
            if ($val === null || $val === '') {
                $errors[] = self::err($coord, $val, 'Coordinate is required', self::INVALID_COORDINATES);
                continue;
            }
            if (!is_numeric($val)) {
                $errors[] = self::err($coord, $val, 'Must be numeric', self::INVALID_COORDINATES);
            } else {
                $f = (float)$val;
                if ($f < $min || $f > $max) {
                    $errors[] = self::err($coord, $val, "Must be within [$min, $max]", self::INVALID_COORDINATES);
                }
            }
        }

        // GPS timestamp validity
        if ($msgTime === null || $msgTime === '') {
            $errors[] = self::err('wialon_msg_time', $msgTime, 'GPS timestamp is missing', self::INVALID_TIMESTAMP);
        } elseif (!is_numeric($msgTime)) {
            $errors[] = self::err('wialon_msg_time', $msgTime, 'GPS timestamp must be epoch', self::INVALID_TIMESTAMP);
        } else {
            $ts = (int)$msgTime;
            if ($ts > time() + 3600) {
                $errors[] = self::err('wialon_msg_time', $msgTime, 'GPS timestamp is in the future', self::INVALID_TIMESTAMP);
            } elseif ($ts < time() - 30 * 86400) {
                $errors[] = self::err('wialon_msg_time', $msgTime, 'GPS timestamp is too old', self::INVALID_TIMESTAMP);
            }
        }

        // Speed range (km/h)
        if (self::present($msg['speed'] ?? null)) {
            if (!is_numeric($msg['speed'])) {
                $errors[] = self::err('speed', $msg['speed'], 'Must be numeric', self::INVALID_SPEED);
            } elseif ((float)$msg['speed'] < 0 || (float)$msg['speed'] > 400) {
                $errors[] = self::err('speed', $msg['speed'], 'Speed out of range [0, 400] km/h', self::INVALID_SPEED);
            }
        }

        // Bearing range (0-360)
        if (self::present($msg['bearing'] ?? null)) {
            if (!is_numeric($msg['bearing'])) {
                $errors[] = self::err('bearing', $msg['bearing'], 'Must be numeric', self::INVALID_BEARING);
            } elseif ((float)$msg['bearing'] < 0 || (float)$msg['bearing'] >= 360) {
                $errors[] = self::err('bearing', $msg['bearing'], 'Bearing out of range [0, 360)', self::INVALID_BEARING);
            }
        }

        // Altitude
        if (self::present($msg['altitude'] ?? null)) {
            if (!is_numeric($msg['altitude'])) {
                $errors[] = self::err('altitude', $msg['altitude'], 'Must be numeric', self::INVALID_ALTITUDE);
            } elseif ((float)$msg['altitude'] < -500 || (float)$msg['altitude'] > 11000) {
                $errors[] = self::err('altitude', $msg['altitude'], 'Altitude out of range', self::INVALID_ALTITUDE);
            }
        }

        // Satellite count (0-40)
        if (self::present($msg['satellite_count'] ?? null)) {
            if (!is_numeric($msg['satellite_count'])) {
                $errors[] = self::err('satellite_count', $msg['satellite_count'], 'Must be numeric', self::INVALID_SATELLITES);
            } elseif ((int)$msg['satellite_count'] < 0 || (int)$msg['satellite_count'] > 40) {
                $errors[] = self::err('satellite_count', $msg['satellite_count'], 'Satellite count out of range [0, 40]', self::INVALID_SATELLITES);
            }
        }

        // HDOP (0-99)
        if (self::present($msg['hdop'] ?? null)) {
            if (!is_numeric($msg['hdop'])) {
                $errors[] = self::err('hdop', $msg['hdop'], 'Must be numeric', self::INVALID_HDOP);
            } elseif ((float)$msg['hdop'] < 0 || (float)$msg['hdop'] > 99) {
                $errors[] = self::err('hdop', $msg['hdop'], 'HDOP out of range [0, 99]', self::INVALID_HDOP);
            }
        }

        // GSM / network parameters (optional — only flagged if present and malformed)
        foreach (['rssi', 'lac', 'cell_id', 'mcc'] as $gsmField) {
            if (!self::present($msg[$gsmField] ?? null)) continue;
            if (!is_numeric($msg[$gsmField])) {
                $errors[] = self::err($gsmField, $msg[$gsmField], 'Must be numeric', self::INVALID_GSM);
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * Record validation errors for a message into validation_errors table.
     * Returns number of rows inserted.
     */
    public static function recordErrors(array $msg, array $errors): int {
        if (!$errors) return 0;
        $count = 0;
        foreach ($errors as $e) {
            Database::insert('validation_errors', [
                'message_log_id'     => $msg['id'] ?? null,
                'vehicle_id'         => $msg['vehicle_id'] ?? null,
                'vehicle_reg_no'     => $msg['vehicle_reg_no'] ?? null,
                'imei'               => $msg['imei'] ?? null,
                'field'              => $e['field'],
                'invalid_value'      => isset($e['value']) ? (is_scalar($e['value']) ? (string)$e['value'] : json_encode($e['value'])) : null,
                'reason'             => $e['reason'],
                'error_code'         => $e['code'] ?? null,
                'activity_id'        => $msg['activity_id'] ?? null,
                'validation_status'  => 'failed',
                'gps_timestamp'      => $msg['wialon_msg_time'] ?? null,
            ]);
            $count++;
        }
        return $count;
    }

    /** Normalize message_log validation_status from a validation result. */
    public static function statusOf(array $result): string {
        return $result['valid'] ? 'valid' : 'failed';
    }

    private static function present($v): bool {
        return $v !== null && $v !== '';
    }

    private static function err(string $field, $value, string $reason, string $code): array {
        return ['field' => $field, 'value' => $value, 'reason' => $reason, 'code' => $code];
    }
}
