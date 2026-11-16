<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

interface EmergencyEventClock
{
    public function now(): string;
}
