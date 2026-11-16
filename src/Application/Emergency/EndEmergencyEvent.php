<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/**
 * Ends an active emergency event and emits the end effects for the stored row.
 */
final class EndEmergencyEvent
{
    public function __construct(
        private EmergencyEventRepository $repository,
        private EmergencyEventEffects $effects,
        private EmergencyEventClock $clock,
    ) {
    }

    public function end(int $eventId): void
    {
        $this->repository->end($eventId, $this->clock->now());

        $event = $this->repository->findById($eventId);
        if ($event === null) {
            return;
        }

        $this->effects->ended($event);
    }
}
