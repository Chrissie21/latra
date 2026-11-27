<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Operations\OperatorReadRepository;

/** Uses the shared database connection API without exposing SQL to operator use cases. */
final class DatabaseOperatorReadRepository implements OperatorReadRepository, \Latra\Application\Operations\OperatorPayloadRepository
{
    public function vehicle(int $id): ?\Latra\Domain\Collection\Vehicle {
        $row = \Database::fetch('SELECT * FROM vehicles WHERE id = ?', [$id]);
        return $row === null ? null : VehicleRowDecoder::decode($row);
    }
    public function device(int $id): ?array { return \Database::fetch('SELECT * FROM device_status WHERE vehicle_id = ?',[$id]); }
    public function latestMessageWithIdentity(int $id): ?array {
        return \Database::fetch('SELECT m.*,v.vehicle_reg_no FROM message_log m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.vehicle_id=? ORDER BY m.wialon_msg_time DESC LIMIT 1',[$id]);
    }
    public function receipt(int $id): ?array { return \Database::fetch('SELECT * FROM push_log WHERE id = ?',[$id]); }
    public function message(int $id): ?array {
        return \Database::fetch('SELECT ml.*,v.vehicle_reg_no,v.imei,v.name FROM message_log ml JOIN vehicles v ON ml.vehicle_id=v.id WHERE ml.id = ?',[$id]);
    }
    public function activityStatistics(): array { return \Database::fetchAll('SELECT activity_id,COUNT(*) as c FROM push_log GROUP BY activity_id'); }
    public function offlineSeconds(): int { return (int)(\Database::fetch("SELECT config_value FROM config WHERE config_key = 'offline_seconds'")['config_value'] ?? 900); }
    public function latestMessage(int $id): ?array { return \Database::fetch('SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1',[$id]); }
    public function recentMessages(int $id,int $limit): array {
        return \Database::fetchAll('SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT '.max(1,$limit),[$id]);
    }
}
