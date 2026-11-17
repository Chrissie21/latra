<?php
/** Operator action use cases: submissions, settings changes, bulk progress and health. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';

use Latra\Application\Operations\ManageOperatorSettings;
use Latra\Application\Operations\OperatorActionRepository;
use Latra\Application\Operations\PushActivityToVehicles;
use Latra\Application\Operations\ReadSchedulerHealth;
use Latra\Application\Operations\SubmitOperatorPayload;

final class MemoryOperatorActions implements OperatorActionRepository
{
    public array $calls = [];
    public array $messages = [
        5 => ['id' => 5, 'msg_id' => 5, 'vehicle_id' => 1, 'activity_id' => 2, 'vehicle_reg_no' => 'SYNTHETIC',
            'imei' => '123456789012345', 'name' => 'Synthetic', 'wialon_msg_time' => 1700000000,
            'addon_info' => '[]', 'fuel_info' => '[]'],
    ];
    public array $vehicles = [1 => ['id' => 1, 'vehicle_reg_no' => 'SYNTHETIC', 'imei' => '123456789012345', 'wialon_unit_id' => 123]];
    public array $latest = [1 => ['id' => 11, 'vehicle_id' => 1, 'wialon_msg_time' => 1700000100, 'raw_data' => '{}']];
    public array $pushLogs = [7 => ['id' => 7, 'vehicle_id' => 1, 'payload' => '{"items":[{"activity_id":"2","timestamp":"1700000000000"}]}']];
    public ?array $mapping = null;
    public array $savedMappings = [];
    public array $activeFlags = [];
    public array $deleted = [];
    public array $health = ['last_run_at' => '2026-11-16 00:00:00', 'enabled_buckets' => 3, 'backlog' => 4, 'dead_letter' => 2];

    public function messageForRelay(int $messageId): ?array { return $this->messages[$messageId] ?? null; }
    public function messageForSubmission(int $messageId): ?array { return $this->messages[$messageId] ?? null; }
    public function vehicle(int $vehicleId): ?array { return $this->vehicles[$vehicleId] ?? null; }
    public function latestMessage(int $vehicleId): ?array { return $this->latest[$vehicleId] ?? null; }
    public function pushLog(int $id): ?array { return $this->pushLogs[$id] ?? null; }
    public function deletePushLog(int $id): void { $this->deleted[] = $id; }
    public function activityMapping(int $vehicleId, int $activityId): ?array { return $this->mapping; }
    public function insertActivityMapping(int $vehicleId, int $activityId, int $enabled): void
    {
        $this->calls[] = ['insert', $vehicleId, $activityId, $enabled];
    }
    public function updateActivityMapping(int $id, int $enabled): void { $this->calls[] = ['update', $id, $enabled]; }
    public function setVehicleActive(int $vehicleId, int $active): void { $this->activeFlags[] = [$vehicleId, $active]; }
    public function schedulerHealth(): array { return $this->health; }
}

function assertSame(mixed $expected, mixed $actual, string $why): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($why . ': expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function expectFailure(callable $work, string $message, string $why): void
{
    try { $work(); } catch (RuntimeException $error) {
        assertSame($message, $error->getMessage(), $why);
        return;
    }
    throw new RuntimeException($why . ': no failure raised');
}

$repo = new MemoryOperatorActions();
$queued = [];
$builder = static fn(array $vehicle, array $source, int $activity, array $addon, array $fuel): array
    => ['vehicle_reg_no' => $vehicle['vehicle_reg_no'], 'imei' => $vehicle['imei'],
        'items' => [['activity_id' => (string) $activity, 'timestamp' => (string) ($source['wialon_msg_time'] * 1000)]]];
$submit = new SubmitOperatorPayload($repo, $builder, static fn(array $payload): array => [],
    static function (array $vehicle, array $payload) use (&$queued): array {
        $queued[] = [$vehicle['id'], $payload['items'][0]['activity_id']];
        return ['success' => true, 'queued' => 1, 'message' => 'Saved to the durable relay.'];
    });

$result = $submit->fromMessage(5);
assertSame(true, $result['success'], 'Message submission did not queue');
assertSame([[1, '2']], $queued, 'Message submission lost the owning vehicle or activity');
expectFailure(static fn() => $submit->fromMessage(404), 'Message not found', 'Missing message was accepted');
expectFailure(static fn() => $submit->fromMessagePayload(5, '{"vehicle_reg_no":"SYNTHETIC"}'),
    'Payload must contain an items array', 'Payload without items was accepted');
assertSame(true, $submit->fromMessagePayload(5, '{"items":[{"activity_id":"2"}]}')['success'],
    'Edited payload for a stored message did not queue');
expectFailure(static fn() => $submit->fromCustomPayload(1, 'not-json'), 'Invalid JSON payload',
    'Invalid custom JSON was accepted');
assertSame(true, $submit->fromCustomPayload(1, '{"items":[{"activity_id":"2"}]}')['success'],
    'Custom payload did not queue');
assertSame(true, $submit->fromStoredPayload(7)['success'], 'Stored payload was not replayed');
expectFailure(static fn() => $submit->fromStoredPayload(8), 'Log entry not found', 'Missing push log was accepted');
$repo->pushLogs[8] = ['id' => 8, 'vehicle_id' => 1, 'payload' => 'not-json'];
expectFailure(static fn() => $submit->fromStoredPayload(8),
    'Stored payload is invalid JSON. Use Edit to rebuild it.', 'Invalid stored payload was accepted');

$invalid = new SubmitOperatorPayload($repo, $builder, static fn(array $payload): array => ['items[0].timestamp is required'],
    static fn(array $vehicle, array $payload): array => ['success' => true]);
expectFailure(static fn() => $invalid->fromCustomPayload(1, '{"items":[{"activity_id":"2"}]}'),
    'Invalid LATRA payload: items[0].timestamp is required', 'Validation errors were not surfaced');

$failing = new SubmitOperatorPayload($repo, $builder, static fn(array $payload): array => [],
    static function (array $vehicle, array $payload): array {
        throw new \Latra\Domain\Exception\InvalidSubmission('identity mismatch');
    });
assertSame(['success' => false, 'message' => 'identity mismatch'], $failing->fromCustomPayload(1, '{"items":[{"activity_id":"2"}]}'),
    'Queue failure did not return a failure result');

$settings = new ManageOperatorSettings($repo);
$settings->setActivity(1, 2, 1);
assertSame([['insert', 1, 2, 1]], $repo->calls, 'New activity mapping was not inserted');
$repo->calls = [];
$repo->mapping = ['id' => 9];
$settings->setActivity(1, 2, 0);
assertSame([['update', 9, 0]], $repo->calls, 'Existing activity mapping was not updated');
$settings->setVehicleActive(1, 0);
$settings->deletePushLog(3);
assertSame([[1, 0]], $repo->activeFlags, 'Vehicle active flag was not written');
assertSame([3], $repo->deleted, 'Push log entry was not deleted');

$events = [];
$bulk = new PushActivityToVehicles($repo, $builder,
    static fn(int $activity, array $params, array $custom): ?array => $custom ? ['trip' => true] : [],
    static fn(array $params): array => ['fuel' => true],
    static fn(int $vehicle, int $at): array => ['distance' => 1, 'at' => $at],
    static function (array $vehicle, array $payload) use (&$queued): array {
        $queued[] = [$vehicle['id'], $payload['items'][0]['activity_id']];
        return ['success' => true, 'queued' => 1, 'message' => 'Saved to the durable relay.'];
    },
    static fn(): int => 1700000200);
$result = $bulk->run([1, 999], 2, static function (array $event) use (&$events): void { $events[] = $event; });
assertSame(['success' => 1, 'failed' => 1], $result, 'Bulk submission counts changed');
assertSame('init', $events[0]['type'], 'Bulk submission lost its init event');
assertSame('queued', $events[1]['latra_status'], 'Bulk submission did not report the queued status');
assertSame('fail', $events[2]['latra_status'], 'Bulk submission did not isolate a missing vehicle');
assertSame('done', end($events)['type'], 'Bulk submission lost its done event');
expectFailure(static fn() => $bulk->run([], 2, static function (array $event): void {}),
    'No vehicle IDs provided', 'Empty bulk selection was accepted');
expectFailure(static fn() => $bulk->run([1], 0, static function (array $event): void {}),
    'Activity ID required', 'Missing activity was accepted');

$health = (new ReadSchedulerHealth($repo, static fn(): string|false => '2026-11-16 12:00:00', 'production', '2.0.0'))->read();
assertSame([
    'ok' => true, 'last_tick_at' => '2026-11-16 12:00:00', 'last_run_at' => '2026-11-16 00:00:00',
    'enabled_buckets' => 3, 'backlog' => 4, 'dead_letter' => 2, 'app_env' => 'production', 'app_version' => '2.0.0',
], $health, 'Scheduler health shape changed');
$noTick = (new ReadSchedulerHealth($repo, static fn(): string|false => false, 'production', '2.0.0'))->read();
assertSame(null, $noTick['last_tick_at'], 'Missing tick file must report null');

echo "PASS operator submissions, settings changes, bulk progress and scheduler health preserve their contracts\n";
