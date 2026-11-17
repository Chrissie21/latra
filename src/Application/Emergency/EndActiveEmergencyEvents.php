<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/** Ends every active event of one type for a vehicle, one lifecycle at a time. */
final class EndActiveEmergencyEvents
{
    public function __construct(
        private EmergencyEventRepository $repository,
        private EndEmergencyEvent $endEvent,
    ) {
    }

    public function end(int $vehicleId, string $eventType): void
    {
        foreach ($this->repository->activeIdsByType($vehicleId, $eventType) as $eventId) {
            $this->endEvent->end((int) $eventId);
        }
    }
}
