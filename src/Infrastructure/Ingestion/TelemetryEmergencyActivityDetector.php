<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\Emergency\EmergencyActivityDetector;
use Latra\Domain\Emergency\EmergencyEventPolicy;

/**
 * Re-detects an emergency condition from a raw fetched source message using the
 * legacy detection inputs: unresolved parameters, no activity allow-list, the
 * vehicle's configured speed limit.
 */
final class TelemetryEmergencyActivityDetector implements EmergencyActivityDetector
{
    public function isEmergency(int $vehicleId, array $message): bool
    {
        $speed = isset($message['pos']['s']) ? round((float) $message['pos']['s'], 2) : 0.0;
        $detected = \EventDetector::detect($message['p'] ?? [], [], [
            'speed' => $speed,
            'activity_enabled' => [],
            'speed_limit' => \EventDetector::speedLimit($vehicleId),
        ]);

        return EmergencyEventPolicy::isEmergencyActivity((int) $detected['activity_id']);
    }
}
