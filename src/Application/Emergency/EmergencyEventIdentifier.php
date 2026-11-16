<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

interface EmergencyEventIdentifier
{
    public function next(int $vehicleId): string;
}
