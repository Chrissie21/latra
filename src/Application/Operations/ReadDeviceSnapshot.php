<?php
namespace Latra\Application\Operations;

use Closure;
use RuntimeException;

/** Projects only available device/source evidence; authorization remains at the entry point. */
final class ReadDeviceSnapshot
{
    private Closure $now;
    public function __construct(private OperatorReadRepository $repository,callable $now) { $this->now=Closure::fromCallable($now); }
    public function read(int $vehicleId): array {
            $vehicle = $this->repository->vehicle($vehicleId);
            if (!$vehicle) {
                throw new RuntimeException('Vehicle not found');
            }
            $device = $this->repository->device($vehicleId);
            $latest = $this->repository->latestMessageWithIdentity($vehicleId);
            $snapshot = [
                'vehicle' => [
                    'id' => (int) $vehicle['id'],
                    'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
                    'name' => $vehicle['name'] ?? '',
                    'imei' => $vehicle['imei'] ?? '',
                    'hw_type' => $vehicle['hw_type'] ?? '',
                    'phone' => $vehicle['phone'] ?? '',
                    'speed_limit_kmh' => isset($vehicle['speed_limit_kmh']) ? (float) $vehicle['speed_limit_kmh'] : null,
                    'odometer' => isset($vehicle['odometer']) ? (float) $vehicle['odometer'] : null,
                    'last_message_time' => isset($vehicle['last_message_time']) && $vehicle['last_message_time'] ? (int) $vehicle['last_message_time'] : null,
                    'is_active' => (bool) $vehicle['is_active'],
                    'last_sync_at' => $vehicle['last_sync_at'] ?? null,
                ],
                'device' => $device
                    ? [
                        'is_online' => (bool) $device['is_online'],
                        'online_at' => $device['online_at'] ?? null,
                        'last_comm_at' => $device['last_comm_at'] ?? null,
                        'last_gps_at' => isset($device['last_gps_at']) && $device['last_gps_at'] ? (int) $device['last_gps_at'] : null,
                        'internal_battery_low' => (bool) $device['internal_battery_low'],
                        'internal_battery_pct' => isset($device['internal_battery_pct']) ? (float) $device['internal_battery_pct'] : null,
                        'internal_battery_mv' => isset($device['internal_battery_mv']) ? (int) $device['internal_battery_mv'] : null,
                        'external_power_mv' => isset($device['external_power_mv']) ? (int) $device['external_power_mv'] : null,
                        'external_power_disconnected' => (bool) $device['external_power_disconnected'],
                        'tampered' => (bool) $device['tampered'],
                        'last_status' => $device['last_status'] ?? null,
                        'updated_at' => $device['updated_at'] ?? null,
                    ]
                    : null,
                'latest_message' => $latest
                    ? [
                        'wialon_msg_time' => isset($latest['wialon_msg_time']) ? (int) $latest['wialon_msg_time'] : null,
                        'created_at' => $latest['created_at'] ?? null,
                        'lat' => isset($latest['lat']) ? (float) $latest['lat'] : null,
                        'lng' => isset($latest['lng']) ? (float) $latest['lng'] : null,
                        'altitude' => isset($latest['altitude']) ? (int) $latest['altitude'] : null,
                        'speed' => isset($latest['speed']) ? (float) $latest['speed'] : null,
                        'bearing' => isset($latest['bearing']) ? (float) $latest['bearing'] : null,
                        'satellite_count' => isset($latest['satellite_count']) ? (int) $latest['satellite_count'] : null,
                        'activity_id' => isset($latest['activity_id']) ? (int) $latest['activity_id'] : null,
                        'driver_id' => $latest['driver_id'] ?? null,
                        'odometer' => isset($latest['odometer']) ? (float) $latest['odometer'] : null,
                        'latra_status' => $latest['latra_status'] ?? '',
                        'latra_response' => $latest['latra_response'] ?? null,
                        'validation_status' => $latest['validation_status'] ?? null,
                    ]
                    : null,
                'stale' => $device && $device['updated_at']
                    ? (strtotime((string) $device['updated_at']) < ($this->now)() - $this->repository->offlineSeconds())
                    : true,
            ];
            return $snapshot;
    }
}
