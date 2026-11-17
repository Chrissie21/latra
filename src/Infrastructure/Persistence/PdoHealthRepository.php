<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\DeviceStatus\HealthRepository;
use PDO;

final class PdoHealthRepository implements HealthRepository
{
    public function __construct(private PDO $pdo) {}
    public function activeVehicles(): array { return $this->pdo->query('SELECT id FROM vehicles WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC); }
    public function lastGpsTime(int $vehicleId): ?int
    {
        $stmt=$this->pdo->prepare('SELECT MAX(wialon_msg_time) AS t FROM message_log WHERE vehicle_id = ?');
        $stmt->execute([$vehicleId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)($row['t'] ?? 0) : null;
    }
    public function saveGpsHealth(int $vehicleId, ?int $lastGps, ?string $lastGpsAt, int $commTime, string $status, ?int $minutes): void
    {
        $stmt=$this->pdo->prepare('SELECT vehicle_id FROM gps_health WHERE vehicle_id = ?');
        $stmt->execute([$vehicleId]);
        $values=[$lastGps,$lastGpsAt,$commTime,$status,$minutes,$vehicleId];
        $sql=$stmt->fetchColumn() !== false
            ? 'UPDATE gps_health SET last_gps_time=?,last_gps_at=?,last_comm_time=?,status=?,minutes_since=? WHERE vehicle_id=?'
            : 'INSERT INTO gps_health (last_gps_time,last_gps_at,last_comm_time,status,minutes_since,vehicle_id) VALUES (?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute($values);
    }
    public function staleOnlineDevices(int $cutoff): array
    {
        $stmt=$this->pdo->prepare('SELECT d.vehicle_id FROM device_status d WHERE d.is_online = 1 AND d.last_comm_time IS NOT NULL AND d.last_comm_time < ?');
        $stmt->execute([$cutoff]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function markDeviceOffline(int $vehicleId,string $at): void
    {
        $this->pdo->prepare('UPDATE device_status SET is_online=0,status_changed_at=? WHERE vehicle_id=?')->execute([$at,$vehicleId]);
    }
}
