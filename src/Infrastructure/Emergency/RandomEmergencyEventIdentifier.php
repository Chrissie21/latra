<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Emergency;

use Latra\Application\Emergency\EmergencyEventIdentifier;
use Latra\Domain\Emergency\EmergencyEventPolicy;

final class RandomEmergencyEventIdentifier implements EmergencyEventIdentifier
{
    public function next(int $vehicleId): string
    {
        return EmergencyEventPolicy::identifier($vehicleId, bin2hex(random_bytes(5)));
    }
}
