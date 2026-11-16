<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Collection\LoadWialonWindow;
use Latra\Application\Collection\WialonTransport;
use Latra\Application\Collection\WialonWindowSession;
use Latra\Domain\Collection\WialonWindowPolicy;
use Latra\Infrastructure\Wialon\WialonApiClient;

function windowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function windowRejects(callable $operation, string $needle): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $needle)) {
            throw new RuntimeException('Unexpected failure: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected failure containing: ' . $needle);
}

final class FakeWindowSession implements WialonWindowSession
{
    public array $calls = [];
    public int $unloads = 0;
    public ?array $loaded = null;
    public array $pages = [];

    public function loadWindow(int $unitId, int $from, int $to, int $count, int $maxBytes): ?array
    {
        $this->calls[] = ['window', $unitId, $from, $to, $count, $maxBytes];
        return $this->loaded;
    }

    public function loadPage(int $from, int $to, int $maxBytes): ?array
    {
        $this->calls[] = ['page', $from, $to, $maxBytes];
        return $this->pages[$from] ?? null;
    }

    public function unload(): void
    {
        $this->unloads++;
    }
}

function wialonMessage(int $time): array
{
    return ['t' => $time, 'pos' => ['x' => 39.2, 'y' => -6.8, 's' => 40]];
}

// ---------------------------------------------------------------------------
// Domain policy
// ---------------------------------------------------------------------------
WialonWindowPolicy::assertRequest(1, 0, 0, 1, 1, 1);
windowRejects(fn() => WialonWindowPolicy::assertRequest(0, 0, 1, 1, 1, 1), 'Explicit bounded Wialon window required');
windowRejects(fn() => WialonWindowPolicy::assertRequest(1, 5, 4, 1, 1, 1), 'Explicit bounded Wialon window required');
windowRejects(fn() => WialonWindowPolicy::assertRequest(1, 0, 1, 0, 1, 1), 'Explicit bounded Wialon window required');
windowRejects(fn() => WialonWindowPolicy::assertRequest(1, 0, 1, 1, 0, 1), 'Explicit bounded Wialon window required');
windowRejects(fn() => WialonWindowPolicy::assertRequest(1, 0, 1, 1, 1, 0), 'Explicit bounded Wialon window required');

windowAssert(WialonWindowPolicy::assertTotal(0, 10) === 0, 'Empty total rejected');
windowAssert(WialonWindowPolicy::assertTotal(10, 10) === 10, 'At-limit total rejected');
windowRejects(fn() => WialonWindowPolicy::assertTotal(11, 10), 'needs subdivision');
windowRejects(fn() => WialonWindowPolicy::assertTotal(null, 10), 'Invalid or over-bound Wialon count');
windowRejects(fn() => WialonWindowPolicy::assertTotal(-1, 10), 'Invalid or over-bound Wialon count');
windowRejects(fn() => WialonWindowPolicy::assertTotal('3', 10), 'Invalid or over-bound Wialon count');

$page = [wialonMessage(100), wialonMessage(101)];
windowAssert(WialonWindowPolicy::assertPage($page, 2) === $page, 'Valid page changed');
windowRejects(fn() => WialonWindowPolicy::assertPage(null, 2), 'Incomplete Wialon page');
windowRejects(fn() => WialonWindowPolicy::assertPage(['messages' => []], 2), 'Incomplete Wialon page');
windowRejects(fn() => WialonWindowPolicy::assertPage([wialonMessage(100)], 2), 'Incomplete Wialon page');

$message = wialonMessage(100);
windowAssert(WialonWindowPolicy::assertMessage($message, 100, 200) === $message, 'In-range message changed');
windowRejects(fn() => WialonWindowPolicy::assertMessage(['t' => 99], 100, 200), 'Invalid Wialon source timestamp');
windowRejects(fn() => WialonWindowPolicy::assertMessage(['t' => 201], 100, 200), 'Invalid Wialon source timestamp');
windowRejects(fn() => WialonWindowPolicy::assertMessage(['t' => '100'], 100, 200), 'Invalid Wialon source timestamp');
windowRejects(fn() => WialonWindowPolicy::assertMessage('not a message', 100, 200), 'Invalid Wialon source timestamp');

windowAssert(WialonWindowPolicy::addBytes(0, $message, 16384) === strlen(json_encode($message)), 'Byte accounting changed');
windowRejects(fn() => WialonWindowPolicy::addBytes(16384, $message, 16384), 'exceeds byte bound');
windowRejects(fn() => WialonWindowPolicy::assertEmptyConsistency(0, [$message]), 'Inconsistent empty Wialon window');
WialonWindowPolicy::assertEmptyConsistency(0, []);
WialonWindowPolicy::assertEmptyConsistency(2, [$message]);

// ---------------------------------------------------------------------------
// Application use case: paging, bounds, byte budget, unload
// ---------------------------------------------------------------------------
$now = 1700000000;
$session = new FakeWindowSession();
$session->loaded = ['count' => 3, 'messages' => [wialonMessage($now), wialonMessage($now + 1)]];
$session->pages = [2 => [wialonMessage($now + 3)]];
$messages = (new LoadWialonWindow($session))->execute(123, $now, $now + 3, 2, 10, 16384);
windowAssert(count($messages) === 3, 'Complete window lost a page');
windowAssert(array_column($messages, 't') === [$now, $now + 1, $now + 3], 'Window message order changed');
windowAssert($session->calls === [
    ['window', 123, $now, $now + 3, 2, 16384],
    ['page', 2, 3, 16384],
], 'Window page bounds changed: ' . json_encode($session->calls));
windowAssert($session->unloads === 1, 'Window session was not unloaded exactly once');

$empty = new FakeWindowSession();
$empty->loaded = ['count' => 0, 'messages' => []];
windowAssert((new LoadWialonWindow($empty))->execute(123, $now, $now + 3, 2, 10, 16384) === [], 'Empty window rejected');
windowAssert($empty->unloads === 1, 'Empty window did not unload its session');

$over = new FakeWindowSession();
$over->loaded = ['count' => 11, 'messages' => []];
windowRejects(
    fn() => (new LoadWialonWindow($over))->execute(123, $now, $now + 3, 2, 10, 16384),
    'needs subdivision',
);
windowAssert($over->unloads === 1, 'Failed window did not unload its session');

$broken = new FakeWindowSession();
$broken->loaded = ['count' => 2, 'messages' => [wialonMessage($now)]];
windowRejects(
    fn() => (new LoadWialonWindow($broken))->execute(123, $now, $now + 3, 2, 10, 16384),
    'Incomplete Wialon page',
);
windowAssert($broken->unloads === 1, 'Incomplete page did not unload its session');

$inconsistent = new FakeWindowSession();
$inconsistent->loaded = ['count' => 0, 'messages' => [wialonMessage($now)]];
windowRejects(
    fn() => (new LoadWialonWindow($inconsistent))->execute(123, $now, $now + 3, 2, 10, 16384),
    'Inconsistent empty Wialon window',
);

$tightBytes = new FakeWindowSession();
$tightBytes->loaded = ['count' => 1, 'messages' => [wialonMessage($now)]];
windowRejects(
    fn() => (new LoadWialonWindow($tightBytes))->execute(123, $now, $now + 3, 2, 10, 32),
    'exceeds byte bound',
);

$outOfRange = new FakeWindowSession();
$outOfRange->loaded = ['count' => 1, 'messages' => [wialonMessage($now + 10)]];
windowRejects(
    fn() => (new LoadWialonWindow($outOfRange))->execute(123, $now, $now + 3, 2, 10, 16384),
    'Invalid Wialon source timestamp',
);

// ---------------------------------------------------------------------------
// Extracted client: protocol shape, bounded transport, session queries
// ---------------------------------------------------------------------------
final class RecordingTransport implements WialonTransport
{
    public array $requests = [];
    public array $responses = [];

    public function post(string $url, string $encodedParams, ?int $maxResponseBytes): ?array
    {
        $this->requests[] = [$url, $encodedParams, $maxResponseBytes];
        return array_shift($this->responses);
    }
}

$transport = new RecordingTransport();
$transport->responses = [
    ['eid' => 'synthetic-session'],
    ['items' => [['id' => 9, 'nm' => 'Synthetic']]],
    ['item' => ['id' => 9, 'sens' => [['t' => 'engine operation', 'p' => ' ign ']]]],
    ['item' => ['id' => 9]],
    ['messages' => [wialonMessage($now)]],
    null,
];
$client = new WialonApiClient('https://wialon.test/root/', 'synthetic-token', $transport);

windowAssert($client->getSid() === null && $client->isLoggedIn() === false, 'Fresh client has a session');
windowAssert($client->login() === true, 'Login failed');
windowAssert($client->getSid() === 'synthetic-session' && $client->isLoggedIn() === true, 'Login did not retain the session');
windowAssert(
    $transport->requests[0][0] === 'https://wialon.test/root/wialon/ajax.html?svc=token%2Flogin',
    'Login URL changed: ' . $transport->requests[0][0],
);
windowAssert(
    $transport->requests[0][1] === 'params=' . urlencode(json_encode(['token' => 'synthetic-token'])),
    'Login body changed',
);

windowAssert($client->getUnits() === [['id' => 9, 'nm' => 'Synthetic']], 'Unit listing changed');
windowAssert(str_contains($transport->requests[1][0], '&sid=synthetic-session'), 'Session id was not sent');
windowAssert($client->getEngineSensorParam(9) === 'ign', 'Engine sensor lookup changed');
windowAssert($client->getUnitData(9) === ['id' => 9], 'Unit data lookup changed');
windowAssert(
    $client->getMessages(9, $now, $now + 3, 10) === [wialonMessage($now)],
    'Plain message load changed',
);
windowAssert($client->request('core/unknown', []) === null, 'Failed transport must return null');

$client->logout();
windowAssert($client->getSid() === null, 'Logout did not clear the session');

// The compatibility class is the same client with the legacy constructor contract.
windowAssert(
    is_subclass_of('WialonAPI', WialonApiClient::class),
    'WialonAPI must remain a compatibility subclass of the extracted client',
);
windowAssert(
    (new ReflectionMethod('WialonAPI', 'getCompleteWindow'))->getDeclaringClass()->getName() === WialonApiClient::class,
    'WialonAPI stopped inheriting the extracted complete-window loader',
);

echo "PASS Wialon window policy, paging use case, bounded client protocol and compatibility alias\n";
