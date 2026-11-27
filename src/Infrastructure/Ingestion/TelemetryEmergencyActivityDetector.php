<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\Emergency\EmergencyActivityDetector;
use Latra\Domain\Emergency\EmergencyEventPolicy;
use Latra\Domain\Ingestion\ActivityDetectionPolicy;
use Latra\Infrastructure\Persistence\PdoIngestionSettingsRepository;
use Latra\Infrastructure\Wialon\WialonSignalReader;

/**
 * Re-detects an emergency condition from a raw fetched source message. It uses the
 * same detection inputs the ingestion path uses: unresolved parameters, no activity
 * allow-list and the vehicle's configured speed limit.
 */
final class TelemetryEmergencyActivityDetector implements EmergencyActivityDetector
{
    private WialonSignalReader $signals;

    public function __construct(private PdoIngestionSettingsRepository $settings,
        ?WialonSignalReader $signals = null) {
        $this->signals = $signals ?? new WialonSignalReader();
    }

    public function isEmergency(int $vehicleId, array $message): bool
    {
        $speed = isset($message['pos']['s']) ? round((float) $message['pos']['s'], 2) : 0.0;
        $detected = ActivityDetectionPolicy::detect(
            $this->signals->readSource($message['p'] ?? [], null, $speed), [], [
                'speed' => $speed,
                'activity_enabled' => [],
                'speed_limit' => $this->settings->speedLimit($vehicleId),
            ]
        );

        return EmergencyEventPolicy::isEmergencyActivity((int) $detected['activity_id']);
    }
}
