<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Scheduling\ScheduleRepository;
use PDO;

final class PdoScheduleRepository implements ScheduleRepository
{
    public function __construct(private PDO $pdo) {}
    public function dueJobs(): array
    {
        return $this->pdo->query("SELECT * FROM jobs WHERE is_enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW())
             ORDER BY FIELD(job_key, 'reconcile','emergency_poll','flush_outbox','poll_messages','offline_sweep','purge_delivered','fleet_sync','backup','retention','audit_rollup')")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function dueBuckets(): array
    {
        return $this->pdo->query("SELECT * FROM automation_buckets WHERE is_enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) ORDER BY COALESCE(next_run_at, '1970-01-01'), id")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function orphanTimedOutBuckets(): void
    {
        $this->pdo->exec("UPDATE automation_runs SET status = 'orphaned', finished_at = NOW(), error_message = 'run timed out (orphan cleanup)'
             WHERE status = 'running' AND started_at < NOW() - INTERVAL 3 MINUTE");
    }
    public function startJob(array $data): int { return $this->insert('job_runs', $data); }
    public function updateJobRun(int $id, array $data): void { $this->update('job_runs', $id, $data); }
    public function updateJob(int $id, array $data): void { $this->update('jobs', $id, $data); }
    public function startBucket(array $data): int { return $this->insert('automation_runs', $data); }
    public function updateBucketRun(int $id, array $data): void { $this->update('automation_runs', $id, $data); }
    public function updateBucket(int $id, array $data): void { $this->update('automation_buckets', $id, $data); }
    private function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_map($this->column(...), array_keys($data)));
        $slots = implode(', ', array_fill(0, count($data), '?'));
        $this->pdo->prepare("INSERT INTO $table ($columns) VALUES ($slots)")->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }
    private function update(string $table, int $id, array $data): void
    {
        $assignments = implode(', ', array_map(fn(string $key): string => $this->column($key) . ' = ?', array_keys($data)));
        $this->pdo->prepare("UPDATE $table SET $assignments WHERE id = ?")->execute([...array_values($data), $id]);
    }
    private function column(string $name): string
    {
        if (!preg_match('/^[a-z_]+$/D', $name)) throw new \InvalidArgumentException('Invalid schedule column');
        return '`' . $name . '`';
    }
}
