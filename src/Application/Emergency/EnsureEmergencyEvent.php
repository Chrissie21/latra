<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

use Latra\Domain\Emergency\EmergencyEventPolicy;

/**
 * Returns the vehicle's active event of a given type, creating it when absent.
 *
 * Mirrors the legacy lifecycle: look up an active event of the same type first,
 * normalise unknown types to "other", derive the recorded activity when the
 * caller does not supply one, and only emit start effects for a new event.
 */
final class EnsureEmergencyEvent
{
    public function __construct(
        private EmergencyEventRepository $repository,
        private EmergencyEventEffects $effects,
        private EmergencyEventClock $clock,
        private EmergencyEventIdentifier $identifier,
    ) {
    }

    public function ensure(
        int $vehicleId,
        ?string $eventType,
        ?string $reason = null,
        ?float $speed = null,
        ?int $activityId = null,
    ): ?array {
        $type = EmergencyEventPolicy::typeOrOther($eventType);
        $active = $this->repository->activeByType($vehicleId, $type);
        if ($active !== null) {
            return $active;
        }

        $eventId = $this->identifier->next($vehicleId);
        $id = $this->repository->create([
            'event_id' => $eventId,
            'vehicle_id' => $vehicleId,
            'event_type' => $type,
            'activity_id' => EmergencyEventPolicy::activityFor($activityId, $type),
            'status' => 'active',
            'started_at' => $this->clock->now(),
            'trigger_reason' => $reason,
            'max_speed_kmh' => $speed,
        ]);

        $event = $this->repository->findById($id);
        if ($event === null) {
            return null;
        }

        $this->effects->started($event);
        return $event;
    }
}
