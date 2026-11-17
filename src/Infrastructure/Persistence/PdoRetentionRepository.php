<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Maintenance\RetentionRepository;
use PDO;
use Throwable;

/** Bounded SQL pages on the captured cleanup connection, preserving unresolved dependencies. */
final class PdoRetentionRepository implements RetentionRepository
{
    public function __construct(private PDO $pdo) {}
    public function acquire(): bool
    {
        return (int) $this->pdo->query("SELECT GET_LOCK('latra-retention', 0)")->fetchColumn() === 1;
    }
    public function release(): void { $this->pdo->query("SELECT RELEASE_LOCK('latra-retention')"); }
    public function deleteEligible(string $category, string $cutoff, ?int $maxBatches = null): int
    {
        [$table, $condition] = match ($category) {
            'delivered_receipts' => ['push_log', "response_status = '1' AND created_at < ? AND " . self::receiptSafe()],
            'old_receipts' => ['push_log', 'created_at < ? AND ' . self::receiptSafe()],
            'delivered_sources' => ['message_log', "latra_status = 'sent' AND pushed_at IS NOT NULL AND pushed_at < ? AND " . self::sourceSafe()],
            'archive_sources' => ['message_log', "created_at < ? AND latra_status = 'sent' AND " . self::sourceSafe()],
            'delivered_queue' => ['outbox', "status = 'sent' AND updated_at < ? AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = outbox.message_id)"],
            'old_queue' => ['outbox', "status = 'sent' AND created_at < ? AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = outbox.message_id)"],
            'wialon_events' => ['wialon_events', "status = 'sent' AND created_at < ? AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = wialon_events.message_log_id)"],
            'webhook_events' => ['webhook_events', "status = 'sent' AND created_at < ?"],
            'automation_run_items' => ['automation_run_items', "status IN ('sent','skipped') AND EXISTS (SELECT 1 FROM automation_runs r WHERE r.id = automation_run_items.run_id AND r.started_at < ?)"],
            'automation_runs' => ['automation_runs', "started_at < ? AND NOT EXISTS (SELECT 1 FROM automation_run_items r WHERE r.run_id = automation_runs.id)"],
            'job_runs' => ['job_runs', 'started_at < ?'],
            'audit_log' => ['audit_log', 'created_at < ?'],
            default => throw new \InvalidArgumentException('Unknown retention category'),
        };
        return $this->deletePages($table, $condition, [$cutoff], $maxBatches, $category === 'archive_sources');
    }

    /** Protect records still required by any unfinished delivery path. */
    private static function sourceSafe(): string {
        return "NOT EXISTS (SELECT 1 FROM outbox o WHERE o.message_id = message_log.id AND o.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM wialon_events e WHERE e.message_log_id = message_log.id AND e.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM automation_run_items r WHERE r.message_log_id = message_log.id AND r.status NOT IN ('sent','skipped'))";
    }

    /** Keep attempt evidence while its source or dependent delivery remains unresolved. */
    private static function receiptSafe(): string {
        return "NOT EXISTS (SELECT 1 FROM outbox o WHERE o.message_id = push_log.message_log_id AND o.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = push_log.message_log_id AND m.latra_status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM wialon_events e WHERE e.message_log_id = push_log.message_log_id AND e.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM automation_run_items r WHERE r.message_log_id = push_log.message_log_id AND r.status NOT IN ('sent','skipped'))";
    }

    /** Reuse the original purge page size; uncapped callers still finish all eligible history. */
    private function deletePages(string $table, string $condition, array $params, ?int $maxBatches = null, bool $archive = false): int {
        $pdo = $this->pdo;
        $removed = 0;
        for ($batch = 0; $maxBatches === null || $batch < $maxBatches; $batch++) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT id FROM $table WHERE $condition ORDER BY id LIMIT 2000 FOR UPDATE");
                $stmt->execute($params);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!$ids) { $pdo->commit(); break; }
                $in = implode(',', array_fill(0, count($ids), '?'));
                if ($archive) {
                    // An archive/schema conflict rolls back the move; do not silently discard source data.
                    $pdo->prepare("INSERT INTO message_log_archive SELECT * FROM message_log WHERE id IN ($in)")->execute($ids);
                }
                $stmt = $pdo->prepare("DELETE FROM $table WHERE id IN ($in) AND ($condition)");
                $stmt->execute(array_merge($ids, $params));
                $count = $stmt->rowCount();
                $pdo->commit();
                $removed += $count;
                if ($count === 0) break;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return $removed;
    }

    /** Page the original CLI counter expiry using its composite primary key. */
    public function deleteExpiredRateLimits(int $cutoff): int {
        $pdo = $this->pdo;
        $removed = 0;
        while (true) {
            $stmt = $pdo->prepare('SELECT rate_key, window_start FROM rate_limits WHERE window_start < ? ORDER BY window_start LIMIT 2000');
            $stmt->execute([$cutoff]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return $removed;
            $delete = $pdo->prepare('DELETE FROM rate_limits WHERE rate_key = ? AND window_start = ?');
            foreach ($rows as $row) {
                $delete->execute([$row['rate_key'], $row['window_start']]);
                $removed += $delete->rowCount();
            }
        }
    }
}
