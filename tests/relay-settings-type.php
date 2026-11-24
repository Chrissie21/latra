<?php
/**
 * Enrolled relay settings are validated and converted once, at the persistence
 * boundary. Downstream composition reads named, already-positive budgets instead
 * of re-checking an untyped row array.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Collection\RelayIdentityPolicy;
use Latra\Domain\Collection\RelaySettings;

function expectSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' changed: expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

// Stored rows arrive as strings from PDO; the type normalizes them once.
$row = [
    'vehicle_id' => '7',
    'profile' => 'teltonika-fmb',
    'max_records' => '100',
    'max_bytes' => '1048576',
    'max_pending_records' => '250',
    'max_pending_bytes' => '2097152',
    'is_enabled' => '1',
];

$settings = RelaySettings::fromRow($row, 7);
expectSame(7, $settings->vehicleId, 'vehicle id');
expectSame('teltonika-fmb', $settings->profile, 'profile');
expectSame(100, $settings->maxRecords, 'max records');
expectSame(1048576, $settings->maxBytes, 'max bytes');
expectSame(250, $settings->maxPendingRecords, 'max pending records');
expectSame(2097152, $settings->maxPendingBytes, 'max pending bytes');

// The owning vehicle comes from the enrollment lookup, not from the row payload.
$other = RelaySettings::fromRow(['profile' => 'p', 'max_records' => 1, 'max_bytes' => 1,
    'max_pending_records' => 1, 'max_pending_bytes' => 1], 42);
expectSame(42, $other->vehicleId, 'explicit vehicle id');

// The legacy operator diagnostic is preserved for every rejected budget.
$legacyMessage = 'Explicit relay resource budgets required';
// Direct construction must enforce the same invariant as the PDO conversion.
foreach (range(0, 3) as $index) {
    $budgets = [1, 1, 1, 1];
    $budgets[$index] = 0;
    try {
        new RelaySettings(7, 'generic', ...$budgets);
    } catch (RuntimeException $error) {
        expectSame($legacyMessage, $error->getMessage(), 'constructor budget rejection');
        continue;
    }
    throw new RuntimeException('Direct construction accepted a non-positive budget');
}
foreach (RelayIdentityPolicy::BUDGET_KEYS as $key) {
    foreach ([null, 0, -1, 'not-a-number'] as $bad) {
        $candidate = $row;
        if ($bad === null) {
            unset($candidate[$key]);
        } else {
            $candidate[$key] = $bad;
        }
        try {
            RelaySettings::fromRow($candidate, 7);
        } catch (RuntimeException $error) {
            expectSame($legacyMessage, $error->getMessage(), $key . ' rejection message');
            continue;
        }
        throw new RuntimeException('A non-positive ' . $key . ' must not become relay settings');
    }
}

echo "PASS relay settings validate budgets once and expose named values\n";
