<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

use InvalidArgumentException;

/**
 * Selected destination for one relay flush.
 *
 * An enabled target always carries an endpoint, so a consumer never has to
 * check "enabled but no URL".
 */
final readonly class RelayDeliverySelection
{
    private function __construct(
        public RelayDeliveryTarget $target,
        public string $endpoint,
    ) {
    }

    public static function disabled(): self
    {
        return new self(RelayDeliveryTarget::Disabled, '');
    }

    public static function mock(string $endpoint): self
    {
        if ($endpoint === '') {
            throw new InvalidArgumentException('Mock delivery requires an endpoint');
        }
        return new self(RelayDeliveryTarget::Mock, $endpoint);
    }

    public static function latra(string $endpoint): self
    {
        if ($endpoint === '') {
            throw new InvalidArgumentException('LATRA delivery requires an endpoint');
        }
        return new self(RelayDeliveryTarget::Latra, $endpoint);
    }
}
