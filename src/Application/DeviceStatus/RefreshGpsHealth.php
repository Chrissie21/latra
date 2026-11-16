<?php
namespace Latra\Application\DeviceStatus;

use Closure;
use Latra\Domain\Ingestion\GpsHealthPolicy;

final class RefreshGpsHealth
{
    private Closure $now;
    public function __construct(private HealthRepository $repository, private DeviceStatusClock $clock, callable $now)
    {
        $this->now = Closure::fromCallable($now);
    }
    public function run(): array
    {
        $vehicles = $this->repository->activeVehicles();
        $now = ($this->now)();
        $counts = GpsHealthPolicy::emptyCounts();
        foreach ($vehicles as $vehicle) {
            $id = (int)$vehicle['id'];
            $lastGps = $this->repository->lastGpsTime($id);
            $health = GpsHealthPolicy::classify($lastGps, $now);
            $counts[$health['status']]++;
            $this->repository->saveGpsHealth($id, $lastGps, $lastGps === null ? null : $this->clock->at($lastGps),
                $now, $health['status'], $health['minutes']);
        }
        return $counts;
    }
}
