<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

/**
 * Source units for fleet sync. `open`/`close` bound one authenticated session,
 * matching the legacy service which logs in once per sync and out once.
 */
interface FleetUnitSource
{
    /** @return bool false when the session could not be established */
    public function open(): bool;

    /** @return array<int,array> every account unit */
    public function units(): array;

    /** Extended unit data (sensors and position) or null when absent. */
    public function unit(int $unitId): ?array;

    /** Parameter name of the unit's engine sensor, or null when not configured. */
    public function engineSensorParam(int $unitId): ?string;

    public function close(): void;
}
