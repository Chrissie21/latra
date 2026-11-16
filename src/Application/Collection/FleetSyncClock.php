<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface FleetSyncClock
{
    public function now(): string;
}
