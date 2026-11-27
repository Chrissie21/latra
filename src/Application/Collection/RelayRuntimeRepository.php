<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Latra\Domain\Collection\RelaySettings;
use Latra\Domain\Collection\Vehicle;

/**
 * Composition data required by the relay coordinator: enrollment budgets, stream
 * cursors, queue accounting, owning vehicle identity and activity gates.
 */
interface RelayRuntimeRepository
{
    /** @return Vehicle[] */
    public function activeVehiclesForBucket(int $bucketId): array;

    /** @return Vehicle[] */
    public function activeVehiclesForEventCollection(): array;

    public function enabledEventActivities(): array;

    /** Enrolled relay settings for one vehicle, or null when not enrolled. */
    public function relaySettings(int $vehicleId): ?RelaySettings;

    /** Independent stream cursors plus unresolved/held/byte queue totals. */
    public function relayStatus(string $unit): array;

    /** Event-mapped activities that may be delivered from the event stream. */
    public function enabledEventActivityIds(): array;

    /** Bucket activities currently enabled for one vehicle. */
    public function allowedBucketActivities(int $vehicleId): array;

    /** Distinct units with deliverable queue rows, oldest attempt first. */
    public function pendingUnits(int $now, int $limit): array;

    /** Active vehicle owning a Wialon unit, if any. */
    public function activeVehicleForUnit(int $unitId): ?Vehicle;

    public function vehicle(int $vehicleId): ?Vehicle;

    /** Resolve identity from a payload that carries no vehicle id. */
    public function vehicleByIdentity(?string $vehicleRegNo, ?string $imei): ?Vehicle;
}
