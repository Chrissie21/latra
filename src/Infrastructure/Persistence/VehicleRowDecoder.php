<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Domain\Collection\Vehicle;

/**
 * The only place that translates vehicle database rows to and from the
 * validated domain representation. Raw PDO values never cross into Application.
 */
final class VehicleRowDecoder
{
    /** @param array<string,mixed> $row */
    public static function decode(array $row): Vehicle
    {
        return new Vehicle(
            id: self::intOrNull($row['id'] ?? null),
            wialon_unit_id: self::intOrNull($row['wialon_unit_id'] ?? null),
            vehicle_reg_no: self::stringOrNull($row['vehicle_reg_no'] ?? null),
            imei: self::stringOrNull($row['imei'] ?? null),
            name: self::stringOrNull($row['name'] ?? null),
            hw_type: self::stringOrNull($row['hw_type'] ?? null),
            phone: self::stringOrNull($row['phone'] ?? null),
            lat: self::floatOrNull($row['lat'] ?? null),
            lng: self::floatOrNull($row['lng'] ?? null),
            altitude: self::intOrNull($row['altitude'] ?? null),
            speed: self::floatOrNull($row['speed'] ?? null),
            bearing: self::floatOrNull($row['bearing'] ?? null),
            satellite_count: self::intOrNull($row['satellite_count'] ?? null),
            hdop: self::floatOrNull($row['hdop'] ?? null),
            d2d3: self::intOrNull($row['d2d3'] ?? null),
            rssi: self::intOrNull($row['rssi'] ?? null),
            lac: self::intOrNull($row['lac'] ?? null),
            cell_id: self::intOrNull($row['cell_id'] ?? null),
            mcc: self::intOrNull($row['mcc'] ?? null),
            mgs_id: self::intOrNull($row['mgs_id'] ?? null),
            last_message_time: self::intOrNull($row['last_message_time'] ?? null),
            last_sync_at: self::stringOrNull($row['last_sync_at'] ?? null),
            is_active: self::boolOrNull($row['is_active'] ?? null),
            speed_limit_kmh: self::floatOrNull($row['speed_limit_kmh'] ?? null),
            driver_id: self::stringOrNull($row['driver_id'] ?? null),
            odometer: self::floatOrNull($row['odometer'] ?? null),
            engine_param: self::stringOrNull($row['engine_param'] ?? null),
            fuel_level_sensor: self::stringOrNull($row['fuel_level_sensor'] ?? null),
            fuel_cadence_min: self::intOrNull($row['fuel_cadence_min'] ?? null),
        );
    }

    /** @return Vehicle[] */
    public static function rows(array $rows): array
    {
        return array_map(static fn(array $row): Vehicle => self::decode($row), $rows);
    }

    /** @return array<string,int|float|string|bool|null> */
    public static function encode(Vehicle $vehicle): array
    {
        return [
            'wialon_unit_id' => $vehicle->wialon_unit_id,
            // The legacy NOT NULL column represents unavailable registration as an empty string.
            // decode() restores absence; no fabricated identity crosses the Domain boundary.
            'vehicle_reg_no' => $vehicle->vehicle_reg_no ?? '',
            'imei' => $vehicle->imei,
            'name' => $vehicle->name,
            'hw_type' => $vehicle->hw_type,
            'phone' => $vehicle->phone,
            'lat' => $vehicle->lat,
            'lng' => $vehicle->lng,
            'altitude' => $vehicle->altitude,
            'speed' => $vehicle->speed,
            'bearing' => $vehicle->bearing,
            'satellite_count' => $vehicle->satellite_count,
            'hdop' => $vehicle->hdop,
            'd2d3' => $vehicle->d2d3,
            'rssi' => $vehicle->rssi,
            'lac' => $vehicle->lac,
            'cell_id' => $vehicle->cell_id,
            'mcc' => $vehicle->mcc,
            'mgs_id' => $vehicle->mgs_id,
            'last_message_time' => $vehicle->last_message_time,
            'last_sync_at' => $vehicle->last_sync_at,
            'is_active' => $vehicle->is_active,
            'speed_limit_kmh' => $vehicle->speed_limit_kmh,
            'driver_id' => $vehicle->driver_id,
            'odometer' => $vehicle->odometer,
            'engine_param' => $vehicle->engine_param,
            'fuel_level_sensor' => $vehicle->fuel_level_sensor,
            'fuel_cadence_min' => $vehicle->fuel_cadence_min,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return ($value === null || $value === '') ? null : (bool) $value;
    }
}
