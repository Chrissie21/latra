<?php
namespace Latra\Application\DeviceStatus;

use Closure;
use Latra\Domain\Ingestion\DeviceStatusPolicy;

final class MarkOfflineDevices
{
    private Closure $now;
    public function __construct(private HealthRepository $repository, private DeviceStatusEffects $effects,
        private DeviceStatusClock $clock, callable $now) { $this->now = Closure::fromCallable($now); }
    public function run(): int
    {
        $marked = 0;
        foreach ($this->repository->staleOnlineDevices(($this->now)() - DeviceStatusPolicy::OFFLINE_SECONDS) as $row) {
            $id = (int)$row['vehicle_id'];
            $this->repository->markDeviceOffline($id, $this->clock->now());
            $this->effects->recordTransition($id,'DEVICE_OFFLINE','Device lost connection (heartbeat expired)');
            $marked++;
        }
        return $marked;
    }
}
