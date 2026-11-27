<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class DeviceStatusPolicy
{
    public const OFFLINE_SECONDS = 900;

    public const BATTERY_LOW_MV = 3200;
    public const BATTERY_LOW_PCT = 20;
    public const EXT_POWER_LOW_MV = 1000;

    public static function evaluate(TelemetrySignals $signals, array $previous): array
    {
        $externalPowerMv = $signals->externalPowerMv();
        $internalBatteryMv = $signals->internalBatteryMv();
        $internalBatteryPct = $signals->batteryPct();
        $externalPowerDisconnected = $externalPowerMv !== null
            && $externalPowerMv < self::EXT_POWER_LOW_MV
            && $externalPowerMv > 0;
        $batteryLow = ($internalBatteryMv !== null
                && $internalBatteryMv < self::BATTERY_LOW_MV
                && $internalBatteryMv > 0)
            || ($internalBatteryPct !== null && $internalBatteryPct < self::BATTERY_LOW_PCT);
        $tampered = $signals->deviceTampered();

        $values = [
            'internal_battery_low' => $batteryLow ? 1 : 0,
            'internal_battery_mv' => $internalBatteryMv,
            'internal_battery_pct' => $internalBatteryPct,
            'external_power_disconnected' => $externalPowerDisconnected ? 1 : 0,
            'external_power_mv' => $externalPowerMv,
            'tampered' => $tampered ? 1 : 0,
        ];
        $events = [];

        if (isset($previous['internal_battery_low'])
            && (int) $previous['internal_battery_low'] !== $values['internal_battery_low']
            && $batteryLow) {
            $events[] = [
                'type' => 'BATTERY_LOW',
                'detail' => "Internal battery low ({$internalBatteryMv} mV)",
            ];
        }
        if (isset($previous['external_power_disconnected'])
            && (int) $previous['external_power_disconnected'] !== $values['external_power_disconnected']
            && $externalPowerDisconnected) {
            $events[] = [
                'type' => 'POWER_DISCONNECTED',
                'detail' => 'External power disconnected',
            ];
        }
        if (isset($previous['tampered'])
            && (int) $previous['tampered'] !== $values['tampered']
            && $tampered) {
            $events[] = [
                'type' => 'TAMPERING',
                'detail' => 'Device tampering detected',
            ];
        }

        $changed = $previous
            ? $previous['is_online'] != 1
                || $previous['internal_battery_low'] != $values['internal_battery_low']
                || $previous['external_power_disconnected'] != $values['external_power_disconnected']
                || $previous['tampered'] != $values['tampered']
            : false;

        return [
            ...$values,
            'events' => $events,
            'changed' => $changed,
        ];
    }
}
