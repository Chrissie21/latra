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
    public static function upsert(int $vehicleId, ?int $lastGps, int $commTime, string $status, ?int $mins): void {
        self::repository()->saveGpsHealth($vehicleId,$lastGps,$lastGps===null ? null : date(DATE_FORMAT,$lastGps),$commTime,$status,$mins);
    }
    public static function freshCount(): int { return self::repository()->freshCount(); }
    public static function summary(): array { return self::repository()->gpsSummary(); }
    public static function staleVehicles(): array { return self::repository()->staleVehicles(); }
}
