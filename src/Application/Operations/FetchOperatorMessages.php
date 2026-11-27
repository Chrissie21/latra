<?php
declare(strict_types=1);

namespace Latra\Application\Operations;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Operator fetch always requests a complete relay window.
 *
 * Direct retained-source imports were a pre-relay path; delivery is now relay-only,
 * so a request without usable bounds fails instead of silently writing sources.
 */
final class FetchOperatorMessages
{
    private Closure $poll;

    public function __construct(private OperatorReadRepository $repository, callable $poll)
    {
        $this->poll = Closure::fromCallable($poll);
    }

    public function one(int $vehicleId): array
    {
        if (!$vehicleId) throw new RuntimeException('Vehicle ID required');
        $vehicle = $this->repository->vehicle($vehicleId);
        if (!$vehicle) throw new RuntimeException('Vehicle not found');
        $collected = ($this->poll)($vehicle, 86400, 100);
        return ['success' => true,
            'message' => $collected['fetched'] . ' source reports collected; '
                . $collected['rejected'] . ' newly held for review.'] + $collected;
    }

    public function bulk(array $vehicleIds, callable $progress): void
    {
        if (!$vehicleIds) throw new RuntimeException('No vehicle IDs provided');
        $total = count($vehicleIds);
        $success = 0;
        $failed = 0;
        $progress(['type' => 'init', 'total' => $total, 'message' => 'Connected. Starting vehicle fetch...']);
        foreach (array_values($vehicleIds) as $index => $id) {
            $vehicle = $this->repository->vehicle((int) $id);
            if (!$vehicle) {
                $progress(['type' => 'progress', 'current' => $index + 1, 'total' => $total,
                    'vehicle' => 'Vehicle #' . $id, 'inserted' => 0, 'status' => 'fail']);
                $failed++;
                continue;
            }
            try {
                $collected = ($this->poll)($vehicle, 86400, 100);
                $progress(['type' => 'progress', 'current' => $index + 1, 'total' => $total,
                    'vehicle' => $vehicle->vehicle_reg_no, 'status' => 'ok'] + $collected);
                $success++;
            } catch (Throwable $error) {
                $progress(['type' => 'progress', 'current' => $index + 1, 'total' => $total,
                    'vehicle' => $vehicle->vehicle_reg_no, 'inserted' => 0, 'status' => 'fail']);
                $failed++;
            }
        }
        $progress(['type' => 'done', 'success' => $success, 'failed' => $failed]);
    }
}
