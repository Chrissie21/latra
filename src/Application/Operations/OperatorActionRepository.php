<?php
declare(strict_types=1);

namespace Latra\Application\Operations;

use Latra\Domain\Collection\Vehicle;

/** Operator mutations and health reads that the API boundary dispatches to. */
interface OperatorActionRepository
{
    /** Message joined with its owning vehicle, shaped for relay payload mapping. */
    public function messageForRelay(int $messageId): ?array;
    /** Message joined with its owning vehicle, shaped for operator payload submission. */
    public function messageForSubmission(int $messageId): ?array;
    public function vehicle(int $vehicleId): ?Vehicle;
    public function latestMessage(int $vehicleId): ?array;
    public function pushLog(int $id): ?array;
    public function deletePushLog(int $id): void;
    public function activityMapping(int $vehicleId, int $activityId): ?array;
    public function insertActivityMapping(int $vehicleId, int $activityId, int $enabled): void;
    public function updateActivityMapping(int $id, int $enabled): void;
    public function setVehicleActive(int $vehicleId, int $active): void;
    /** @return array{last_run_at:?string,enabled_buckets:int,backlog:int,dead_letter:int} */
    public function schedulerHealth(): array;
}
