<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Maintenance\DataCleanupRepository;
use Latra\Domain\Maintenance\DataCleanupPolicy;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PdoDataCleanupRepository implements DataCleanupRepository
{
    public function __construct(private PDO $pdo) {}
    public function clearCategory(string $category,array $criteria): array
    {
        [$table,$date,$fields]=match ($category) {
            'message_log'=>['message_log','data_processed_at',['activity'=>'activity_id','status'=>'latra_status']],
            'validation_errors'=>['validation_errors','created_at',[]],
            'emergency_events'=>['emergency_events','started_at',['event_type'=>'event_type','status'=>'status']],
            'emergency_queue'=>['emergency_queue','created_at',['event'=>'event_id']],
            'device_status'=>['device_status',null,[]],
            'gps_health'=>['gps_health',null,[]],
            'push_log'=>['push_log','created_at',['response_status'=>'response_status']],
            'audit_log'=>['audit_log','created_at',[]],
            'automation_runs'=>['automation_runs','started_at',[]],
            default=>throw new InvalidArgumentException("Unknown data category: $category"),
        };
        $conditions=[];$params=[];
        $ids=$this->vehicleIds($criteria);
        if ($ids!==null) {
            $conditions[]='vehicle_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
            $params=$ids;
        }
        foreach ($fields as $field=>$column) {
            if (isset($criteria[$field])) { $conditions[]=$column.' = ?';$params[]=$criteria[$field]; }
        }
        if ($date!==null) foreach (['from'=>'>=','to'=>'<=','older_than'=>'<'] as $field=>$operator) {
            if (isset($criteria[$field])) { $conditions[]=$date.' '.$operator.' ?';$params[]=$criteria[$field]; }
        }
        $where=$conditions ? ' WHERE '.implode(' AND ',$conditions) : '';
        $owned=$category==='emergency_events' && !$this->pdo->inTransaction();
        if ($owned) $this->pdo->beginTransaction();
        try {
            $queueDeleted=0;
            if ($category==='emergency_events') {
                $stmt=$this->pdo->prepare('SELECT id FROM emergency_events'.$where);$stmt->execute($params);
                $events=$stmt->fetchAll(PDO::FETCH_COLUMN);
                if ($events) {
                    $stmt=$this->pdo->prepare('DELETE FROM emergency_queue WHERE emergency_event_id IN ('.implode(',',array_fill(0,count($events),'?')).')');
                    $stmt->execute($events);$queueDeleted=$stmt->rowCount();
                }
            }
            $stmt=$this->pdo->prepare('DELETE FROM '.$table.$where);$stmt->execute($params);
            $result=['deleted'=>$stmt->rowCount()];
            if ($category==='emergency_events') $result['queue_deleted']=$queueDeleted;
            if ($owned) $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owned && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
    private function vehicleIds(array $criteria): ?array
    {
        $conditions=[];$params=[];
        foreach (['vehicle'=>'vehicle_reg_no','imei'=>'imei'] as $field=>$column) {
            if (isset($criteria[$field])) { $conditions[]=$column.' LIKE ?';$params[]='%'.$criteria[$field].'%'; }
        }
        if (!$conditions) return null;
        $stmt=$this->pdo->prepare('SELECT id FROM vehicles WHERE '.implode(' OR ',$conditions));$stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [0];
    }
    public function resetGenerated(string $table): int
    {
        if (!in_array($table,DataCleanupPolicy::RESET_ORDER,true)) throw new InvalidArgumentException('Protected cleanup table');
        if ($this->pdo->inTransaction()) throw new RuntimeException('Generated-data reset cannot run inside a transaction');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $count=(int)$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
            $this->pdo->exec('TRUNCATE TABLE '.$table);
            return $count;
        } finally { $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); }
    }
    public function resetBucketCounters(): void
    {
        $this->pdo->exec('UPDATE automation_buckets SET run_count=0,fail_count=0,consecutive_failures=0,last_error=NULL,last_run_at=NULL,next_run_at=NULL');
    }
}
