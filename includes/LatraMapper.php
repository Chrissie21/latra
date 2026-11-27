<?php
/** Builds LATRA payloads and trip-stat data. */
use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

require_once __DIR__ . '/autoload.php';

class LatraMapper
{
    public const ACTIVITY_MOVEMENT = LatraPayloadPolicy::ACTIVITY_MOVEMENT;
    public const ACTIVITY_ENGINE_ON = LatraPayloadPolicy::ACTIVITY_ENGINE_ON;
    public const ACTIVITY_ENGINE_OFF = LatraPayloadPolicy::ACTIVITY_ENGINE_OFF;
    public const ACTIVITY_SPEEDING = LatraPayloadPolicy::ACTIVITY_SPEEDING;
    public const ACTIVITY_HASH_BRAKING = LatraPayloadPolicy::ACTIVITY_HASH_BRAKING;
    public const ACTIVITY_HASH_TURNING = LatraPayloadPolicy::ACTIVITY_HASH_TURNING;
    public const ACTIVITY_HASH_ACCEL = LatraPayloadPolicy::ACTIVITY_HASH_ACCEL;
    public const ACTIVITY_PANIC_DRIVER = LatraPayloadPolicy::ACTIVITY_PANIC_DRIVER;
    public const ACTIVITY_BATTERY_LOW = LatraPayloadPolicy::ACTIVITY_BATTERY_LOW;
    public const ACTIVITY_POWER_DISCONNECT = LatraPayloadPolicy::ACTIVITY_POWER_DISCONNECT;
    public const ACTIVITY_EXCESSIVE_IDLE = LatraPayloadPolicy::ACTIVITY_EXCESSIVE_IDLE;
    public const ACTIVITY_ACCIDENT = LatraPayloadPolicy::ACTIVITY_ACCIDENT;
    public const ACTIVITY_PANIC_PASSENGER = LatraPayloadPolicy::ACTIVITY_PANIC_PASSENGER;
    public const ACTIVITY_TAMPERING = LatraPayloadPolicy::ACTIVITY_TAMPERING;
    public const ACTIVITY_BLACK_BOX = LatraPayloadPolicy::ACTIVITY_BLACK_BOX;
    public const ACTIVITY_FUEL_REPORT = LatraPayloadPolicy::ACTIVITY_FUEL_REPORT;
    public const ACTIVITY_INVALID_SCAN = LatraPayloadPolicy::ACTIVITY_INVALID_SCAN;
    public const ACTIVITY_ENGINE_START = LatraPayloadPolicy::ACTIVITY_ENGINE_START;
    public const ACTIVITY_ENGINE_STOP = LatraPayloadPolicy::ACTIVITY_ENGINE_STOP;
    public const ACTIVITY_ENTER_BOUNDARY = LatraPayloadPolicy::ACTIVITY_ENTER_BOUNDARY;
    public const ACTIVITY_LEAVE_BOUNDARY = LatraPayloadPolicy::ACTIVITY_LEAVE_BOUNDARY;
    public const ACTIVITY_ENTER_CHECKPOINT = LatraPayloadPolicy::ACTIVITY_ENTER_CHECKPOINT;
    public const ACTIVITY_LEAVE_CHECKPOINT = LatraPayloadPolicy::ACTIVITY_LEAVE_CHECKPOINT;
    public const ACTIVITY_IBUTTON_REGULAR = LatraPayloadPolicy::ACTIVITY_IBUTTON_REGULAR;
    public const ACTIVITIES = LatraPayloadPolicy::ACTIVITIES;
    public const HARDWARE_FAULTS = LatraPayloadPolicy::HARDWARE_FAULTS;

    public static function mapVehicle(array $wialonUnit): array
    {
        return (new \Latra\Infrastructure\Wialon\WialonSourceReader())->unitFields($wialonUnit);
    }

    public static function buildPayload(
        Vehicle $vehicle,
        array $message,
        int $activityId,
        array $addonInfo = [],
        array $fuelInfo = [],
    ): array {
        return LatraPayloadPolicy::buildPayload(
            $vehicle,
            \Latra\Domain\Ingestion\TelemetryMessage::fromStored($message),
            $activityId,
            $addonInfo,
            $fuelInfo,
        )->toWire();
    }

    public static function buildAddonInfo(int $activityId, array $wialonParams = [], array $customData = []): ?array
    {
        return (new \Latra\Application\Ingestion\BuildActivityAddon(
            static fn(string $event, array $context) => Logger::info($event, $context),
        ))->build($activityId, (new \Latra\Infrastructure\Wialon\WialonSourceReader())->decodeParameters($wialonParams), $customData);
    }

    public static function buildFuelInfo(array $wialonParams = [], array $customData = []): array
    {
        return LatraPayloadPolicy::buildFuelInfo(
            (new \Latra\Infrastructure\Wialon\WialonSourceReader())->decodeFuelEvidence($wialonParams),
            $customData,
        );
    }

    public static function activityRequiresAddon(int $activityId): bool
    {
        return LatraPayloadPolicy::activityRequiresAddon($activityId);
    }

    public static function getActivityName(int $activityId): string
    {
        return LatraPayloadPolicy::getActivityName($activityId);
    }

    public static function buildTripStats(int $vehicleId, int $engineOffMsgTime): array
    {
        return (new \Latra\Application\Ingestion\BuildTripStatistics(
            new \Latra\Infrastructure\Persistence\PdoTripStatisticsRepository(Database::getInstance()),
        ))->build($vehicleId, $engineOffMsgTime);
    }

    public static function validatePayload(array $payload): array
    {
        return LatraPayloadPolicy::validatePayload($payload);
    }
}
