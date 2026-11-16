<?php
/** Pure relay coordination rules: unit identity, vehicle ownership and delivery gating. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Collection\RelayDeliveryGatePolicy;
use Latra\Domain\Collection\RelayIdentityPolicy;
use Latra\Domain\Exception\InvalidSubmission;

function relayPolicyExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function relayPolicyRejects(callable $operation, string $needle, string $class = RuntimeException::class): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if (!($error instanceof $class) || !str_contains($error->getMessage(), $needle)) {
            throw new RuntimeException('Unexpected failure: ' . get_class($error) . ': ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected failure containing: ' . $needle);
}

// ---------------------------------------------------------------------------
// Unit identity
// ---------------------------------------------------------------------------
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('wialon:123') === 123, 'Plain relay unit lost its id');
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('wialon:7:poll') === 7, 'Poll stream unit lost its id');
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('wialon:7:events') === 7, 'Event stream unit lost its id');
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('wialon:7:activity:4') === 7, 'Activity stream unit lost its id');
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('manual:wialon:9') === 9, 'Manual unit lost its id');
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('manual:wialon:9:activity:2') === 9, 'Manual activity unit lost its id');
foreach (['', 'wialon', 'wialon:', 'wialon:abc', 'wialon:12abc', 'other:12', 'manual:', 'xmanual:wialon:12'] as $unit) {
    relayPolicyExpect(RelayIdentityPolicy::unitVehicleId($unit) === null, 'Foreign unit resolved to a vehicle: ' . $unit);
}
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId("wialon:12\nextra") === null, 'Trailing data was accepted as a unit');
relayPolicyExpect(RelayIdentityPolicy::unitVehicleId('wialon:0') === 0, 'Zero unit id crashed instead of resolving');

// ---------------------------------------------------------------------------
// Vehicle ownership
// ---------------------------------------------------------------------------
$vehicle = ['id' => 5, 'vehicle_reg_no' => 'T123ABC', 'imei' => '123456789012345'];
RelayIdentityPolicy::assertOwns($vehicle, []);
RelayIdentityPolicy::assertOwns($vehicle, ['vehicle_reg_no' => 'T123ABC']);
RelayIdentityPolicy::assertOwns($vehicle, ['imei' => '123456789012345']);
RelayIdentityPolicy::assertOwns($vehicle, ['vehicle_reg_no' => 'T123ABC', 'imei' => '123456789012345']);
RelayIdentityPolicy::assertOwns($vehicle, ['vehicle_reg_no' => null, 'imei' => null]);
// SQL-compatible comparison: the legacy check cast both sides to string.
RelayIdentityPolicy::assertOwns(['id' => 5, 'vehicle_reg_no' => 123, 'imei' => 987654321], ['vehicle_reg_no' => '123']);
RelayIdentityPolicy::assertOwns(['id' => 5], ['imei' => '']);
relayPolicyRejects(
    fn() => RelayIdentityPolicy::assertOwns($vehicle, ['vehicle_reg_no' => 'T999ZZZ']),
    'Relay payload identity does not match the owning vehicle',
    InvalidSubmission::class,
);
relayPolicyRejects(
    fn() => RelayIdentityPolicy::assertOwns($vehicle, ['imei' => '999999999999999']),
    'Relay payload identity does not match the owning vehicle',
    InvalidSubmission::class,
);
relayPolicyRejects(
    fn() => RelayIdentityPolicy::assertOwns(['id' => 5, 'imei' => null], ['imei' => '123']),
    'Relay payload identity does not match the owning vehicle',
    InvalidSubmission::class,
);

// ---------------------------------------------------------------------------
// Enrollment budgets
// ---------------------------------------------------------------------------
$budgets = ['max_records' => 200, 'max_bytes' => 4096, 'max_pending_records' => 10, 'max_pending_bytes' => 8192];
RelayIdentityPolicy::assertBudgets($budgets);
RelayIdentityPolicy::assertBudgets(array_map('strval', $budgets));
foreach (['max_records', 'max_bytes', 'max_pending_records', 'max_pending_bytes'] as $key) {
    foreach ([0, -1, null, ''] as $invalid) {
        $broken = $budgets;
        $broken[$key] = $invalid;
        relayPolicyRejects(
            fn() => RelayIdentityPolicy::assertBudgets($broken),
            'Explicit relay resource budgets required',
        );
    }
    $missing = $budgets;
    unset($missing[$key]);
    relayPolicyRejects(
        fn() => RelayIdentityPolicy::assertBudgets($missing),
        'Explicit relay resource budgets required',
    );
}
relayPolicyRejects(fn() => RelayIdentityPolicy::assertBudgets([]), 'Explicit relay resource budgets required');

// ---------------------------------------------------------------------------
// Delivery gate
// ---------------------------------------------------------------------------
$enabled = [1 => true, 2 => true, 8 => 1];
relayPolicyExpect(RelayDeliveryGatePolicy::allows($enabled, [1, 8], [1]), 'Fully enabled activity was blocked');
relayPolicyExpect(RelayDeliveryGatePolicy::allows($enabled, [1, 8], []), 'Empty payload was blocked');
relayPolicyExpect(RelayDeliveryGatePolicy::allows($enabled, [1, 8], [8, 1]), 'Multiple allowed activities were blocked');
relayPolicyExpect(!RelayDeliveryGatePolicy::allows($enabled, [1], [8]), 'Activity outside the allowed set was sent');
relayPolicyExpect(!RelayDeliveryGatePolicy::allows([1 => true], [1], [8]), 'Disabled activity was sent');
relayPolicyExpect(!RelayDeliveryGatePolicy::allows([], [1], [1]), 'Vehicle with no enabled activities was sent');
relayPolicyExpect(!RelayDeliveryGatePolicy::allows($enabled, [1, 8], [1, 8, 4]), 'One unknown activity did not block the payload');

// Pending payload decoding keeps its tolerant legacy behaviour.
relayPolicyExpect(RelayDeliveryGatePolicy::activityIds('{"items":[{"activity_id":4},{"activity_id":"8"}]}') === [4, 8],
    'Pending activity ids changed');
relayPolicyExpect(RelayDeliveryGatePolicy::activityIds('{"items":[]}') === [], 'Empty payload produced activities');
relayPolicyExpect(RelayDeliveryGatePolicy::activityIds('{"items":[{}]}') === [], 'Missing activity id produced a value');
relayPolicyExpect(RelayDeliveryGatePolicy::activityIds('{"items":[{"activity_id":null}]}') === [0],
    'Null activity id changed');
relayPolicyExpect(RelayDeliveryGatePolicy::activityIds('not json') === [], 'Malformed payload was not treated as empty');
relayPolicyExpect(RelayDeliveryGatePolicy::activityIds('{"other":1}') === [], 'Payload without items produced activities');

echo "PASS relay identity, ownership, budget and delivery gate policies\n";
