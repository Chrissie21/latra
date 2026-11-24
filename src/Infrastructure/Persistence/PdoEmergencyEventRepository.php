<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Emergency\EmergencyEventRepository;
use PDO;
use PDOException;

/**
 * PDO implementation of emergency event and high-frequency record persistence.
 *
 * No transaction is opened here: every write joins whatever connection (and
 * caller-owned transaction) the composition root supplied, matching the legacy
 * behaviour where ingestion buffered records outside the per-message write.
 */
final class PdoEmergencyEventRepository implements EmergencyEventRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function activeByType(int $vehicleId, string $eventType): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM emergency_events
             WHERE vehicle_id = ? AND status = 'active' AND event_type = ?
             LIMIT 1"
        );
        $statement->execute([$vehicleId, $eventType]);
        $event = $statement->fetch(PDO::FETCH_ASSOC);
        return $event === false ? null : $event;
    }

    public function activeForVehicle(int $vehicleId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT e.*, v.vehicle_reg_no, v.imei FROM emergency_events e
             JOIN vehicles v ON v.id = e.vehicle_id
             WHERE e.vehicle_id = ? AND e.status = 'active'
             ORDER BY e.started_at DESC LIMIT 1"
        );
        $statement->execute([$vehicleId]);
        $event = $statement->fetch(PDO::FETCH_ASSOC);
        return $event === false ? null : $event;
    }

    public function activeCount(): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM emergency_events WHERE status = 'active'");
        return (int) $statement->fetchColumn();
    }

    public function findById(int $eventId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM emergency_events WHERE id = ?');
        $statement->execute([$eventId]);
        $event = $statement->fetch(PDO::FETCH_ASSOC);
        return $event === false ? null : $event;
    }

    public function create(array $event): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO emergency_events
                (event_id, vehicle_id, event_type, activity_id, status, started_at, trigger_reason, max_speed_kmh)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $event['event_id'],
            $event['vehicle_id'],
            $event['event_type'],
            $event['activity_id'],
            $event['status'],
            $event['started_at'],
            $event['trigger_reason'],
            $event['max_speed_kmh'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createRecord(array $record): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO emergency_queue
                (event_id, emergency_event_id, vehicle_id, wialon_msg_time, lat, lng, altitude, speed,
                 bearing, satellite_count, hdop, raw_data, latra_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute([
                $record['event_id'],
                $record['emergency_event_id'],
                $record['vehicle_id'],
                $record['wialon_msg_time'],
                $record['lat'],
                $record['lng'],
                $record['altitude'],
                $record['speed'],
                $record['bearing'],
                $record['satellite_count'],
                $record['hdop'],
                $record['raw_data'],
                $record['latra_status'],
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                return false;
            }
            throw $error;
        }

        return true;
    }

    public function incrementRecordCount(int $eventId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE emergency_events SET record_count = record_count + 1 WHERE id = ?'
        );
        $statement->execute([$eventId]);
    }

    public function end(int $eventId, string $endedAt): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE emergency_events SET status = 'ended', ended_at = ? WHERE id = ?"
        );
        $statement->execute([$endedAt, $eventId]);
    }
}
