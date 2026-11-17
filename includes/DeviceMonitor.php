<?php
/**
 * Device status monitor.
 */
require_once __DIR__ . '/autoload.php';

class DeviceMonitor {

    // Offline after 15 minutes without contact.
    const OFFLINE_SECONDS = \Latra\Domain\Ingestion\DeviceStatusPolicy::OFFLINE_SECONDS;

    // Low battery warning level.
    const BATTERY_LOW_MV = \Latra\Domain\Ingestion\DeviceStatusPolicy::BATTERY_LOW_MV;

    // Low battery percent.
    const BATTERY_LOW_PCT = \Latra\Domain\Ingestion\DeviceStatusPolicy::BATTERY_LOW_PCT;

    // External power low level.
    const EXT_POWER_LOW_MV = \Latra\Domain\Ingestion\DeviceStatusPolicy::EXT_POWER_LOW_MV;

    // Update a device from the latest message.
    public static function updateFromMessage(int $vehicleId, array $params, int $commTime): void {
        $useCase = new \Latra\Application\DeviceStatus\UpdateDeviceStatus(
            new \Latra\Infrastructure\Persistence\DatabaseDeviceStatusRepository(),
            new \Latra\Infrastructure\Ingestion\AuditDeviceStatusEffects(),
            new \Latra\Infrastructure\Ingestion\SystemDeviceStatusClock(),
        );
        $useCase->execute($vehicleId, $params, $commTime);
    }

    private static function repository(): \Latra\Infrastructure\Persistence\PdoHealthRepository {
        return new \Latra\Infrastructure\Persistence\PdoHealthRepository(Database::getInstance());
    }
    public static function markOffline(): int {
        return (new \Latra\Application\DeviceStatus\MarkOfflineDevices(self::repository(),
            new \Latra\Infrastructure\Ingestion\AuditDeviceStatusEffects(),
            new \Latra\Infrastructure\Ingestion\SystemDeviceStatusClock(), static fn(): int => time()))->run();
    }
}
