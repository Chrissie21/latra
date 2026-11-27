<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Latra\Application\Ingestion\TelemetrySignalReader;
use Latra\Domain\Ingestion\ActivityDetectionPolicy;
use Latra\Domain\Ingestion\TelemetrySignals;
use RuntimeException;

/**
 * Decodes Wialon parameter evidence into canonical telemetry signals.
 *
 * Alias precedence, bit syntax, profile-specific IO and voltage/unit conversion stay here.
 * The relay view reproduces the historical filtered detector input; readSource() keeps the
 * unfiltered view used by the inactive emergency re-detection adapter.
 */
final class WialonSignalReader implements TelemetrySignalReader
{
    /** Named activity flags: strict on-values only, one canonical name per activity. */
    private const STRICT_SIGNALS = [
        'harsh_braking' => ['harsh_braking', 'hard_braking'],
        'harsh_turning' => ['harsh_turning', 'hard_turn'],
        'harsh_acceleration' => ['harsh_acceleration', 'hard_acceleration'],
        'panic' => ['panic', 'sos', 'panic_button'],
        'battery_low' => ['battery_low', 'low_battery'],
        'power_disconnected' => ['power_disconnected', 'ext_power_off'],
        'excessive_idle' => ['excessive_idle', 'long_idle'],
        'accident' => ['accident', 'crash'],
        'passenger_panic' => ['passenger_panic', 'passenger_panic_button'],
        'tamper' => ['tamper', 'tampering', 'antenna_cut'],
        'black_box' => ['black_box', 'black_box_data'],
        'fuel_report' => ['fuel_report'],
        'invalid_scan' => ['invalid_scan', 'invalid_ibutton'],
        'engine_start' => ['engine_start'],
        'engine_stop' => ['engine_stop'],
        'boundary_enter' => ['boundary_enter', 'geofence_in', 'enter_zone'],
        'boundary_exit' => ['boundary_exit', 'geofence_out', 'leave_zone'],
        'checkpoint_enter' => ['checkpoint_enter', 'enter_checkpoint'],
        'checkpoint_exit' => ['checkpoint_exit', 'leave_checkpoint'],
        'ibutton' => ['ibutton', 'driver_scan', 'ibutton_scan'],
    ];

    private const DRIVING_MANEUVERS = [1 => 'acceleration', 2 => 'braking', 3 => 'turning'];

    public function read(array $parameters, ?string $profile = null, ?string $engineParam = null,
        ?float $speedKmh = null): TelemetrySignals
    {
        return $this->build($parameters, $profile, $engineParam, $speedKmh, true, true);
    }

    public function readDeviceStatus(array $parameters): TelemetrySignals
    {
        return $this->build($parameters, null, null, null, true, false);
    }

    /** Unfiltered source view retained for the deferred emergency re-detection path. */
    public function readSource(array $parameters, ?string $engineParam = null,
        ?float $speedKmh = null): TelemetrySignals
    {
        return $this->build($parameters, null, $engineParam, $speedKmh, false, false);
    }

    private function build(array $parameters, ?string $profile, ?string $engineParam,
        ?float $speedKmh, bool $relayView, bool $decodeVoltage): TelemetrySignals
    {
        $fmb = $profile === 'teltonika-fmb';
        $rawEvent = strtolower((string) ($parameters['event'] ?? ''));
        $trimmedEvent = strtolower(trim((string) ($parameters['event'] ?? '')));
        $exactEvent = isset(ActivityDetectionPolicy::EVENT_MAP[$rawEvent]) ? $rawEvent : null;
        $eventKey = isset(ActivityDetectionPolicy::EVENT_MAP[$trimmedEvent]) ? $trimmedEvent : null;
        $relayEvent = $eventKey !== null ? ($rawEvent !== '' ? $rawEvent : null) : null;

        if ($relayView) {
            $detectorParameters = $parameters;
            foreach (['io_66', 'pwr_ext', 'ext_voltage', 'io_66_power',
                'io_104', 'io_105', 'io_233', 'io_8', 'io_335', 'io_123',
                'io_79', 'io_80', 'ho', 'pass', 'tco_driver1_id'] as $key) {
                unset($detectorParameters[$key]);
            }
            foreach (['invalid_scan', 'panic', 'sos', 'alarm', 'passenger_panic'] as $key) {
                if (!in_array($detectorParameters[$key] ?? null, [1, '1', true], true)) {
                    unset($detectorParameters[$key]);
                }
            }
            if ($eventKey === null) unset($detectorParameters['event']);
            // The relay mapper removes power rails, hardware-only channels and non-exact events
            // before legacy detection, and keeps named flags only when strictly on.
            $loose = [
                'panic_driver' => self::anyStrict($parameters, ['panic', 'sos', 'alarm']) ? true : null,
                'panic_passenger' => self::anyStrict($parameters, ['passenger_panic']) ? true : null,
                'battery_low' => self::firstPresentLoose($parameters, ['battery_low', 'low_battery']),
                'power_disconnected' => self::firstPresentLoose(
                    $parameters, ['power_disconnected', 'ext_power_off', 'io_66_power_loss']
                ),
                'tamper' => self::firstPresentLoose($parameters, ['tamper', 'tampering', 'antenna_cut']),
                'ibutton' => self::firstPresentLoose($parameters, ['ibutton', 'driver_scan']),
                'invalid_scan' => self::firstPresentLoose($parameters, ['invalid_ibutton']),
                'fuel_report' => self::firstPresentLoose($parameters, ['fuel_report']),
                'black_box' => self::firstPresentLoose($parameters, ['black_box']),
                'accident' => self::firstPresentLoose($parameters, ['accident', 'crash']),
            ];
            $driverPresent = false;
            $invalidScanPresent = self::anyStrict($parameters, ['invalid_scan']);
            $gForce = null;
            $externalPowerPresent = null;
            $eventName = $relayEvent;
        } else {
            $detectorParameters = $parameters;
            $loose = [
                'panic_driver' => self::flagOn($parameters, ['panic', 'sos', 'alarm', 'io_104']),
                'panic_passenger' => self::flagOn($parameters, ['passenger_panic', 'io_105']),
                'battery_low' => self::flagOn($parameters, ['battery_low', 'low_battery']),
                'power_disconnected' => self::flagOn(
                    $parameters, ['power_disconnected', 'ext_power_off', 'io_66_power_loss']
                ),
                'tamper' => self::flagOn($parameters, ['tamper', 'tampering', 'antenna_cut', 'io_8']),
                'ibutton' => self::flagOn($parameters, ['ibutton', 'driver_scan']),
                'invalid_scan' => self::flagOn($parameters, ['invalid_ibutton', 'io_123']),
                'fuel_report' => self::flagOn($parameters, ['fuel_report']),
                'black_box' => self::flagOn($parameters, ['black_box', 'io_335']),
                'accident' => self::flagOn($parameters, ['accident', 'crash', 'io_233']),
            ];
            $driverPresent = isset($parameters['pass']) || isset($parameters['tco_driver1_id']);
            $invalidScanPresent = isset($parameters['invalid_scan']);
            $gForce = self::gForce($parameters);
            $externalPowerPresent = self::externalPowerState($parameters);
            $eventName = $rawEvent !== '' ? $rawEvent : null;
        }

        $drivingCode = $parameters['io_253'] ?? null;
        $maneuver = $fmb && (is_int($drivingCode) || is_string($drivingCode))
            && isset(self::DRIVING_MANEUVERS[$drivingCode])
            ? self::DRIVING_MANEUVERS[$drivingCode] : null;
        // Preserve the detector's historical fallback to a parameter-bag speed when no
        // explicit position speed was supplied by the caller.
        $resolvedSpeed = $speedKmh;
        if ($resolvedSpeed === null && isset($parameters['speed']) && is_numeric($parameters['speed'])) {
            $resolvedSpeed = (float) $parameters['speed'];
        }

        return new TelemetrySignals(
            eventName: $eventName,
            eventKey: $eventKey,
            rawEventName: $exactEvent,
            strictFlags: self::strictFlags($parameters),
            looseFlags: $loose,
            driverPresent: $driverPresent,
            invalidScanPresent: $invalidScanPresent,
            fuelPresent: isset($parameters['fuel']) || isset($parameters['fuel_level']),
            fuelMeasured: self::numericPresent($parameters, ['fuel', 'fuel_level']),
            gForce: $gForce,
            engineOn: self::engineState($parameters, $engineParam),
            detectorEngineOn: self::engineState($detectorParameters, $engineParam),
            speedKmh: $resolvedSpeed,
            externalPowerPresent: $externalPowerPresent,
            externalPowerVolts: $decodeVoltage
                ? self::voltage($parameters, 'io_66', 'pwr_ext', $fmb) : null,
            internalBatteryVolts: $decodeVoltage
                ? self::voltage($parameters, 'io_67', 'pwr_int', $fmb) : null,
            externalPowerMv: self::toMillivolts(
                $parameters['pwr_ext'] ?? $parameters['ext_power'] ?? $parameters['io_66'] ?? null
            ),
            internalBatteryMv: self::toMillivolts(
                $parameters['pwr_int'] ?? $parameters['battery_charge'] ?? $parameters['io_67'] ?? null
            ),
            batteryPct: self::toPercentage($parameters['battery'] ?? $parameters['battery_pct'] ?? null),
            deviceTampered: self::firstPresentLoose(
                $parameters, ['tamper', 'tampering', 'antenna_cut', 'io_8']
            ) === true,
            fmbManeuver: $maneuver,
            fmbPowerCut: $fmb && in_array($parameters['io_252'] ?? null, [1, '1'], true),
            fmbExcessiveIdle: $fmb && in_array($parameters['io_251'] ?? null, [1, '1'], true),
        );
    }

    /** @return array<string,bool> */
    private static function strictFlags(array $parameters): array
    {
        $flags = [];
        foreach (self::STRICT_SIGNALS as $signal => $keys) {
            $flags[$signal] = self::anyStrict($parameters, $keys);
        }
        return $flags;
    }

    private static function anyStrict(array $parameters, array $keys): bool
    {
        foreach ($keys as $key) {
            if (in_array($parameters[$key] ?? null, [1, '1', true], true)) {
                return true;
            }
        }
        return false;
    }

    private static function firstPresentLoose(array $parameters, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $parameters)) {
                $value = $parameters[$key];
                return $value !== 0 && $value !== '0' && $value !== false;
            }
        }
        return null;
    }

    private static function flagOn(array $parameters, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (strpos((string) $key, ':') !== false) {
                [$parameter, $bit] = explode(':', (string) $key, 2);
                if (array_key_exists($parameter, $parameters) && is_numeric($bit)) {
                    $value = (int) $parameters[$parameter];
                    return ((int) floor($value / (1 << (int) $bit))) % 2 === 1;
                }
                continue;
            }
            if (array_key_exists($key, $parameters)) {
                $value = $parameters[$key];
                return $value !== 0 && $value !== '0' && $value !== false;
            }
        }
        return null;
    }

    private static function numericPresent(array $parameters, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($parameters[$key]) && is_numeric($parameters[$key])) {
                return true;
            }
        }
        return false;
    }

    private static function engineState(array $parameters, ?string $engineParam): ?bool
    {
        if (is_string($engineParam) && trim($engineParam) !== '') {
            $engineParam = trim($engineParam);
            if (preg_match('/^[^:]+:\d+$/', $engineParam)) {
                return self::flagOn($parameters, [$engineParam]);
            }
            if (array_key_exists($engineParam, $parameters)) {
                $value = $parameters[$engineParam];
                return $value !== 0 && $value !== '0' && $value !== false;
            }
        }
        return self::flagOn(
            $parameters, ['ignition', 'io_5', 'io_239', 'ignit', 'engine_on', 'io_69']
        );
    }

    private static function externalPowerState(array $parameters): ?bool
    {
        foreach (['io_66', 'pwr_ext', 'ext_voltage', 'io_66_power'] as $key) {
            if (isset($parameters[$key]) && is_numeric($parameters[$key])) {
                return (float) $parameters[$key] > 0;
            }
        }
        return null;
    }

    private static function gForce(array $parameters): ?float
    {
        if (isset($parameters['ho']) && is_numeric($parameters['ho'])) {
            return (float) $parameters['ho'];
        }
        foreach (['io_79', 'io_80'] as $key) {
            if (isset($parameters[$key]) && is_numeric($parameters[$key])) {
                $raw = (int) $parameters[$key];
                if ($raw > 0x7FFFFFFF) {
                    $raw -= 0x100000000;
                }
                return $raw / 1000.0;
            }
        }
        return null;
    }

    private static function voltage(array $parameters, string $io, string $alias, bool $fmb): ?float
    {
        // IO numbers are profile-specific; named Wialon voltage parameters are expressed in volts.
        $key = $fmb && array_key_exists($io, $parameters) ? $io : $alias;
        if (!array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return null;
        }
        if (!is_numeric($parameters[$key]) || (float) $parameters[$key] < 0) {
            throw new RuntimeException('Invalid voltage');
        }
        return (float) $parameters[$key] / ($key === $io ? 1000 : 1);
    }

    private static function toMillivolts(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $millivolts = (float) $value;
        if ($millivolts < 100) {
            return round($millivolts * 1000, 2);
        }
        return round($millivolts, 2);
    }

    private static function toPercentage(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $percentage = (float) $value;
        if ($percentage > 0 && $percentage <= 1) {
            $percentage *= 100;
        }
        return round(max(0, min(100, $percentage)), 2);
    }
}
