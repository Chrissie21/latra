<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

interface EmergencyEventRepository
{
    public function activeByType(int $vehicleId, string $eventType): ?array;

    public function activeForVehicle(int $vehicleId): ?array;

    public function findById(int $eventId): ?array;

    public function create(array $event): int;

    public function createRecord(array $record): bool;

    public function incrementRecordCount(int $eventId): void;

    public function end(int $eventId, string $endedAt): void;
}
