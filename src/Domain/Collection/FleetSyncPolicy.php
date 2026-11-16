<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use Latra\Domain\Ingestion\LatraPayloadPolicy;

/**
 * Maps one Wialon unit into the persisted vehicle row used by fleet sync:
 * the LATRA vehicle mapping plus the sync timestamp, active flag and the unit's
 * configured engine sensor parameter. A blank imei is stored as null.
 */
final class FleetSyncPolicy
{
    public static function vehicleRow(array $wialonUnit, string $syncedAt, ?string $engineParam): array
    {
        $mapped = LatraPayloadPolicy::mapVehicle($wialonUnit);
        if (empty($mapped['imei'])) {
            $mapped['imei'] = null;
        }

        return array_merge($mapped, [
            'last_sync_at' => $syncedAt,
            'is_active' => 1,
            'engine_param' => $engineParam,
        ]);
    }
}
