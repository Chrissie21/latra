<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Collection\Vehicle;

interface ScheduledVehicleRepository
{
    /** @return Vehicle[] */
    public function ordinaryVehicles(): array;
    /** @return Vehicle[] */
    public function emergencyVehicles(): array;
}
