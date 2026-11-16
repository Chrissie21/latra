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

    public function __construct(
        private FleetUnitSource $source,
        private FleetRepository $repository,
        private FleetSyncClock $clock,
        callable $log,
    ) {
        $this->log = Closure::fromCallable($log);
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
                $row = FleetSyncPolicy::vehicleRow(
                    $unit,
                    $this->clock->now(),
                    $this->source->engineSensorParam($unitId),
                );

                $existing = $this->repository->findByUnitId((int) $row['wialon_unit_id']);
                if ($existing) {
                    $vehicleId = (int) $existing['id'];
                    $this->repository->update($vehicleId, $row);
                    $updated++;
                } else {
                    $vehicleId = $this->repository->insert($row);
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
            $unitId = (int) $vehicle['wialon_unit_id'];
            $unit = $this->source->unit($unitId);
            if ($unit) {
                $this->repository->update($vehicleId, FleetSyncPolicy::vehicleRow(
                    $unit,
                    $this->clock->now(),
                    $this->source->engineSensorParam($unitId),
                ));
            }
        } finally {
            $this->source->close();
        }
    }
}
