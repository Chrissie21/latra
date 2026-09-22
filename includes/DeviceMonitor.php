<?php
/**
 * Device status monitor.
 */
class DeviceMonitor {

    // Offline after 15 minutes without contact.
    const OFFLINE_SECONDS = 900;

    // Low battery warning level.
    const BATTERY_LOW_MV = 3200;

    // Low battery percent.
    const BATTERY_LOW_PCT = 20;

    // External power low level.
    const EXT_POWER_LOW_MV = 1000;

    // Update a device from the latest message.
    public static function updateFromMessage(int $vehicleId, array $params, int $commTime): void {
        $prev = Database::fetch("SELECT * FROM device_status WHERE vehicle_id = ?", [$vehicleId]);

        // Extract electric / power / tamper signals (Wialon param names vary).
        $extPwrMv = self::mV($params['pwr_ext'] ?? $params['ext_power'] ?? $params['io_66'] ?? null);
        $intBatMv = self::mV($params['pwr_int'] ?? $params['battery_charge'] ?? $params['io_67'] ?? null);
        // Wialon units commonly report internal battery as a percentage (e.g. 72).
        $intBatPct = self::pct($params['battery'] ?? $params['battery_pct'] ?? null);
        $extPwrDisconnected = $extPwrMv !== null && $extPwrMv < self::EXT_POWER_LOW_MV && $extPwrMv > 0;
        $batLow = ($intBatMv !== null && $intBatMv < self::BATTERY_LOW_MV && $intBatMv > 0)
               || ($intBatPct !== null && $intBatPct < self::BATTERY_LOW_PCT);
        $tampered = EventDetector::flagOn($params, ['tamper', 'tampering', 'antenna_cut', 'io_8']) === true;

        $data = [
            'is_online'                    => 1,
            'online_at'                    => $prev['online_at'] ?? date(DATE_FORMAT),
            'last_comm_time'               => $commTime,
            'last_comm_at'                 => date(DATE_FORMAT, $commTime),
            'last_gps_at'                  => $commTime,
            'internal_battery_low'         => $batLow ? 1 : 0,
            'internal_battery_mv'          => $intBatMv,
            'internal_battery_pct'         => $intBatPct,
            'external_power_disconnected'  => $extPwrDisconnected ? 1 : 0,
            'external_power_mv'            => $extPwrMv,
            'tampered'                     => $tampered ? 1 : 0,
            'status_changed_at'            => date(DATE_FORMAT),
        ];

        $changed = false;
        if ($prev) {
            // Detect transitions for event generation.
            if ((int)$prev['internal_battery_low'] !== (int)$data['internal_battery_low'] && $batLow) {
                self::event($vehicleId, 'BATTERY_LOW', "Internal battery low ({$intBatMv} mV)");
            }
            if ((int)$prev['external_power_disconnected'] !== (int)$data['external_power_disconnected'] && $extPwrDisconnected) {
                self::event($vehicleId, 'POWER_DISCONNECTED', "External power disconnected");
            }
            if ((int)$prev['tampered'] !== (int)$data['tampered'] && $tampered) {
                self::event($vehicleId, 'TAMPERING', 'Device tampering detected');
            }
            $changed = $prev['is_online'] != $data['is_online'] || $prev['internal_battery_low'] != $data['internal_battery_low'] ||
                       $prev['external_power_disconnected'] != $data['external_power_disconnected'] || $prev['tampered'] != $data['tampered'];
            Database::update('device_status', $data, 'vehicle_id = ?', [$vehicleId]);
        } else {
            Database::insert('device_status', array_merge(['vehicle_id' => $vehicleId], $data));
        }

        if ($changed) {
            self::statusLog($vehicleId, $data);
        }
    }

    // Mark devices offline when they stop replying.
    public static function markOffline(): int {
        $now = time();
        $rows = Database::fetchAll(
            "SELECT d.vehicle_id FROM device_status d
             WHERE d.is_online = 1 AND d.last_comm_time IS NOT NULL
               AND d.last_comm_time < ? - " . self::OFFLINE_SECONDS,
            [$now]
        );
        $marked = 0;
        foreach ($rows as $r) {
            Database::update('device_status', ['is_online' => 0, 'status_changed_at' => date(DATE_FORMAT)], 'vehicle_id = ?', [$r['vehicle_id']]);
            self::event((int)$r['vehicle_id'], 'DEVICE_OFFLINE', 'Device lost connection (heartbeat expired)');
            $marked++;
        }
        return $marked;
    }

    // Fleet summary for the dashboard.
    public static function summary(): array {
        $rows = Database::fetchAll(
            "SELECT
                SUM(is_online) AS online,
                SUM(internal_battery_low) AS battery_low,
                SUM(external_power_disconnected) AS power_disconnected,
                SUM(tampered) AS tampered,
                COUNT(*) AS total
             FROM device_status"
        );
        $s = $rows[0] ?? [];
        return [
            'online' => (int)($s['online'] ?? 0),
            'battery_low' => (int)($s['battery_low'] ?? 0),
            'power_disconnected' => (int)($s['power_disconnected'] ?? 0),
            'tampered' => (int)($s['tampered'] ?? 0),
            'total' => (int)($s['total'] ?? 0),
        ];
    }

    // List devices with their status.
    public static function statusList(int $limit = 200): array {
        return Database::fetchAll(
            "SELECT d.*, v.vehicle_reg_no, v.imei
             FROM device_status d JOIN vehicles v ON v.id = d.vehicle_id
             ORDER BY d.is_online DESC, d.last_comm_at DESC LIMIT $limit"
        );
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    // Convert voltage to millivolts.
    private static function mV($value): ?float {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        $v = (float)$value;
        // Wialon often reports V (e.g. 13.4) or mV (13425). Normalize to mV.
        if ($v < 100) return round($v * 1000, 2);
        return round($v, 2);
    }

    // Convert battery to percent.
    private static function pct($value): ?float {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        $v = (float)$value;
        // Some units report 0-1 fraction instead of 0-100.
        if ($v > 0 && $v <= 1) $v *= 100;
        return round(max(0, min(100, $v)), 2);
    }

    // Save a status change event.
    private static function event(int $vehicleId, string $type, string $detail): void {
        Automation::log('device.status_change', ['vehicle' => $vehicleId, 'type' => $type, 'detail' => $detail]);
        if (class_exists('Auth')) {
            Auth::audit('device.' . strtolower($type), (string)$vehicleId, $detail, 'system');
        }
    }

    private static function statusLog(int $vehicleId, array $data): void {
        Automation::log('device.status', [
            'vehicle' => $vehicleId,
            'online' => $data['is_online'],
            'battery_low' => $data['internal_battery_low'],
            'ext_power_off' => $data['external_power_disconnected'],
            'tampered' => $data['tampered'],
        ]);
    }
}
