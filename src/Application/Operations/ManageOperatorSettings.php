<?php
declare(strict_types=1);

namespace Latra\Application\Operations;

/**
 * Operational settings changes dispatched by the API boundary.
 *
 * Activity toggles upsert the per-vehicle mapping; vehicle toggles change only the
 * active flag. Neither operation rewrites collection schedules or relay budgets.
 */
final class ManageOperatorSettings
{
    public function __construct(private OperatorActionRepository $repository) {}

    public function setActivity(int $vehicleId, int $activityId, int $enabled): void
    {
        $existing = $this->repository->activityMapping($vehicleId, $activityId);
        if ($existing) {
            $this->repository->updateActivityMapping((int) $existing['id'], $enabled);
            return;
        }
        $this->repository->insertActivityMapping($vehicleId, $activityId, $enabled);
    }

    public function setVehicleActive(int $vehicleId, int $active): void
    {
        $this->repository->setVehicleActive($vehicleId, $active);
    }

    public function deletePushLog(int $id): void
    {
        $this->repository->deletePushLog($id);
    }
}
