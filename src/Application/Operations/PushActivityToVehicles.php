<?php
declare(strict_types=1);

namespace Latra\Application\Operations;

use Closure;
use Latra\Application\Ingestion\TelemetryMessageReader;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use RuntimeException;
use Throwable;

/**
 * Bulk activity submission for the SSE operator stream.
 *
 * Each vehicle is mapped, queued and reported independently; one failure never
 * aborts the remaining selections.
 */
final class PushActivityToVehicles
{
    private Closure $payloadBuilder;
    private Closure $addonBuilder;
    private Closure $fuelBuilder;
    private Closure $tripBuilder;
    private Closure $queue;
    private Closure $now;

    public function __construct(private OperatorActionRepository $repository,
        callable $payloadBuilder, callable $addonBuilder, callable $fuelBuilder,
        callable $tripBuilder, callable $queue, callable $now,
        private TelemetryMessageReader $decoder)
    {
        $this->payloadBuilder = Closure::fromCallable($payloadBuilder);
        $this->addonBuilder = Closure::fromCallable($addonBuilder);
        $this->fuelBuilder = Closure::fromCallable($fuelBuilder);
        $this->tripBuilder = Closure::fromCallable($tripBuilder);
        $this->queue = Closure::fromCallable($queue);
        $this->now = Closure::fromCallable($now);
    }

    /** @param callable(array):void $progress */
    public function run(array $vehicleIds, int $activityId, callable $progress): array
    {
        if (!$vehicleIds) throw new RuntimeException('No vehicle IDs provided');
        if (!$activityId) throw new RuntimeException('Activity ID required');
        $total = count($vehicleIds);
        $success = 0;
        $failed = 0;
        $progress(['type' => 'init', 'total' => $total, 'message' => 'Connected. Starting LATRA push...']);

        foreach (array_values($vehicleIds) as $index => $vehicleId) {
            $vehicle = $this->repository->vehicle((int) $vehicleId);
            if (!$vehicle) {
                $progress(['type' => 'progress', 'current' => $index + 1, 'total' => $total,
                    'vehicle' => 'Vehicle #' . $vehicleId, 'latra_status' => 'fail']);
                $failed++;
                continue;
            }
            try {
                $latest = $this->repository->latestMessage((int) $vehicleId);
                $result = $this->submit($vehicle, $latest ?: [], $latest ?: null, $activityId);
                $progress(['type' => 'progress', 'current' => $index + 1, 'total' => $total,
                    'vehicle' => $vehicle->vehicle_reg_no, 'latra_status' => ($result['success'] ?? false) ? 'queued' : 'fail',
                    'message' => $result['message'] ?? 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                if (($result['success'] ?? false)) $success++; else $failed++;
            } catch (Throwable $error) {
                $progress(['type' => 'progress', 'current' => $index + 1, 'total' => $total,
                    'vehicle' => $vehicle->vehicle_reg_no, 'latra_status' => 'fail']);
                $failed++;
            }
        }

        $progress(['type' => 'done', 'success' => $success, 'failed' => $failed]);
        return ['success' => $success, 'failed' => $failed];
    }

    private function submit(\Latra\Domain\Collection\Vehicle $vehicle, array $source, ?array $latest, int $activityId): array
    {
        $custom = [];
        if ($activityId === LatraPayloadPolicy::ACTIVITY_ENGINE_OFF) {
            $custom = ($this->tripBuilder)((int) ($vehicle->id ?? 0),
                (int) ($source['wialon_msg_time'] ?? ($this->now)()));
        }
        $params = $latest ? $this->decoder->decodeStoredParameters($latest) : [];
        $addon = ($this->addonBuilder)($activityId, $params, $custom) ?? [];
        $fuel = $activityId === LatraPayloadPolicy::ACTIVITY_FUEL_REPORT
            ? ($this->fuelBuilder)($params) : [];
        $payload = ($this->payloadBuilder)($vehicle, $source, $activityId, $addon, $fuel);
        return ($this->queue)($vehicle, $payload);
    }
}
