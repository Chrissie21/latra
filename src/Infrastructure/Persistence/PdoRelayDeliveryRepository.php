<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Closure;
use Latra\Application\Delivery\RelayDeliveryRepository;
use Latra\Domain\Delivery\DeliveryClaim;
use Latra\Domain\Delivery\DeliveryClaimStatus;
use PDO;
use Latra\Domain\Delivery\VtsBatchPolicy;
use PDOStatement;
use Throwable;

final class PdoRelayDeliveryRepository implements RelayDeliveryRepository
{
    private ?Closure $cleanupHook;
    private ?string $ownedUnit = null;
    private ?int $connectionId = null;

    public function __construct(private PDO $database, ?callable $cleanupHook = null, private bool $vtsBatching = false)
    {
        $this->cleanupHook = $cleanupHook === null ? null : Closure::fromCallable($cleanupHook);
    }

    public function acquireUnitLock(string $unit): bool
    {
        if ($this->ownedUnit !== null) throw new \LogicException('Delivery repository already owns a unit');
        if ((int) $this->run('SELECT GET_LOCK(?, 0)', [$this->lockName($unit)])->fetchColumn() !== 1) return false;
        $this->ownedUnit = $unit;
        $this->connectionId = (int) $this->run('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertUnitLock($unit);
        return true;
    }

    public function assertUnitLock(string $unit): void
    {
        try {
            $owner = $this->run('SELECT IS_USED_LOCK(?)', [$this->lockName($unit)])->fetchColumn();
            $connection = (int) $this->run('SELECT CONNECTION_ID()')->fetchColumn();
        } catch (\PDOException $error) {
            throw new \Latra\Domain\Exception\OwnershipLost('Relay delivery lock connection was lost', 0, $error);
        }
        if ($this->ownedUnit !== $unit || $this->connectionId !== $connection || (int) $owner !== $connection) {
            throw new \Latra\Domain\Exception\OwnershipLost('Relay delivery lock ownership was lost');
        }
    }

    public function releaseUnitLock(string $unit): void
    {
        if ($this->ownedUnit !== $unit) return;
        try {
            // Never reconnect to release a lock. A dead connection releases its own locks.
            $this->run('SELECT RELEASE_LOCK(?)', [$this->lockName($unit)]);
        } catch (\PDOException) {
            // Preserve the original dispatch/finalization failure.
        } finally {
            $this->ownedUnit = null;
            $this->connectionId = null;
        }
    }

    public function claimPending(
        string $unit,
        int $now,
        int $maximumAttempts,
        ?callable $canSend = null,
    ): DeliveryClaim {
        $this->assertUnitLock($unit);
        $this->database->beginTransaction();
        try {
            $this->run('SELECT unit FROM relay_units WHERE unit=? FOR UPDATE', [$unit])->fetchColumn();
            $pendingReport = $this->run(
                'SELECT * FROM relay_pending WHERE unit=? ORDER BY source_time, id LIMIT 1',
                [$unit]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$pendingReport) {
                return $this->commitClaim(DeliveryClaimStatus::Empty);
            }

            if ($pendingReport['status'] !== 'pending' || (int) $pendingReport['next_attempt'] > $now) {
                return $this->commitClaim(DeliveryClaimStatus::Blocked);
            }

            if ($this->vtsBatching) {
                if ($this->retainInvalidVtsPayload($pendingReport)) return $this->commitClaim(DeliveryClaimStatus::Review);
                $decoded = json_decode($pendingReport['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded['items'] ?? null) || !$decoded['items'] || count($decoded['items']) > VtsBatchPolicy::MAX_ITEMS) {
                    $this->run("UPDATE relay_pending SET status='review', next_attempt=NULL, last_error='Invalid VTS request size' WHERE id=?", [$pendingReport['id']]);
                    $this->run("UPDATE relay_identity SET state='review' WHERE delivery_key=?", [$pendingReport['delivery_key']]);
                    return $this->commitClaim(DeliveryClaimStatus::Review);
                }
                if (!isset($decoded[VtsBatchPolicy::META]) && count($decoded['items'] ?? []) % 3 !== 0) {
                    if ((int) $pendingReport['attempts'] !== 0) {
                        // An attempted body is immutable, including when it predates the IDD fix.
                        $this->run("UPDATE relay_pending SET status='review', next_attempt=NULL, last_error='Previously attempted non-3*N payload requires review' WHERE id=?", [$pendingReport['id']]);
                        $this->run("UPDATE relay_identity SET state='review' WHERE delivery_key=?", [$pendingReport['delivery_key']]);
                        return $this->commitClaim(DeliveryClaimStatus::Review);
                    }
                    $rows = $this->run('SELECT * FROM relay_pending WHERE unit=? ORDER BY source_time, id LIMIT 198', [$unit])->fetchAll(PDO::FETCH_ASSOC);
                    $batch = VtsBatchPolicy::assemble($rows, $now);
                    if ($batch === null) return $this->commitClaim(DeliveryClaimStatus::Deferred);
                    $pendingReport['payload'] = json_encode($batch, JSON_THROW_ON_ERROR);
                    $this->run('UPDATE relay_pending SET payload=? WHERE id=?', [$pendingReport['payload'], $pendingReport['id']]);
                    foreach (VtsBatchPolicy::members($pendingReport) as $member) {
                        if ($member['id'] !== (int) $pendingReport['id']) {
                            $this->run("UPDATE relay_pending SET status='batched', payload='' WHERE id=?", [$member['id']]);
                        }
                    }
                }
                // A batch can include older queued members; validate the assembled body too.
                if ($this->retainInvalidVtsPayload($pendingReport)) return $this->commitClaim(DeliveryClaimStatus::Review);
            }

            if ($canSend !== null) {
                $eligible = $canSend($pendingReport);
                $this->assertUnitLock($unit);
                if (!$eligible) return $this->commitClaim(DeliveryClaimStatus::Deferred);
            }

            if ((int) $pendingReport['attempts'] >= $maximumAttempts) {
                $this->run(
                    "UPDATE relay_pending SET status='review', next_attempt=NULL, last_error='Attempt budget exhausted' WHERE id=?",
                    [$pendingReport['id']]
                );
                $this->run(
                    "UPDATE relay_identity SET state='review' WHERE delivery_key=?",
                    [$pendingReport['delivery_key']]
                );
                foreach (VtsBatchPolicy::members($pendingReport) as $member) {
                    $this->run("UPDATE relay_pending SET status='review', next_attempt=NULL, last_error='Attempt budget exhausted' WHERE id=?", [$member['id']]);
                    $this->run("UPDATE relay_identity SET state='review' WHERE delivery_key=?", [$member['delivery_key']]);
                }
                return $this->commitClaim(DeliveryClaimStatus::Review);
            }

            $this->assertUnitLock($unit);
            $this->run('UPDATE relay_pending SET attempts=attempts+1 WHERE id=?', [$pendingReport['id']]);
            $this->run(
                'UPDATE relay_units SET attempted_time=GREATEST(COALESCE(attempted_time,0),?) WHERE unit=?',
                [max(array_column(VtsBatchPolicy::members($pendingReport), 'source_time')), $unit]
            );
            $this->database->commit();
            return new DeliveryClaim(DeliveryClaimStatus::Ready, $pendingReport);
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function scheduleRetry(int $pendingId, string $deliveryKey, string $status, ?int $nextAttempt, string $error): void
    {
        $this->inTransaction(function () use ($pendingId, $deliveryKey, $status, $nextAttempt, $error): void {
            $this->run(
                'UPDATE relay_pending SET status=?, next_attempt=?, last_error=? WHERE id=?',
                [$status, $nextAttempt, $error, $pendingId]
            );
            $this->run('UPDATE relay_identity SET state=? WHERE delivery_key=?', [$status, $deliveryKey]);
            if ($status === 'review') {
                $report = $this->run('SELECT * FROM relay_pending WHERE id=?', [$pendingId])->fetch(PDO::FETCH_ASSOC);
                if ($report) foreach (VtsBatchPolicy::members($report) as $member) {
                    $this->run("UPDATE relay_pending SET status='review', next_attempt=NULL, last_error=? WHERE id=?", [$error, $member['id']]);
                    $this->run("UPDATE relay_identity SET state='review' WHERE delivery_key=?", [$member['delivery_key']]);
                }
            }
        });
    }

    public function completeAccepted(string $unit, array $pendingReport): void
    {
        $this->inTransaction(function () use ($unit, $pendingReport): void {
            foreach (VtsBatchPolicy::members($pendingReport) as $member) {
                $this->run("UPDATE relay_identity SET state='delivered' WHERE delivery_key=?", [$member['delivery_key']]);
                $this->run('UPDATE relay_units SET delivered_time=GREATEST(COALESCE(delivered_time,0),?) WHERE unit=?', [$member['source_time'], $unit]);
                $this->run('DELETE FROM relay_pending WHERE id=?', [$member['id']]);
            }
            if ($this->cleanupHook !== null) {
                ($this->cleanupHook)('cleanup');
            }
        });
    }

    /** Runs inside the claim transaction; review never consumes a transport attempt. */
    private function retainInvalidVtsPayload(array $report): bool
    {
        $payload=json_decode($report['payload'],true);
        $members=is_array($payload) ? VtsBatchPolicy::members($report) : [
            ['id'=>$report['id'],'delivery_key'=>$report['delivery_key']]];
        if(is_array($payload)) unset($payload[VtsBatchPolicy::META]);
        $errors=is_array($payload)
            ? \Latra\Domain\Ingestion\LatraPayloadPolicy::validatePayload($payload)
            : ['Stored payload must be a JSON object'];
        if(!$errors) return false;
        foreach($members as $member) {
            $this->run("UPDATE relay_pending SET status='review', next_attempt=NULL, last_error=? WHERE id=?",
                ['Required payload validation: '.substr(implode('; ',$errors),0,460),$member['id']]);
            $this->run("UPDATE relay_identity SET state='review' WHERE delivery_key=?",[$member['delivery_key']]);
        }
        return true;
    }

    private function commitClaim(DeliveryClaimStatus $status): DeliveryClaim
    {
        $this->database->commit();
        return new DeliveryClaim($status);
    }

    private function inTransaction(callable $operation): void
    {
        $this->assertUnitLock($this->ownedUnit ?? '');
        $this->database->beginTransaction();
        try {
            $operation();
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function run(string $sql, array $values = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function lockName(string $unit): string
    {
        return 'relay:' . substr(hash('sha256', $unit), 0, 56);
    }
}
