<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

/**
 * Applies sync ownership and policy to an already-decoded vehicle: the sync timestamp, active flag,
 * engine sensor parameter and source facts. Source key selection belongs to Infrastructure.
 */
final class FleetSyncPolicy
{
    public static function vehicle(Vehicle $decoded, string $syncedAt, ?string $engineParam): Vehicle
    {
        return new Vehicle(
            wialon_unit_id: $decoded->wialon_unit_id,
            vehicle_reg_no: $decoded->vehicle_reg_no,
            imei: $decoded->imei,
            name: $decoded->name,
            hw_type: $decoded->hw_type,
            phone: $decoded->phone,
            lat: $decoded->lat,
            lng: $decoded->lng,
            altitude: $decoded->altitude,
            speed: $decoded->speed,
            bearing: $decoded->bearing,
            satellite_count: $decoded->satellite_count,
            hdop: $decoded->hdop,
            d2d3: $decoded->d2d3,
            rssi: $decoded->rssi,
            lac: $decoded->lac,
            cell_id: $decoded->cell_id,
            mcc: $decoded->mcc,
            mgs_id: $decoded->mgs_id,
            last_message_time: $decoded->last_message_time,
            last_sync_at: $syncedAt,
            is_active: true,
            engine_param: $engineParam,
        );
    }
}
