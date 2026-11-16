<?php
namespace Latra\Application\DeviceStatus;

interface HealthRepository
{
    public function activeVehicles(): array;
    public function lastGpsTime(int $vehicleId): ?int;
    public function saveGpsHealth(int $vehicleId, ?int $lastGps, ?string $lastGpsAt, int $commTime, string $status, ?int $minutes): void;
    public function staleOnlineDevices(int $cutoff): array;
    public function markDeviceOffline(int $vehicleId, string $at): void;
}
