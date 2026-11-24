<?php
/**
 * Delivery transport selection is a policy decision, not adapter code: mock is
 * the default, a missing mock URL disables delivery, and the real destination
 * needs an explicit mode plus an endpoint.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Delivery\RelayDeliverySelection;
use Latra\Domain\Delivery\RelayDeliveryTarget;
use Latra\Domain\Delivery\RelayDeliveryTransportPolicy;
use Latra\Domain\Exception\InvalidConfiguration;

function selectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejection(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidConfiguration $error) {
        selectionAssert($error->getMessage() === $message, 'Rejection message changed: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected InvalidConfiguration: ' . $message);
}

// A resolver that records use, so an unused destination is never read.
$calls = [];
$mock = static function (string $url) use (&$calls): callable {
    return static function () use ($url, &$calls): string {
        $calls[] = 'mock';
        return $url;
    };
};
$latra = static function (string $url) use (&$calls): callable {
    return static function () use ($url, &$calls): string {
        $calls[] = 'latra';
        return $url;
    };
};

// Mock is the default and only needs its own endpoint.
$disabled = RelayDeliveryTransportPolicy::select('mock', $mock(''), $latra('https://latra.example'));
selectionAssert($disabled->target === RelayDeliveryTarget::Disabled && $disabled->endpoint === '',
    'An unconfigured mock must disable delivery');
selectionAssert($calls === ['mock'], 'A disabled mock must not read the LATRA endpoint');

$calls = [];
$mockSelection = RelayDeliveryTransportPolicy::select('mock', $mock('http://127.0.0.1:8099/'), $latra('https://latra.example'));
selectionAssert($mockSelection->target === RelayDeliveryTarget::Mock
    && $mockSelection->endpoint === 'http://127.0.0.1:8099/', 'Mock endpoint not selected');
selectionAssert($calls === ['mock'], 'Mock selection must not read the LATRA endpoint');

$calls = [];
$latraSelection = RelayDeliveryTransportPolicy::select('latra', $mock('http://127.0.0.1:8099/'), $latra('https://latra.example/v1'));
selectionAssert($latraSelection->target === RelayDeliveryTarget::Latra
    && $latraSelection->endpoint === 'https://latra.example/v1', 'LATRA endpoint not selected');
selectionAssert($calls === ['latra'], 'LATRA selection must not read the mock endpoint');

// Fail closed, with the operator diagnostics the live run relied on.
rejection(
    fn() => RelayDeliveryTransportPolicy::select('latra', $mock('http://127.0.0.1:8099/'), $latra('')),
    'The LATRA transport requires LATRA_API_URL to be configured',
);
rejection(
    fn() => RelayDeliveryTransportPolicy::select('Latra', $mock('http://127.0.0.1:8099/'), $latra('https://latra.example')),
    'Unsupported relay delivery transport: Latra',
);

// A selection cannot represent a missing endpoint for an enabled target.
try {
    RelayDeliverySelection::mock('');
} catch (InvalidArgumentException $error) {
    selectionAssert($error->getMessage() === 'Mock delivery requires an endpoint', 'Selection guard message changed');
}
try {
    RelayDeliverySelection::latra('');
} catch (InvalidArgumentException $error) {
    selectionAssert($error->getMessage() === 'LATRA delivery requires an endpoint', 'Selection guard message changed');
}

echo "PASS delivery transport selection defaults to mock, stays lazy and fails closed\n";
