<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/**
 * Re-classifies a fetched source message to decide whether a vehicle is still
 * in the emergency condition that opened its active event.
 */
interface EmergencyActivityDetector
{
    public function isEmergency(int $vehicleId, array $message): bool;
}
