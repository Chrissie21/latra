<?php
/**
 * Emergency event handler.
 *
 * Compatibility and composition boundary: emergency type mapping, active
 * counts, queue flush and the relay handoff delegate to the extracted
 * application use cases and PDO persistence adapter.
 */
use Latra\Domain\Emergency\EmergencyEventPolicy;
use Latra\Infrastructure\Persistence\PdoEmergencyEventRepository;

require_once __DIR__ . '/autoload.php';

class EmergencyHandler {

    /** Emergency event types that trigger high-frequency capture. */
    const EMERGENCY_TYPES = EmergencyEventPolicy::TYPES;

    /** Map a detected LATRA activity_id to an emergency event type (or null). */
    public static function mapEmergencyType(int $activityId): ?string {
        return EmergencyEventPolicy::typeForActivity($activityId);
    }

    /**
     * Count of currently active emergency events (fleet-wide).
     */
    public static function activeCount(): int {
        return (new \Latra\Application\Emergency\ReadEmergencyEvents(self::events()))->activeCount();
    }

    /**
     * Submit all pending emergency_queue records for a vehicle's active event
     * to LATRA. Returns counts [submitted, queued, failed, skipped].
     */
    public static function flushPending(array $event, int $vehicleId): array {
        $useCase = new \Latra\Application\Emergency\FlushPendingEmergencyReports(
            new \Latra\Infrastructure\Persistence\PdoEmergencyQueueRepository(Database::getInstance()),
            static function (array $event, array $row, array $vehicle): array {
                $payload = LatraMapper::buildPayload($vehicle, [
                    'lat' => $row['lat'], 'lng' => $row['lng'], 'altitude' => $row['altitude'],
                    'speed' => $row['speed'], 'bearing' => $row['bearing'],
                    'satellite_count' => $row['satellite_count'], 'hdop' => $row['hdop'],
                    'd2d3' => null, 'mgs_id' => $row['wialon_msg_time'],
                    'wialon_msg_time' => $row['wialon_msg_time'],
                ], (int) $event['activity_id']);
                $payload['items'][0]['emergency_event_id'] = $event['event_id'];
                return $payload;
            },
            static fn(array $payload, int $ownerVehicleId): array => self::pushPayload($payload, $ownerVehicleId),
        );
        return $useCase->flush($event, $vehicleId);
    }

    /** Queue a full LATRA payload for the owning vehicle (relay is the only delivery path). */
    public static function pushPayload(array $payload, ?int $vehicleId = null): array {
        if (!class_exists('RelayRuntime', false) && !class_exists('RelayRuntime', true)) {
            return ['ok' => false, 'response' => 'Relay queueing is unavailable in this runtime'];
        }
        try {
            $result = RelayRuntime::queueForVehicle($payload, $vehicleId);
            return ['ok' => !empty($result['success']), 'queued' => (int) ($result['queued'] ?? 0),
                'recorded' => true, 'response' => $result['message'] ?? 'queued for relay delivery'];
        } catch (Throwable $e) {
            return ['ok' => false, 'response' => $e->getMessage()];
        }
    }

    private static function events(): PdoEmergencyEventRepository {
        return new PdoEmergencyEventRepository(Database::getInstance());
    }
}
