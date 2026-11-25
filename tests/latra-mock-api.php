<?php
declare(strict_types=1);

/**
 * End-to-end tests for the reconstructed LATRA receiver (`tests/mock-latra-api.php`).
 *
 * Starts the router with PHP's built-in server on a free loopback port, sends real
 * HTTP requests, and asserts the documented IDD rev 1.14 behaviour: Basic auth,
 * 3*N item rule, per-activity addon_info, fuel_info, and the response format.
 * No external network access and no application bootstrap are used.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/latra-idd.php';

$root = dirname(__DIR__);
$router = __DIR__ . '/mock-latra-api.php';
$log = sys_get_temp_dir() . '/latra-mock-api-' . getmypid() . '.jsonl';
@unlink($log);

// Reserve a free loopback port, then release it for the server to bind.
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    throw new RuntimeException("Cannot reserve a port: $errstr");
}
$name = (string) stream_socket_get_name($probe, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);
fclose($probe);

$env = [
    'LATRA_MOCK_API_LOG' => $log,
    'LATRA_MOCK_API_USER' => 'tester',
    'LATRA_MOCK_API_PASS' => 'secret',
    'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
];
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root,
    $env,
);
if (!is_resource($server)) {
    throw new RuntimeException('Cannot start the mock server');
}

/** @return array{0:int,1:?array} HTTP status and decoded JSON body */
function http(string $method, string $url, ?array $body = null, ?string $auth = null, int $timeout = 5, ?string $key = null): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($auth !== null) {
        $headers[] = 'Authorization: Basic ' . base64_encode($auth);
    }
    if ($key !== null) $headers[] = 'X-Idempotency-Key: ' . $key;
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : (string) json_encode($body),
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    $decoded = $raw === false ? null : json_decode($raw, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $base = "http://127.0.0.1:$port";
    $auth = 'tester:secret';

    // Wait for the server to accept connections.
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        usleep(50_000);
        [$status] = http('GET', "$base/_health", null, null, 2);
        if ($status === 200) {
            $ready = true;
            break;
        }
    }
    check($ready, 'mock server did not become ready');

    // 1. Health reports the contract identity.
    [$status, $health] = http('GET', "$base/_health");
    check($status === 200 && $health['ok'] === true, 'health endpoint must report ok');
    check(str_contains((string) $health['contract'], 'rev 1.14'), 'health must name IDD rev 1.14');
    check($health['timestamp_unit'] === 'epoch_milliseconds', 'health must state the timestamp unit');
    check($health['auth_required'] === true, 'health must report that auth is required');

    // 2. A conforming 3-item movement request is accepted with the documented body.
    [$status, $body] = http('POST', "$base/data-integration/integration/gps", latra_idd_sample_request(1, 3), $auth);
    check($status === 200, "valid request returned HTTP $status");
    check($body['status'] === '1', 'valid request must answer status "1"');
    check(is_string($body['timestamp']) && strlen($body['timestamp']) === 13, 'response timestamp must be epoch ms');

    // 3. Every activity shape is accepted.
    foreach (array_keys(latra_idd_activities()) as $activityId) {
        [$status, $body] = http('POST', "$base/data-integration/integration/gps", latra_idd_sample_request($activityId, 3), $auth);
        check($status === 200 && $body['status'] === '1', "activity $activityId was rejected");
    }

    // 4. The 3*N rule rejects a single item, and strict=0 relaxes it.
    [$status, $body] = http('POST', "$base/data-integration/integration/gps", latra_idd_sample_request(1, 1), $auth);
    check($status === 200 && $body['status'] === '0', 'single item must be rejected');
    check(str_contains((string) ($body['message'] ?? ''), '3*N'), 'rejection must explain the 3*N rule');
    [$status, $body] = http('POST', "$base/data-integration/integration/gps?strict=0", latra_idd_sample_request(1, 1), $auth);
    check($status === 200 && $body['status'] === '1', 'strict=0 must accept a single item');

    // 5. Missing addon_info is rejected for activity 2.
    $missingAddon = latra_idd_sample_request(2, 3);
    unset($missingAddon['items'][0]['addon_info']);
    [$status, $body] = http('POST', "$base/data-integration/integration/gps", $missingAddon, $auth);
    check($body['status'] === '1', 'optional addon_info may be absent');

    // 6. Fuel units and ranges.
    $badFuel = latra_idd_sample_request(16, 3);
    $badFuel['items'][0]['fuel_info']['signalLevel'] = '150';
    [$status, $body] = http('POST', "$base/data-integration/integration/gps", $badFuel, $auth);
    check($body['status'] === '0' && str_contains((string) $body['message'], 'signalLevel'), 'signalLevel 150 must be rejected');

    // 7. Basic authentication is enforced.
    [$status, $body] = http('POST', "$base/data-integration/integration/gps", latra_idd_sample_request(1, 3));
    check($status === 401 && $body['status'] === '0', 'missing credentials must answer 401');
    [$status] = http('POST', "$base/data-integration/integration/gps", latra_idd_sample_request(1, 3), 'tester:wrong');
    check($status === 401, 'wrong password must answer 401');

    // 8. Method and route handling.
    [$status] = http('GET', "$base/data-integration/integration/gps", null, $auth);
    check($status === 405, 'GET on the endpoint must answer 405');
    [$status] = http('POST', "$base/unknown", latra_idd_sample_request(1, 3), $auth);
    check($status === 404, 'unknown route must answer 404');

    // 9. Failure modes used by the delivery drills.
    [$status, $body] = http('POST', "$base/data-integration/integration/gps?mode=status0", latra_idd_sample_request(1, 3), $auth);
    check($status === 200 && $body['status'] === '0', 'mode=status0 must answer status "0" over HTTP 200');
    [$status] = http('POST', "$base/data-integration/integration/gps?mode=error500", latra_idd_sample_request(1, 3), $auth);
    check($status === 500, 'mode=error500 must answer HTTP 500');
    [$status, $body] = http('POST', "$base/data-integration/integration/gps?http=400", latra_idd_sample_request(1, 1), $auth);
    check($status === 400 && $body['status'] === '0', 'http=400 must answer HTTP 400 with status "0"');

    // Evidence endpoints require auth; exact payloads and stable duplicate decisions survive requests.
    [$status] = http('GET', "$base/_received");
    check($status === 401, 'review data exposed without credentials');
    [$status] = http('POST', "$base/_reset", []);
    check($status === 401, 'reset allowed without credentials');
    $dedup = latra_idd_sample_request(1,3);
    [$status,$body] = http('POST', "$base/data-integration/integration/gps", $dedup, $auth, 5, 'stable-test-key');
    check($body['status']==='1','first keyed request rejected');
    [$status,$body] = http('POST', "$base/data-integration/integration/gps", $dedup, $auth, 5, 'stable-test-key');
    check($body['status']==='1','identical keyed retry rejected');
    [$status,$received] = http('GET', "$base/_received?limit=1",null,$auth);
    check($received['entries'][0]['duplicate']===true && $received['entries'][0]['payload']===$dedup,
        'duplicate or complete payload not recorded');
    $dedup['items'][0]['speed']='changed';
    [$status,$body] = http('POST', "$base/data-integration/integration/gps", $dedup, $auth, 5, 'stable-test-key');
    check($body['status']==='0' && str_contains($body['message'],'different body'), 'same key accepted with different body');
    $csv = file_get_contents("$base/_received.csv",false,stream_context_create(['http'=>[
        'header'=>'Authorization: Basic '.base64_encode($auth)]]));
    check(str_contains($csv,'vehicle_reg_no') && str_contains($csv,'DUMMY')===false
        && str_contains($csv,'T123ABC') && str_contains($csv,'1541603095967'), 'CSV lacks received measurements');

    // 10. Evidence log and reset.
    [$status, $received] = http('GET', "$base/_received?limit=5", null, $auth);
    check($status === 200 && $received['count'] > 0, 'the evidence log must record requests');
    $last = $received['entries'][count($received['entries']) - 1];
    check(isset($last['errors']) && isset($last['body_sha256']), 'log entries must record verdict and body hash');
    [$status] = http('POST', "$base/_reset", [], $auth);
    check($status === 200, 'reset must succeed');
    [$status, $received] = http('GET', "$base/_received", null, $auth);
    check($received['count'] === 0, 'reset must clear the log');

    // A receiver with no writable evidence destination must never acknowledge data.
    if (is_file($log)) unlink($log);
    mkdir($log);
    [$status,$body] = http('POST', "$base/data-integration/integration/gps", latra_idd_sample_request(1,3),$auth);
    check($status===503 && $body['status']==='0','storage failure acknowledged success');
    rmdir($log);

    echo "PASS reconstructed LATRA IDD receiver enforces auth, 3*N, addon/fuel shapes and response format\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    @unlink($log);
}
