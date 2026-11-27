<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Operations\OperatorActionRepository;

/** Operator action SQL behind the shared connection API; no business rules live here. */
final class DatabaseOperatorActionRepository implements OperatorActionRepository
{
    public function messageForRelay(int $messageId): ?array
    {
        return \Database::fetch(
            'SELECT ml.*, v.vehicle_reg_no, v.imei, v.name, ml.id as msg_id
             FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?',
            [$messageId],
        );
    }

    public function messageForSubmission(int $messageId): ?array
    {
        return \Database::fetch(
            'SELECT ml.*, v.vehicle_reg_no, v.imei, v.name
             FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?',
            [$messageId],
        );
    }

    public function vehicle(int $vehicleId): ?\Latra\Domain\Collection\Vehicle
    {
        $row = \Database::fetch('SELECT * FROM vehicles WHERE id = ?', [$vehicleId]);
        return $row === null ? null : VehicleRowDecoder::decode($row);
    }

    public function latestMessage(int $vehicleId): ?array
    {
        return \Database::fetch(
            'SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1',
            [$vehicleId],
        );
    }

    public function pushLog(int $id): ?array
    {
        return \Database::fetch('SELECT * FROM push_log WHERE id = ?', [$id]);
    }

    public function deletePushLog(int $id): void
    {
        \Database::query('DELETE FROM push_log WHERE id = ?', [$id]);
    }

    public function activityMapping(int $vehicleId, int $activityId): ?array
    {
        return \Database::fetch(
            'SELECT id FROM activity_mappings WHERE vehicle_id = ? AND activity_id = ?',
            [$vehicleId, $activityId],
        );
    }

    public function insertActivityMapping(int $vehicleId, int $activityId, int $enabled): void
    {
        \Database::insert('activity_mappings', [
            'vehicle_id' => $vehicleId,
            'activity_id' => $activityId,
            'is_enabled' => $enabled,
        ]);
    }

    public function updateActivityMapping(int $id, int $enabled): void
    {
        \Database::update('activity_mappings', ['is_enabled' => $enabled], 'id = ?', [$id]);
    }

    public function setVehicleActive(int $vehicleId, int $active): void
    {
        \Database::update('vehicles', ['is_active' => $active], 'id = ?', [$vehicleId]);
    }

    public function schedulerHealth(): array
    {
        return [
            'last_run_at' => \Database::fetch('SELECT MAX(started_at) AS s FROM automation_runs')['s'] ?? null,
            'enabled_buckets' => (int) (\Database::fetch('SELECT COUNT(*) AS c FROM automation_buckets WHERE is_enabled = 1')['c'] ?? 0),
            'backlog' => (int) (\Database::fetch("SELECT COUNT(*) AS c FROM relay_pending WHERE status = 'pending'")['c'] ?? 0),
            'dead_letter' => (int) (\Database::fetch("SELECT COUNT(*) AS c FROM relay_pending WHERE status = 'review'")['c'] ?? 0),
        ];
    }
}
