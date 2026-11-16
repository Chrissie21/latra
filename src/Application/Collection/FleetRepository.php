<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface FleetRepository
{
    public function findByUnitId(int $unitId): ?array;

    public function findById(int $vehicleId): ?array;

    public function insert(array $vehicle): int;

    public function update(int $vehicleId, array $vehicle): void;

    /** Create the disabled activity mappings the legacy service seeds per vehicle. */
    public function seedActivityMappings(int $vehicleId): void;
}
