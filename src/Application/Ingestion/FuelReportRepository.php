<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Collection\Vehicle;

interface FuelReportRepository
{
    /** @return Vehicle[] */
    public function vehicles(): array;
}
