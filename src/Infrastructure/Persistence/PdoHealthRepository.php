<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\DeviceStatus\HealthRepository;
use Latra\Domain\Ingestion\GpsHealthPolicy;
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
    public function freshCount(): int { return (int)$this->pdo->query("SELECT COUNT(*) FROM gps_health WHERE status = 'ok'")->fetchColumn(); }
    public function gpsSummary(): array
    {
        $counts=GpsHealthPolicy::emptyCounts();
        foreach ($this->pdo->query('SELECT status,COUNT(*) AS c FROM gps_health GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($counts[$row['status']])) $counts[$row['status']]=(int)$row['c'];
        }
        return $counts;
    }
    public function staleVehicles(): array
    {
        return $this->pdo->query("SELECT g.vehicle_id, g.status, g.minutes_since, g.last_gps_time, v.vehicle_reg_no, v.imei
             FROM gps_health g JOIN vehicles v ON v.id = g.vehicle_id
             WHERE g.status IN ('stale','offline') ORDER BY g.minutes_since DESC")->fetchAll(PDO::FETCH_ASSOC);
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
    public function deviceSummary(): array
    {
        $row=$this->pdo->query('SELECT SUM(is_online) AS online,SUM(internal_battery_low) AS battery_low,SUM(external_power_disconnected) AS power_disconnected,SUM(tampered) AS tampered,COUNT(*) AS total FROM device_status')->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map('intval',array_replace(['online'=>0,'battery_low'=>0,'power_disconnected'=>0,'tampered'=>0,'total'=>0],$row));
    }
    public function deviceStatusList(int $limit): array
    {
        return $this->pdo->query("SELECT d.*,v.vehicle_reg_no,v.imei FROM device_status d JOIN vehicles v ON v.id=d.vehicle_id ORDER BY d.is_online DESC,d.last_comm_at DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    }
}
