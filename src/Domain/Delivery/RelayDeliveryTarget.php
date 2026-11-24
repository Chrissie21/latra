<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

/** Which destination a relay flush may use. */
enum RelayDeliveryTarget
{
    case Disabled;
    case Mock;
    case Latra;
}
