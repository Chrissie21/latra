<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Latra\Application\Ingestion\TelemetryMessageReader;
use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\FuelEvidence;
use Latra\Domain\Ingestion\ParameterResolutionPolicy;
use Latra\Domain\Ingestion\SatelliteModePolicy;
use Latra\Domain\Ingestion\SensorReading;
use Latra\Domain\Ingestion\TelemetryMessage;
use RuntimeException;

/**
 * The single Wialon envelope decoder.
 *
 * All source keys, alias fallbacks and unit conversions live here. Domain policies receive only
 * canonical telemetry; the raw message and resolved parameters remain attached as evidence.
 */
final class WialonSourceReader implements TelemetryMessageReader
{
    /** Keys a device may use to report the fix mode; the meaning stays in SatelliteModePolicy. */
    private const FIX_MODE_KEYS = ['d2d3', 'satellite_mode', 'fix_type', 'gps_mode'];

    public function decodeMessage(Vehicle $vehicle, array $raw, array $aliases = []): TelemetryMessage
    {
        if (!is_int($raw['t'] ?? null) || !is_array($raw['p'] ?? []) || !is_array($raw['pos'] ?? [])) {
            throw new RuntimeException('Invalid source structure');
        }
        foreach (($raw['pos'] ?? []) as $key => $value) {
            if (in_array($key, ['x', 'y', 'z', 's', 'c', 'sc'], true) && $value !== null && !is_numeric($value)) {
                throw new RuntimeException('Invalid position field');
            }
        }
        $params = ParameterResolutionPolicy::resolve(
            $raw['p'] ?? [],
            (string) ($vehicle->hw_type ?? ''),
            $aliases,
        );
        return $this->build($raw, $raw, $params, true);
    }

    public function decodeUnitData(?array $data): ?TelemetryMessage
    {
        if ($data === null) {
            return null;
        }
        $position = is_array($data['pos'] ?? null) ? $data['pos'] : [];
        $envelope = $data;
        if (!isset($envelope['t']) && isset($position['t']) && is_numeric($position['t']) && (int) $position['t'] > 0) {
            $envelope['t'] = (int) $position['t'];
        }
        return $this->build(
            $envelope,
            $data,
            is_array($data['p'] ?? null) ? $data['p'] : [],
            false,
        );
    }

    /** Extract the canonical parameter map from a stored source row. */
    public function decodeStoredParameters(array $stored): array
    {
        $raw = json_decode((string) ($stored['raw_data'] ?? '{}'), true);
        return is_array($raw) && is_array($raw['p'] ?? null) ? $raw['p'] : [];
    }

    /** Decode already-resolved parameters for compatibility callers that hold only a parameter map. */
    public function decodeParameters(array $params): TelemetryMessage
    {
        return $this->build(['p' => $params], ['p' => $params], $params, false);
    }

    public function decodeFuelEvidence(array $params): FuelEvidence
    {
        return $this->decodeParameters($params)->fuel;
    }

    /** Map one Wialon unit to a validated local vehicle without inventing identity. */
    public function decodeUnit(array $unit): Vehicle
    {
        $row = $this->unitFields($unit);
        return new Vehicle(
            wialon_unit_id: self::intOrNull($row['wialon_unit_id']),
            vehicle_reg_no: self::stringOrNull($row['vehicle_reg_no']),
            imei: self::stringOrNull($row['imei']),
            name: self::stringOrNull($row['name']),
            hw_type: self::stringOrNull($row['hw_type']),
            phone: self::stringOrNull($row['phone']),
            lat: self::floatOrNull($row['lat']),
            lng: self::floatOrNull($row['lng']),
            altitude: self::intOrNull($row['altitude']),
            speed: self::floatOrNull($row['speed']),
            bearing: self::floatOrNull($row['bearing']),
            satellite_count: self::intOrNull($row['satellite_count']),
            hdop: self::floatOrNull($row['hdop']),
            d2d3: self::intOrNull($row['d2d3']),
            rssi: self::intOrNull($row['rssi']),
            lac: self::intOrNull($row['lac']),
            cell_id: self::intOrNull($row['cell_id']),
            mcc: self::intOrNull($row['mcc']),
            mgs_id: self::intOrNull($row['mgs_id']),
            last_message_time: self::intOrNull($row['last_message_time']),
        );
    }

    /** Legacy array shape retained only for the compatibility adapter. */
    public function unitFields(array $unit): array
    {
        $position = is_array($unit['pos'] ?? null) ? $unit['pos'] : [];
        $lastMessage = is_array($unit['lmsg'] ?? null) ? $unit['lmsg'] : [];
        $params = is_array($lastMessage['p'] ?? null) ? $lastMessage['p'] : [];
        $source = !empty($lastMessage['pos']) && is_array($lastMessage['pos'])
            ? $lastMessage['pos']
            : $position;
        $time = self::intOrNull($lastMessage['t'] ?? $position['t'] ?? null);
        $fixMode = null;
        foreach (self::FIX_MODE_KEYS as $key) {
            $fixMode = SatelliteModePolicy::interpret($source[$key] ?? $params[$key] ?? null);
            if ($fixMode !== null) {
                break;
            }
        }

        return [
            'wialon_unit_id' => self::intOrNull($unit['id'] ?? null),
            'vehicle_reg_no' => $this->registration($unit),
            'name' => self::stringOrNull($unit['nm'] ?? null),
            'imei' => self::stringOrNull($unit['uid'] ?? $unit['uid2'] ?? null),
            'hw_type' => self::stringOrNull($unit['hw'] ?? null),
            'phone' => self::stringOrNull($unit['ph'] ?? null),
            'lat' => self::floatOrNull($source['y'] ?? null, 8),
            'lng' => self::floatOrNull($source['x'] ?? null, 8),
            'altitude' => self::intOrNull($source['z'] ?? null),
            'speed' => self::floatOrNull($source['s'] ?? null, 2),
            'bearing' => self::floatOrNull($source['c'] ?? null, 2),
            'satellite_count' => self::intOrNull($source['sc'] ?? null),
            'hdop' => self::floatOrNull($params['hdop'] ?? null, 2),
            'd2d3' => $fixMode,
            'rssi' => self::intOrNull($params['rssi'] ?? $params['gsm'] ?? null),
            'lac' => self::intOrNull($params['lac'] ?? $params['gsm_lac'] ?? $params['lbs_lac'] ?? null),
            'cell_id' => self::intOrNull($params['cell_id'] ?? $params['cellid'] ?? $params['cid']
                ?? $params['gsm_cellid'] ?? $params['gsm_cell_id'] ?? null),
            'mcc' => self::intOrNull($params['mcc'] ?? null),
            'mgs_id' => $time,
            'last_message_time' => $time,
        ];
    }

    /**
     * @param array<string,mixed> $envelope decoded view, including any elevated position time
     * @param array<string,mixed> $rawSource original vendor evidence, retained verbatim
     * @param array<string,mixed> $params resolved parameters
     */
    private function build(array $envelope, array $rawSource, array $params, bool $deriveMessageId): TelemetryMessage
    {
        $messageTime = self::intOrNull($envelope['t'] ?? null);
        $position = is_array($envelope['pos'] ?? null) ? $envelope['pos'] : [];
        $fixMode = null;
        foreach (self::FIX_MODE_KEYS as $key) {
            $fixMode = SatelliteModePolicy::interpret($position[$key] ?? $params[$key] ?? null);
            if ($fixMode !== null) {
                break;
            }
        }
        $batteryMv = self::toMv(ParameterResolutionPolicy::pick($params, 'battery_mv', ['pwr_int', 'io_67']));
        $batteryPct = self::toPct(ParameterResolutionPolicy::pick($params, 'battery_pct', ['battery']));
        $received = isset($envelope['rt']) && is_numeric($envelope['rt']) ? (int) $envelope['rt'] : time();

        $sensors = [];
        foreach (is_array($envelope['sens'] ?? null) ? $envelope['sens'] : [] as $sensor) {
            if (!is_array($sensor)) {
                continue;
            }
            $sensors[] = new SensorReading(
                type: isset($sensor['t']) ? (string) $sensor['t'] : null,
                value: $sensor['v'] ?? null,
                unit: isset($sensor['u']) ? (string) $sensor['u'] : null,
            );
        }

        return new TelemetryMessage(
            messageTime: $messageTime,
            messageId: $deriveMessageId ? $messageTime : null,
            receivedTime: $received,
            latitude: self::floatOrNull($position['y'] ?? null, 8),
            longitude: self::floatOrNull($position['x'] ?? null, 8),
            altitudeM: self::intOrNull($position['z'] ?? null),
            speedKmh: self::floatOrNull($position['s'] ?? null, 2),
            bearingDeg: self::floatOrNull($position['c'] ?? null, 2),
            satelliteCount: self::intOrNull($position['sc'] ?? null),
            fixMode: $fixMode,
            hdop: self::floatOrNull($params['hdop'] ?? null, 2),
            rssi: self::intOrNull(ParameterResolutionPolicy::pick($params, 'rssi', ['gsm'])),
            lac: self::intOrNull(ParameterResolutionPolicy::pick($params, 'lac')),
            cellId: self::intOrNull(ParameterResolutionPolicy::pick($params, 'cell_id')),
            mcc: self::intOrNull($params['mcc'] ?? null),
            odometerKm: self::floatOrNull(
                ParameterResolutionPolicy::pick($params, 'odometer', ['mileage', 'mil']),
                2,
            ),
            verticalSpeedMps: self::floatOrNull(
                ParameterResolutionPolicy::pick($params, 'vertical_speed', ['vspeed']),
            ),
            driverId: self::stringOrNull(
                ParameterResolutionPolicy::pick($params, 'driver_id', ['tco_driver1_id', 'pass']),
            ),
            idleTimeSeconds: self::floatOrNull(ParameterResolutionPolicy::pick($params, 'idle_time')),
            externalPowerVolts: self::floatOrNull($params['pwr_ext'] ?? null),
            internalBatteryVolts: self::floatOrNull($params['pwr_int'] ?? null),
            batteryPct: $batteryPct,
            batteryMv: $batteryMv,
            fuel: new FuelEvidence(
                unit: self::stringOrNull(ParameterResolutionPolicy::pick($params, 'fuel_unit', ['unit'])),
                level: self::floatOrNull(ParameterResolutionPolicy::pick($params, 'fuel', ['fuel_level'])),
                rtLevel: self::floatOrNull($params['rt_fuel'] ?? null),
                validFlag: self::fuelScalar($params['fuel_valid_flag'] ?? null),
                signalLevel: self::fuelScalar($params['fuel_signal'] ?? null),
                softStatus: self::fuelScalar($params['fuel_soft_status'] ?? null),
                hardFault: self::fuelScalar($params['fuel_hard_fault'] ?? null),
                tankTempC: self::floatOrNull($params['tank_temp'] ?? $params['fuel_temp'] ?? null),
                channel: self::fuelScalar($params['fuel_channel'] ?? null),
            ),
            sensors: $sensors,
            rawSource: $rawSource,
            signalParameters: $params,
        );
    }

    private function registration(array $unit): string
    {
        foreach (is_array($unit['flds'] ?? null) ? $unit['flds'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = strtolower((string) ($field['n'] ?? ''));
            if (in_array($name, ['reg_no', 'registration', 'plate', 'plate_no', 'vehicle_reg'], true)) {
                return strtoupper(trim((string) ($field['v'] ?? '')));
            }
        }
        $name = strtoupper(trim((string) ($unit['nm'] ?? '')));
        return preg_match('/^[A-Z0-9\-]{3,20}$/', $name) ? $name : '';
    }

    private static function toMv(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $volts = (float) $value;
        return $volts < 100 ? round($volts * 1000, 2) : round($volts, 2);
    }

    private static function toPct(mixed $value): ?float
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

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    /** Preserve reported status precision and malformed text for Domain validation. */
    private static function fuelScalar(mixed $value): int|float|string|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private static function floatOrNull(mixed $value, ?int $digits = null): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return $digits === null ? (float) $value : round((float) $value, $digits);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
