<?php
/**
 * Normaliser.
 */
class Normaliser {
    public static function fromWialon(array $vehicle, array $msg): array {
        $pos = $msg['pos'] ?? [];
        $params = ParamResolver::resolve($msg['p'] ?? [], (string) ($vehicle['hw_type'] ?? ''));
        $epoch = isset($msg['t']) ? (int) $msg['t'] : time();
        $received = isset($msg['rt']) ? (int) $msg['rt'] : time();

        $batteryMv = self::toMv(ParamResolver::pick($params, 'battery_mv', ['pwr_int', 'io_67']));
        $batteryPct = self::toPct(ParamResolver::pick($params, 'battery_pct', ['battery']));
        $driver = ParamResolver::pick($params, 'driver_id', ['pass', 'tco_driver1_id']);
        $odo = ParamResolver::pick($params, 'odometer', ['mileage', 'mil']);

        return [
            'vehicle_id' => (int) $vehicle['id'],
            'wialon_msg_time' => $epoch,
            'gps_time' => gmdate('Y-m-d H:i:s', $epoch),
            'data_received_time' => gmdate('Y-m-d H:i:s', $received),
            'data_processed_time' => gmdate('Y-m-d H:i:s'),
            'raw_data' => json_encode($msg),
            'lat' => isset($pos['y']) ? round((float) $pos['y'], 8) : null,
            'lng' => isset($pos['x']) ? round((float) $pos['x'], 8) : null,
            'altitude' => isset($pos['z']) ? (int) $pos['z'] : null,
            'speed' => isset($pos['s']) ? round((float) $pos['s'], 2) : null,
            'bearing' => isset($pos['c']) ? round((float) $pos['c'], 2) : null,
            'satellite_count' => isset($pos['sc']) ? (int) $pos['sc'] : null,
            'hdop' => isset($params['hdop']) ? round((float) $params['hdop'], 2) : null,
            'd2d3' => (!empty($pos['z'])) ? 3 : 2,
            'rssi' => self::intOrNull(ParamResolver::pick($params, 'rssi', ['gsm'])),
            'lac' => self::intOrNull(ParamResolver::pick($params, 'lac')),
            'cell_id' => self::intOrNull(ParamResolver::pick($params, 'cell_id')),
            'mcc' => self::intOrNull($params['mcc'] ?? null),
            'mgs_id' => $epoch,
            'odometer' => $odo !== null && is_numeric($odo) ? round((float) $odo, 2) : null,
            'driver_id' => $driver !== null ? (string) $driver : null,
            'imei' => $vehicle['imei'] ?: null,
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'] ?? null,
            'battery_pct' => $batteryPct,
            'battery_mv' => $batteryMv,
            'data_received_at' => $received,
            'canonical_params' => $params,
        ];
    }

    public static function toMv($value): ?float {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $v = (float) $value;
        return $v < 100 ? round($v * 1000, 2) : round($v, 2);
    }

    public static function toPct($value): ?float {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $v = (float) $value;
        if ($v > 0 && $v <= 1) {
            $v *= 100;
        }
        return round(max(0, min(100, $v)), 2);
    }

    private static function intOrNull($v): ?int {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : (int) $v;
    }
}
