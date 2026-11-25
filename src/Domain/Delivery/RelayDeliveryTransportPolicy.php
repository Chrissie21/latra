<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

use Latra\Domain\Exception\InvalidConfiguration;

/**
 * Chooses the relay delivery destination from deployment configuration.
 *
 * Mock stays the default and a missing mock URL means "no delivery". The real
 * destination requires the explicit `latra` mode and a configured endpoint, so a
 * deployment cannot reach LATRA by accident. Endpoints are resolved lazily, so
 * only the selected mode's source is read.
 */
final class RelayDeliveryTransportPolicy
{
    public const MODE_MOCK = 'mock';
    public const MODE_LATRA = 'latra';

    public static function select(string $configuredMode, callable $mockEndpoint, callable $latraEndpoint): RelayDeliverySelection
    {
        return match ($configuredMode) {
            self::MODE_MOCK => self::mock((string) $mockEndpoint()),
            self::MODE_LATRA => self::latra((string) $latraEndpoint()),
            default => throw new InvalidConfiguration(
                'Unsupported relay delivery transport: ' . $configuredMode,
            ),
        };
    }

    public static function requiresVtsBatching(string $mode, string $mockEndpoint): bool
    {
        return $mode === self::MODE_LATRA
            || str_contains($mockEndpoint, '/data-integration/integration/gps');
    }

    private static function mock(string $endpoint): RelayDeliverySelection
    {
        return $endpoint === '' ? RelayDeliverySelection::disabled() : RelayDeliverySelection::mock($endpoint);
    }

    private static function latra(string $endpoint): RelayDeliverySelection
    {
        if ($endpoint === '') {
            throw new InvalidConfiguration(
                'The LATRA transport requires LATRA_API_URL to be configured',
            );
        }
        return RelayDeliverySelection::latra($endpoint);
    }
}
