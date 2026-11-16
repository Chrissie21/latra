<?php
namespace Latra\Infrastructure\Persistence;

use PDO;

/** Existing maintenance SQL, scoped to the session supplied by the composition root. */
final class PdoMaintenanceRepository
{
    public function __construct(private PDO $pdo) {}
    public function reconcileJobLocks(): int {
        $stmt = $this->pdo->query(
            "UPDATE jobs SET locked_until = NULL WHERE locked_until IS NOT NULL AND locked_until < NOW()"
        );
        return $stmt->rowCount();
    }

    public function rollupDaily(): int {
        $this->pdo->query(
            "INSERT INTO kpi_daily (day, vehicles, messages, sent, failed, rejected, emergencies)
             SELECT CURDATE(),
                (SELECT COUNT(*) FROM vehicles),
                (SELECT COUNT(*) FROM message_log WHERE DATE(created_at) = CURDATE()),
                (SELECT COUNT(*) FROM outbox WHERE status = 'sent' AND DATE(updated_at) = CURDATE()),
                (SELECT COUNT(*) FROM outbox WHERE status = 'failed' AND DATE(updated_at) = CURDATE()),
                (SELECT COUNT(*) FROM message_log WHERE latra_status = 'rejected' AND DATE(created_at) = CURDATE()),
                (SELECT COUNT(*) FROM emergency_events WHERE DATE(started_at) = CURDATE())
             ON DUPLICATE KEY UPDATE
                vehicles = VALUES(vehicles), messages = VALUES(messages), sent = VALUES(sent),
                failed = VALUES(failed), rejected = VALUES(rejected), emergencies = VALUES(emergencies)"
        );
        return 1;
    }
}
