<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Closure;
use Latra\Domain\Collection\FleetSyncPolicy;
use RuntimeException;

/**
 * Synchronises Wialon units into local vehicles.
 *
 * `all()` upserts every account unit and seeds its disabled activity mappings;
 * `one()` refreshes a single existing vehicle without touching mappings. A
 * failed login aborts before any write, and the session is closed on every
 * path that opened one.
 */
final class SyncFleetVehicles
{
    private Closure $log;
    private Closure $decodeUnit;

    public function __construct(
        private FleetUnitSource $source,
        private FleetRepository $repository,
        private FleetSyncClock $clock,
        callable $log,
        callable $decodeUnit,
    ) {
        $this->log = Closure::fromCallable($log);
        $this->decodeUnit = Closure::fromCallable($decodeUnit);
    }

    /** @return array{synced:int,updated:int} */
    public function all(): array
    {
        if (!$this->source->open()) {
            throw new RuntimeException('Wialon login failed');
        }

        $synced = 0;
        $updated = 0;
        try {
            foreach ($this->source->units() as $unit) {
                $unitId = (int) ($unit['id'] ?? 0);
                $mapped = FleetSyncPolicy::vehicle(
                    ($this->decodeUnit)($unit),
                    $this->clock->now(),
                    $this->source->engineSensorParam($unitId),
                );
                if ($mapped->wialon_unit_id === null) {
                    throw new RuntimeException('Wialon unit has no identity');
                }

                $existing = $this->repository->findByUnitId($mapped->wialon_unit_id);
                if ($existing) {
                    if ($existing->id === null) {
                        throw new RuntimeException('Existing vehicle has no local id');
                    }
                    $vehicleId = $existing->id;
                    $this->repository->update($existing->withSynced($mapped));
                    $updated++;
                } else {
                    $vehicleId = $this->repository->insert($mapped);
                    $synced++;
                }
                $this->repository->seedActivityMappings($vehicleId);
            }
        } finally {
            $this->source->close();
        }

        ($this->log)($synced, $updated);
        return ['synced' => $synced, 'updated' => $updated];
    }

    /** Refresh one existing vehicle; the caller owns the "not found" contract. */
    public function one(int $vehicleId): void
    {
        $vehicle = $this->repository->findById($vehicleId);
        if (!$vehicle) {
            throw new RuntimeException('Vehicle not found');
        }

        if (!$this->source->open()) {
            throw new RuntimeException('Wialon login failed');
        }

        try {
            $unitId = $vehicle->wialon_unit_id;
            if ($unitId === null) {
                return;
            }
            $unit = $this->source->unit($unitId);
            if ($unit) {
                $mapped = FleetSyncPolicy::vehicle(
                    ($this->decodeUnit)($unit),
                    $this->clock->now(),
                    $this->source->engineSensorParam($unitId),
                );
                $this->repository->update($vehicle->withSynced($mapped));
            }
        } finally {
            $this->source->close();
        }
    }
}
