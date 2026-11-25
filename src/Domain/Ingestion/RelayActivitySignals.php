<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/** Independent, explicitly named signals. Device IO interpretation remains profile-specific. */
final class RelayActivitySignals
{
    public const FLAGS = [
        5 => ['harsh_braking', 'hard_braking'],
        6 => ['harsh_turning', 'hard_turn'],
        7 => ['harsh_acceleration', 'hard_acceleration'],
        8 => ['panic', 'sos', 'panic_button'],
        9 => ['battery_low', 'low_battery'],
        10 => ['power_disconnected', 'ext_power_off'],
        11 => ['excessive_idle', 'long_idle'],
        12 => ['accident', 'crash'],
        13 => ['passenger_panic', 'passenger_panic_button'],
        14 => ['tamper', 'tampering', 'antenna_cut'],
        15 => ['black_box', 'black_box_data'],
        16 => ['fuel_report'],
        17 => ['invalid_scan', 'invalid_ibutton'],
        18 => ['engine_start'],
        19 => ['engine_stop'],
        20 => ['boundary_enter', 'geofence_in', 'enter_zone'],
        21 => ['boundary_exit', 'geofence_out', 'leave_zone'],
        22 => ['checkpoint_enter', 'enter_checkpoint'],
        23 => ['checkpoint_exit', 'leave_checkpoint'],
        24 => ['ibutton', 'driver_scan', 'ibutton_scan'],
    ];

    /** @return array<int,true> */
    public static function detect(array $params): array
    {
        $activities = [];
        foreach (self::FLAGS as $id => $keys) {
            foreach ($keys as $key) {
                if (in_array($params[$key] ?? null, [1, '1', true], true)) {
                    $activities[$id] = true;
                    break;
                }
            }
        }
        $event = strtolower(trim((string) ($params['event'] ?? '')));
        if (isset(ActivityDetectionPolicy::EVENT_MAP[$event])) {
            $activities[ActivityDetectionPolicy::EVENT_MAP[$event]] = true;
        }
        // A zero fuel measurement is still a measurement, unlike an inactive flag.
        foreach (['fuel', 'fuel_level'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) $activities[16] = true;
        }
        return $activities;
    }
}
