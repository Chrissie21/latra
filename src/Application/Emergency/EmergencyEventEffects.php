<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/**
 * Side effects of an emergency lifecycle transition: operational logging and
 * outbound webhook events.
 */
interface EmergencyEventEffects
{
    public function started(array $event): void;

    public function ended(array $event): void;
}
