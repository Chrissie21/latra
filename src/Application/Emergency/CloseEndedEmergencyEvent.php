<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/**
 * After a source fetch, closes a vehicle's active emergency event when none of
 * the fetched messages still satisfy an emergency condition.
 *
 * A vehicle with no active event, or an empty fetch, is left untouched so an
 * event is never closed from missing evidence.
 */
final class CloseEndedEmergencyEvent
{
    public function __construct(
        private EmergencyEventRepository $repository,
        private EmergencyActivityDetector $detector,
        private EndEmergencyEvent $endEvent,
    ) {
    }

    public function close(int $vehicleId, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $active = $this->repository->activeForVehicle($vehicleId);
        if ($active === null) {
            return;
        }

        foreach ($messages as $message) {
            if ($this->detector->isEmergency($vehicleId, $message)) {
                return;
            }
        }

        $this->endEvent->end((int) $active['id']);
    }
}
