<?php
/**
 * Real LATRA transport contract, proved against a loopback receiver.
 *
 * The receiver writes every request to a text file, so this suite asserts the
 * exact wire facts a live canary depends on: Basic authentication, the
 * persisted delivery key, the JSON body, and that credentials never leak into
 * transport errors. No network destination outside 127.0.0.1 is contacted.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }

require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Delivery\DeliveryDisposition;
use Latra\Domain\Delivery\DeliveryResponsePolicy;
use Latra\Infrastructure\Delivery\LatraRelayTransport;

function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }

$private = sys_get_temp_dir() . '/latra-transport-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
$cleanup = static function () use ($private): void {
    foreach (glob($private . '/*') ?: [] as $file) { @unlink($file); }
    @rmdir($private);
};

$logPath = $private . '/delivery.txt';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($socket !== false, 'Cannot allocate a loopback port');
$listen = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $listen . '/latra-receiver.php';

$env = getenv();
$env['LATRA_RECEIVER_LOG'] = $logPath;
$env['LATRA_RECEIVER_USER'] = 'synthetic-user';
$env['LATRA_RECEIVER_PASS'] = 'synthetic-secret-value';
$pipes = [];
$server = proc_open(
    [PHP_BINARY, '-S', $listen, __DIR__ . '/latra-receiver.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $private . '/receiver.log', 'w'],
     2 => ['file', $private . '/receiver.log', 'a']],
    $pipes,
    null,
    $env,
);
check(is_resource($server), 'Cannot start the receiver');

$ready = false;
for ($i = 0; $i < 60; $i++) {
    $probe = @stream_socket_client('tcp://' . $listen, $errno, $error, .2);
    if ($probe) { fclose($probe); $ready = true; break; }
    usleep(20000);
}
if (!$ready) {
    proc_terminate($server);
    $cleanup();
    throw new RuntimeException('Receiver failed to start');
}

$body = json_encode(['vehicle_reg_no' => 'SYNTHETIC', 'type' => 'poi', 'imei' => '000000000000000',
    'items' => [['MGS_ID' => '1', 'activity_id' => '1', 'idempotency_key' => str_repeat('a', 64) . '-a1']]]);
$key = hash('sha256', 'synthetic-delivery-key');

try {
    // Constructor guards: an explicit URL and credentials are mandatory.
    foreach ([
        static fn() => new LatraRelayTransport('', 'u', 'p'),
        static fn() => new LatraRelayTransport('ftp://127.0.0.1/x', 'u', 'p'),
        static fn() => new LatraRelayTransport($base, '', 'p'),
        static fn() => new LatraRelayTransport($base, 'u', ''),
        static fn() => new LatraRelayTransport($base, 'u', 'p', 0, 8),
    ] as $invalid) {
        $rejected = false;
        try { $invalid(); } catch (InvalidArgumentException) { $rejected = true; }
        check($rejected, 'Invalid transport configuration was accepted');
    }

    $transport = new LatraRelayTransport($base, 'synthetic-user', 'synthetic-secret-value');
    $response = $transport($body, $key);
    check($response['code'] === 200, 'Accepted delivery did not return HTTP 200');
    check(($response['data']['status'] ?? null) === '1', 'Accepted delivery did not report status 1');
    check($response['error'] === '', 'Accepted delivery reported a transport error');
    check(DeliveryResponsePolicy::classify($response, 1, 8) === DeliveryDisposition::Accepted,
        'Accepted delivery did not classify as accepted');

    check(is_file($logPath), 'Receiver did not write the delivery log');
    $log = (string) file_get_contents($logPath);
    check(str_contains($log, 'body_base64: ' . base64_encode($body)),
        'Receiver evidence does not preserve the exact request bytes');
    check(str_contains($log, 'basic_user:  synthetic-user'), 'Basic-auth username was not delivered');
    check(str_contains($log, 'idempotency: ' . $key), 'Persisted delivery key was not delivered');
    check(str_contains($log, 'items:       1'), 'Delivery body did not reach the receiver intact');
    check(str_contains($log, str_repeat('a', 64) . '-a1'), 'Activity identity was not delivered');
    check(!str_contains($log, 'synthetic-secret-value'), 'Receiver log contains the password');
    check(!str_contains($log, base64_encode('synthetic-user:synthetic-secret-value')),
        'Receiver log contains the encoded credentials');

    // Replaying the same persisted key must remain visible as a duplicate.
    $repeat = $transport($body, $key);
    check(($repeat['data']['duplicate'] ?? null) === true, 'Repeat delivery was not reported as a duplicate');

    // Wrong credentials must fail closed, not retry forever as an unknown outcome.
    $unauthorised = (new LatraRelayTransport($base, 'synthetic-user', 'wrong-secret'))($body, $key);
    check($unauthorised['code'] === 401, 'Wrong credentials were not rejected');
    check(DeliveryResponsePolicy::classify($unauthorised, 1, 8) === DeliveryDisposition::Review,
        'Rejected credentials did not classify for review');

    // Explicit failure modes must produce the documented disposition.
    $cases = [
        ['?mode=status0', 200, DeliveryDisposition::Review, 'status 0'],
        ['?mode=400', 400, DeliveryDisposition::Review, 'permanent 400'],
        ['?mode=503', 503, DeliveryDisposition::Retry, 'transient 503'],
        ['?mode=429', 429, DeliveryDisposition::Retry, 'rate-limited 429'],
    ];
    foreach ($cases as [$query, $expectedCode, $expectedDisposition, $label]) {
        $result = (new LatraRelayTransport($base . $query, 'synthetic-user', 'synthetic-secret-value'))($body, $key);
        check($result['code'] === $expectedCode, 'Unexpected HTTP code for ' . $label);
        check(DeliveryResponsePolicy::classify($result, 1, 8) === $expectedDisposition,
            'Unexpected disposition for ' . $label);
    }

    // An unreachable endpoint is an unknown outcome: retry, never a false acceptance.
    $dead = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $deadAddress = stream_socket_get_name($dead, false);
    fclose($dead);
    $unreachable = (new LatraRelayTransport('http://' . $deadAddress . '/x', 'u', 'dead-secret', 2, 1))($body, $key);
    check($unreachable['code'] === 0, 'Unreachable endpoint reported an HTTP code');
    check($unreachable['error'] !== '', 'Unreachable endpoint did not report an error');
    check(DeliveryResponsePolicy::classify($unreachable, 1, 8) === DeliveryDisposition::Retry,
        'Unreachable endpoint did not classify for retry');
    check(!str_contains($unreachable['error'], 'dead-secret'), 'Unreachable error leaked credentials');
} finally {
    proc_terminate($server);
    proc_close($server);
    $cleanup();
}

echo "PASS LATRA transport wire contract, auth, idempotency, dispositions and error hygiene\n";
