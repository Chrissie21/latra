<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Collection;

use Latra\Application\Collection\FleetSyncClock;

final class SystemFleetSyncClock implements FleetSyncClock
{
    public function now(): string
    {
        return date(\defined('DATE_FORMAT') ? \DATE_FORMAT : 'Y-m-d H:i:s');
    }
}
