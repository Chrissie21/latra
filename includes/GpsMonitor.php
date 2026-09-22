<?php
/**
 * GPS monitor.
 */
class GpsMonitor {

    // LATRA max gap between GPS updates.
    const MAX_INTERVAL_SECONDS = 180; // 3 minutes

    // Offline threshold.
    const OFFLINE_SECONDS = 900; // 15 minutes

    // Refresh GPS health for active vehicles.
    public static function refresh(): array {
        $vehicles = Database::fetchAll(
            "SELECT id FROM vehicles WHERE is_active = 1"
        );
        $now = time();
        $counts = ['ok' => 0, 'stale' => 0, 'offline' => 0, 'unknown' => 0];

        foreach ($vehicles as $veh) {
            $row = Database::fetch(
                "SELECT MAX(wialon_msg_time) AS t FROM message_log WHERE vehicle_id = ?",
                [$veh['id']]
            );
            $lastGps = $row ? (int)($row['t'] ?? 0) : null;

            $status = 'unknown';
            $mins = null;
            if ($lastGps) {
                $mins = (int)floor(($now - $lastGps) / 60);
                if (($now - $lastGps) <= self::MAX_INTERVAL_SECONDS) {
                    $status = 'ok';
                } elseif (($now - $lastGps) <= self::OFFLINE_SECONDS) {
                    $status = 'stale';
                } else {
                    $status = 'offline';
                }
            }

            $counts[$status]++;
            self::upsert($veh['id'], $lastGps, $now, $status, $mins);
        }

        return $counts;
    }

    // Save GPS health for one vehicle.
    public static function upsert(int $vehicleId, ?int $lastGps, int $commTime, string $status, ?int $mins): void {
        $exists = Database::fetch("SELECT vehicle_id FROM gps_health WHERE vehicle_id = ?", [$vehicleId]);
        $data = [
            'last_gps_time' => $lastGps !== null ? $lastGps : null,
            'last_gps_at'   => $lastGps !== null ? date(DATE_FORMAT, $lastGps) : null,
            'last_comm_time'=> $commTime,
            'status'        => $status,
            'minutes_since' => $mins,
        ];
        if ($exists) {
            Database::update('gps_health', $data, 'vehicle_id = ?', [$vehicleId]);
        } else {
            Database::insert('gps_health', array_merge(['vehicle_id' => $vehicleId], $data));
        }
    }

    // Count vehicles with fresh GPS.
    public static function freshCount(): int {
        $row = Database::fetch("SELECT COUNT(*) AS c FROM gps_health WHERE status = 'ok'");
        return (int)($row['c'] ?? 0);
    }

    // Summary for the health page.
    public static function summary(): array {
        $rows = Database::fetchAll("SELECT status, COUNT(*) AS c FROM gps_health GROUP BY status");
        $out = ['ok' => 0, 'stale' => 0, 'offline' => 0, 'unknown' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r['status']])) $out[$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    // List stale or offline vehicles.
    public static function staleVehicles(): array {
        return Database::fetchAll(
            "SELECT g.vehicle_id, g.status, g.minutes_since, g.last_gps_time, v.vehicle_reg_no, v.imei
             FROM gps_health g JOIN vehicles v ON v.id = g.vehicle_id
             WHERE g.status IN ('stale','offline')
             ORDER BY g.minutes_since DESC"
        );
    }
}
