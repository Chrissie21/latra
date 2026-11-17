<?php
require_once __DIR__ . '/autoload.php';

/** Compatibility API for the retained-source health monitor. */
class GpsMonitor {
    public const MAX_INTERVAL_SECONDS = \Latra\Domain\Ingestion\GpsHealthPolicy::MAX_INTERVAL_SECONDS;
    public const OFFLINE_SECONDS = \Latra\Domain\Ingestion\GpsHealthPolicy::OFFLINE_SECONDS;
    private static function repository(): \Latra\Infrastructure\Persistence\PdoHealthRepository {
        return new \Latra\Infrastructure\Persistence\PdoHealthRepository(Database::getInstance());
    }
    public static function refresh(): array {
        return (new \Latra\Application\DeviceStatus\RefreshGpsHealth(self::repository(),
            new \Latra\Infrastructure\Ingestion\SystemDeviceStatusClock(), static fn(): int => time()))->run();
    }
}
