<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Latra\Domain\Collection\Vehicle;

interface FleetRepository
{
    public function findByUnitId(int $unitId): ?Vehicle;

    public function findById(int $vehicleId): ?Vehicle;

    /** A new vehicle has no local id until the repository assigns one. */
    public function insert(Vehicle $vehicle): int;

    public function update(Vehicle $vehicle): void;

    /** Create the disabled activity mappings the legacy service seeds per vehicle. */
    public function seedActivityMappings(int $vehicleId): void;
}
