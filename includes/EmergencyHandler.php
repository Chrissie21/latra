<?php
/**
 * Emergency event handler.
 *
 * Compatibility and composition boundary: the event lifecycle (creation,
 * buffering, closing) runs through the extracted application use cases and the
 * PDO persistence adapter. The public static API is unchanged.
 */
use Latra\Application\Emergency\BufferEmergencyRecord;
use Latra\Application\Emergency\CloseEndedEmergencyEvent;
use Latra\Application\Emergency\EndEmergencyEvent;
use Latra\Application\Emergency\EnsureEmergencyEvent;
use Latra\Domain\Emergency\EmergencyEventPolicy;
use Latra\Infrastructure\Emergency\RandomEmergencyEventIdentifier;
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
     * After a fetch, end any active emergency event for a vehicle whose
     * triggering condition no longer holds in the returned messages.
     */
    public static function closeEndedEvent(int $vehicleId, array $messages): void {
        (new CloseEndedEmergencyEvent(
            self::events(),
            new \Latra\Infrastructure\Ingestion\TelemetryEmergencyActivityDetector(),
            self::endUseCase(),
        ))->close($vehicleId, $messages);
    }

    /**
     * Ensure an active emergency event exists for a vehicle + type, or create one.
     * Returns the active event row (event_id field).
     */
    public static function ensureActiveEvent(int $vehicleId, string $eventType, ?string $reason = null, ?float $speed = null, ?int $activityId = null): ?array {
        return (new EnsureEmergencyEvent(
            self::events(),
            new \Latra\Infrastructure\Ingestion\WebhookEmergencyEventEffects(),
            new \Latra\Infrastructure\Ingestion\SystemEmergencyEventClock(),
            new RandomEmergencyEventIdentifier(),
        ))->ensure($vehicleId, $eventType, $reason, $speed, $activityId);
    }

    /**
     * Buffer a high-frequency GPS record against an active event.
     * Dedupes by (event_id, wialon_msg_time, vehicle_id). Returns true if inserted.
     */
    public static function bufferRecord(array $event, int $vehicleId, array $msg): bool {
        return (new BufferEmergencyRecord(self::events()))->buffer($event, $vehicleId, $msg);
    }

    /**
     * Close an active emergency event, returning the vehicle to normal cadence.
     */
    public static function endEvent(int $eventId): void {
        self::endUseCase()->end($eventId);
    }

    /**
     * End ALL active emergency events of a given type for a vehicle healthily.
     * Used when the triggering condition (e.g. speed) no longer applies.
     */
    public static function endActiveByVehicle(int $vehicleId, string $eventType): void {
        $rows = Database::fetchAll(
            "SELECT id FROM emergency_events WHERE vehicle_id = ? AND event_type = ? AND status = 'active'",
            [$vehicleId, $eventType]
        );
        foreach ($rows as $r) self::endEvent((int)$r['id']);
    }

    /**
     * Get the currently active emergency event for a vehicle (any type), if any.
     */
    public static function activeForVehicle(int $vehicleId): ?array {
        return self::events()->activeForVehicle($vehicleId);
    }

    /**
     * Count of currently active emergency events (fleet-wide).
     */
    public static function activeCount(): int {
        $row = Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE status = 'active'");
        return (int)($row['c'] ?? 0);
    }

    /** List all emergency events, newest first (for audit UI). */
    public static function listEvents(int $limit = 50): array {
        return Database::fetchAll(
            "SELECT e.*, v.vehicle_reg_no FROM emergency_events e
             JOIN vehicles v ON v.id = e.vehicle_id
             ORDER BY e.started_at DESC LIMIT $limit"
        );
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

    private static function endUseCase(): EndEmergencyEvent {
        return new EndEmergencyEvent(
            self::events(),
            new \Latra\Infrastructure\Ingestion\WebhookEmergencyEventEffects(),
            new \Latra\Infrastructure\Ingestion\SystemEmergencyEventClock(),
        );
    }
}
