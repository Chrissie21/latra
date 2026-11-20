<?php
/**
 * Stand-in LATRA receiver for live delivery verification.
 *
 * Loopback or an explicitly allowed container network. It accepts the legacy
 * delivery wire format (POST JSON, Basic
 * auth, X-Idempotency-Key), appends every request to a plain-text log, and
 * answers {"status":"1"} as assumed by the client. Failure modes
 * are selected with ?mode= for retry/review checks.
 *
 * Configuration (environment):
 *   LATRA_RECEIVER_LOG   required, path to the text log (created 0600)
 *   LATRA_RECEIVER_USER  optional, require this Basic-auth username
 *   LATRA_RECEIVER_PASS  optional, require this Basic-auth password
 *   LATRA_RECEIVER_ALLOW optional, comma-separated extra IPv4 addresses/CIDRs
 *                        (loopback is always allowed) so the receiver can run
 *                        as a separate service in a container network
 *
 * This file never forwards anything and holds no production credentials.
 */
declare(strict_types=1);

/** True when the address is loopback or inside one of the explicit ranges. */
function receiverAllows(string $remote, string $extra): bool
{
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        return true;
    }
    if ($extra === '' || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $address = ip2long($remote);
    foreach (array_filter(array_map('trim', explode(',', $extra))) as $range) {
        [$network, $bits] = array_pad(explode('/', $range, 2), 2, '32');
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !ctype_digit($bits) || (int) $bits > 32) {
            continue;
        }
        $mask = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits));
        if (($address & $mask) === (ip2long($network) & $mask)) {
            return true;
        }
    }
    return false;
}

if (PHP_SAPI !== 'cli'
    && !receiverAllows((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) (getenv('LATRA_RECEIVER_ALLOW') ?: ''))) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json');

$logPath = (string) (getenv('LATRA_RECEIVER_LOG') ?: '');
if ($logPath === '') {
    http_response_code(500);
    echo json_encode(['status' => '0', 'error' => 'LATRA_RECEIVER_LOG is not configured']);
    exit;
}

$mode = (string) ($_GET['mode'] ?? 'ok');
$expectedUser = (string) (getenv('LATRA_RECEIVER_USER') ?: '');
$expectedPass = (string) (getenv('LATRA_RECEIVER_PASS') ?: '');

$givenUser = '';
$authOk = true;
$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($header === '' && function_exists('apache_request_headers')) {
    // Some SAPI configurations expose it only through the request header table.
    $headers = apache_request_headers();
    $header = is_array($headers) ? (string) ($headers['Authorization'] ?? '') : '';
}
if ($header !== '' && preg_match('/^Basic\s+(.+)$/i', $header, $match)) {
    $decoded = base64_decode($match[1], true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        $authOk = false;
    } else {
        [$givenUser, $givenPass] = explode(':', $decoded, 2);
        if ($expectedUser !== '') {
            $authOk = hash_equals($expectedUser, $givenUser) && hash_equals($expectedPass, $givenPass);
        }
    }
} elseif ($expectedUser !== '') {
    $authOk = false;
}

$key = (string) ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '');
$rawBody = (string) file_get_contents('php://input');
$decodedBody = json_decode($rawBody, true);
$items = is_array($decodedBody) && is_array($decodedBody['items'] ?? null) ? $decodedBody['items'] : null;

$keyIndex = $logPath . '.keys';
$seen = 0;
$indexHandle = null;

$block = static function () use ($key, $givenUser, $rawBody, $mode, &$seen, $items): string {
    $safeBody = json_encode(json_decode($rawBody, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return "\n==== " . gmdate('Y-m-d\TH:i:s\Z') . " ====\n"
        . 'path:        ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n"
        . 'method:      ' . ($_SERVER['REQUEST_METHOD'] ?? '') . "\n"
        . 'remote:      ' . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n"
        . 'mode:        ' . $mode . "\n"
        . 'basic_user:  ' . ($givenUser === '' ? '<none>' : $givenUser) . "\n"
        . 'idempotency: ' . ($key === '' ? '<missing>' : $key) . "\n"
        . 'repeat:      ' . $seen . "\n"
        . 'items:       ' . ($items === null ? '<invalid>' : count($items)) . "\n"
        . 'body_base64: ' . base64_encode($rawBody) . "\n"
        . "body:\n" . ($safeBody === false ? $rawBody : $safeBody) . "\n";
};

$append = static function (string $path, string $data): void {
    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to append to the delivery log');
    }
    try {
        if (!chmod($path, 0600) || !flock($handle, LOCK_EX) || fseek($handle, 0, SEEK_END) !== 0
            || fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
            throw new RuntimeException('Unable to persist the delivery log');
        }
    } finally {
        fclose($handle);
    }
};

try {
    if (!$authOk) {
        $append($logPath, $block() . "result:      rejected (unauthenticated)\n");
        http_response_code(401);
        echo json_encode(['status' => '0', 'error' => 'Unauthorized']);
        exit;
    }
    if ($key === '' || $items === null) {
        $append($logPath, $block() . "result:      rejected (malformed request)\n");
        http_response_code(400);
        echo json_encode(['status' => '0', 'error' => 'Idempotency key and an items array are required']);
        exit;
    }
    if (in_array($mode, ['503', '429', '400'], true)) {
        $append($logPath, $block() . "result:      simulated HTTP {$mode}\n");
        if ($mode === '429') {
            header('Retry-After: 5');
        }
        http_response_code((int) $mode);
        echo json_encode(['status' => '0']);
        exit;
    }
    if ($mode === 'status0') {
        $append($logPath, $block() . "result:      HTTP 200 with status 0\n");
        echo json_encode(['status' => '0', 'error' => 'Rejected by simulated LATRA rule']);
        exit;
    }

    // Hold one lock across duplicate lookup, evidence and acceptance. Separate
    // Apache workers must not both call the same delivery key a first request.
    $indexHandle = fopen($keyIndex, 'c+b');
    if ($indexHandle === false || !chmod($keyIndex, 0600) || !flock($indexHandle, LOCK_EX)) {
        throw new RuntimeException('Unable to lock the delivery index');
    }
    while (($known = fgets($indexHandle)) !== false) {
        if (hash_equals(rtrim($known, "\r\n"), $key)) $seen++;
    }
    $append($logPath, $block() . "result:      accepted\n");
    if (fwrite($indexHandle, $key . "\n") !== strlen($key) + 1 || !fflush($indexHandle)) {
        throw new RuntimeException('Unable to persist the delivery key');
    }
    flock($indexHandle, LOCK_UN);
    fclose($indexHandle);
    $indexHandle = null;
    if ($mode === 'slow') {
        sleep(2);
    }
    echo json_encode([
        'status' => '1',
        'idempotency_key' => $key,
        'received_items' => count($items),
        'duplicate' => $seen > 0,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['status' => '0', 'error' => 'Receiver log unavailable']);
} finally {
    if (is_resource($indexHandle)) fclose($indexHandle);
}
