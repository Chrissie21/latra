<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use InvalidArgumentException;

/**
 * One local vehicle at persistence/application boundaries.
 *
 * Local id, Wialon unit id, IMEI and registration are separate facts: any of
 * them may be absent, and none may be substituted with another identity. A
 * newly created vehicle simply has no local id yet.
 */
final readonly class Vehicle
{
    public function __construct(
        public ?int $id = null,
        public ?int $wialon_unit_id = null,
        public ?string $vehicle_reg_no = null,
        public ?string $imei = null,
        public ?string $name = null,
        public ?string $hw_type = null,
        public ?string $phone = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?int $altitude = null,
        public ?float $speed = null,
        public ?float $bearing = null,
        public ?int $satellite_count = null,
        public ?float $hdop = null,
        public ?int $d2d3 = null,
        public ?int $rssi = null,
        public ?int $lac = null,
        public ?int $cell_id = null,
        public ?int $mcc = null,
        public ?int $mgs_id = null,
        public ?int $last_message_time = null,
        public ?string $last_sync_at = null,
        public ?bool $is_active = null,
        public ?float $speed_limit_kmh = null,
        public ?string $driver_id = null,
        public ?float $odometer = null,
        public ?string $engine_param = null,
        public ?string $fuel_level_sensor = null,
        public ?int $fuel_cadence_min = null,
    ) {
        foreach (['id' => $id, 'wialon_unit_id' => $wialon_unit_id,
            'fuel_cadence_min' => $fuel_cadence_min] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("Vehicle $field must not be negative");
            }
        }
        foreach (['lat' => $lat, 'lng' => $lng, 'speed' => $speed, 'bearing' => $bearing,
            'hdop' => $hdop, 'speed_limit_kmh' => $speed_limit_kmh, 'odometer' => $odometer] as $field => $value) {
            if ($value !== null && !is_finite($value)) {
                throw new InvalidArgumentException("Vehicle $field must be finite");
            }
        }
    }

    /** Latest stored vehicle facts shaped as a source message for payload fallback. */
    public function telemetrySource(): array
    {
        return [
            'wialon_msg_time' => $this->last_message_time,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'altitude' => $this->altitude,
            'speed' => $this->speed,
            'bearing' => $this->bearing,
            'satellite_count' => $this->satellite_count,
            'hdop' => $this->hdop,
            'd2d3' => $this->d2d3,
            'rssi' => $this->rssi,
            'lac' => $this->lac,
            'cell_id' => $this->cell_id,
            'mcc' => $this->mcc,
            'mgs_id' => $this->mgs_id,
        ];
    }

    /** Apply fleet-sync facts to a persisted vehicle without erasing operator settings. */
    public function withSynced(Vehicle $synced): self
    {
        return new self(
            id: $this->id,
            wialon_unit_id: $synced->wialon_unit_id,
            vehicle_reg_no: $synced->vehicle_reg_no,
            imei: $synced->imei,
            name: $synced->name,
            hw_type: $synced->hw_type,
            phone: $synced->phone,
            lat: $synced->lat,
            lng: $synced->lng,
            altitude: $synced->altitude,
            speed: $synced->speed,
            bearing: $synced->bearing,
            satellite_count: $synced->satellite_count,
            hdop: $synced->hdop,
            d2d3: $synced->d2d3,
            rssi: $synced->rssi,
            lac: $synced->lac,
            cell_id: $synced->cell_id,
            mcc: $synced->mcc,
            mgs_id: $synced->mgs_id,
            last_message_time: $synced->last_message_time,
            last_sync_at: $synced->last_sync_at,
            is_active: $synced->is_active,
            speed_limit_kmh: $this->speed_limit_kmh,
            driver_id: $this->driver_id,
            odometer: $this->odometer,
            engine_param: $synced->engine_param,
            fuel_level_sensor: $this->fuel_level_sensor,
            fuel_cadence_min: $this->fuel_cadence_min,
        );
    }
}
