<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\Emergency\EmergencyEventClock;

final class SystemEmergencyEventClock implements EmergencyEventClock
{
    public function now(): string
    {
        return date(\defined('DATE_FORMAT') ? \DATE_FORMAT : 'Y-m-d H:i:s');
    }
}
