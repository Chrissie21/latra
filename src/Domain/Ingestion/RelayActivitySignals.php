<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/** Independent, explicitly named signals. Device IO interpretation remains profile-specific. */
final class RelayActivitySignals
{
    /** Canonical strict evidence names per activity. */
    public const FLAGS = [
        5 => ['harsh_braking'],
        6 => ['harsh_turning'],
        7 => ['harsh_acceleration'],
        8 => ['panic'],
        9 => ['battery_low'],
        10 => ['power_disconnected'],
        11 => ['excessive_idle'],
        12 => ['accident'],
        13 => ['passenger_panic'],
        14 => ['tamper'],
        15 => ['black_box'],
        16 => ['fuel_report'],
        17 => ['invalid_scan'],
        18 => ['engine_start'],
        19 => ['engine_stop'],
        20 => ['boundary_enter'],
        21 => ['boundary_exit'],
        22 => ['checkpoint_enter'],
        23 => ['checkpoint_exit'],
        24 => ['ibutton'],
    ];

    /** @return array<int,true> */
    public static function detect(TelemetrySignals $signals): array
    {
        $activities = [];
        foreach (self::FLAGS as $id => $names) {
            foreach ($names as $name) {
                if ($signals->strictFlag($name)) {
                    $activities[$id] = true;
                    break;
                }
            }
        }
        $event = $signals->eventKey();
        if ($event !== null) {
            $activities[ActivityDetectionPolicy::EVENT_MAP[$event]] = true;
        }
        // A zero fuel measurement is still a measurement, unlike an inactive flag.
        if ($signals->fuelMeasured()) {
            $activities[ActivityDetectionPolicy::FUEL_REPORT] = true;
        }
        return $activities;
    }
}
