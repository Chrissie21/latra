<?php
declare(strict_types=1);

namespace Latra\Domain\Emergency;

use Latra\Domain\Ingestion\ActivityDetectionPolicy;

/**
 * Emergency event lifecycle rules: which activities open an emergency, how an
 * event type is normalised, which activity an event records, and how an event
 * identifier is shaped.
 */
final class EmergencyEventPolicy
{
    /** Emergency event types that trigger high-frequency capture. */
    public const TYPES = ['overspeed', 'panic', 'accident'];

    /** Fallback type for any non-emergency trigger. */
    public const OTHER = 'other';

    public static function typeForActivity(int $activityId): ?string
    {
        return match ($activityId) {
            ActivityDetectionPolicy::SPEEDING => 'overspeed',
            ActivityDetectionPolicy::PANIC_DRIVER,
            ActivityDetectionPolicy::PANIC_PASSENGER => 'panic',
            ActivityDetectionPolicy::ACCIDENT => 'accident',
            default => null,
        };
    }

    public static function isEmergencyActivity(int $activityId): bool
    {
        return self::typeForActivity($activityId) !== null;
    }

    public static function typeOrOther(?string $eventType): string
    {
        return in_array($eventType, self::TYPES, true) ? $eventType : self::OTHER;
    }

    public static function defaultActivityFor(string $eventType): int
    {
        return match ($eventType) {
            'overspeed' => ActivityDetectionPolicy::SPEEDING,
            'accident' => ActivityDetectionPolicy::ACCIDENT,
            default => ActivityDetectionPolicy::PANIC_DRIVER,
        };
    }

    public static function activityFor(?int $activityId, string $eventType): int
    {
        return $activityId ?: self::defaultActivityFor($eventType);
    }

    public static function identifier(int $vehicleId, string $randomHex): string
    {
        return 'EMG-' . $vehicleId . '-' . strtoupper($randomHex);
    }
}
