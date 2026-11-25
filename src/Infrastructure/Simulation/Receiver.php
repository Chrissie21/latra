<?php
declare(strict_types=1);

/**
 * Reconstructed LATRA / SUMATRA VTS receiver — IDD rev 1.14.
 *
 * This is a local stand-in for `http://<host>:8090/data-integration/integration/gps`
 * built from `LATRA VTS API SPECIFICATION.md`. It exists so the middleware can be
 * developed and tested against the documented contract before real credentials and
 * the real endpoint are issued. It never forwards anything and holds no secrets.
 *
 * Run:
 *   LATRA_MOCK_API_LOG=/tmp/latra-mock.jsonl php -S 127.0.0.1:8090 tests/mock-latra-api.php
 *
 * Environment:
 *   LATRA_MOCK_API_LOG    required, path to the JSONL evidence log (created 0600)
 *   LATRA_MOCK_API_USER   optional, require this Basic-auth username
 *   LATRA_MOCK_API_PASS   optional, require this Basic-auth password
 *   LATRA_MOCK_API_ALLOW  optional, comma-separated extra IPv4 addresses/CIDRs
 *
 * Query switches (testing aids, not part of the contract):
 *   strict=0        disable the 3*N rule and string-type requirement
 *   http=400        return a non-200 HTTP status with status:"0"
 *   mode=status0    answer {"status":"0"} over HTTP 200
 *   mode=error500   answer HTTP 500
 *   mode=slow       delay 3 seconds
 *   mode=timeout    delay 30 seconds (client-side timeout drill)
 *   mode=duplicate  answer success, record the repeat
 *
 * Routes:
 *   POST /data-integration/integration/gps   the IDD endpoint (also POST /)
 *   GET  /_health                            contract and configuration summary
 *   GET  /_received.csv                     all submissions as CSV
 *   GET  /_received?limit=20                 recent accepted and rejected requests
 *   POST /_reset                             truncate the evidence log
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/IddContract.php';

const MOCK_MAX_BODY_BYTES = 1048576;

/** True when the address is loopback or inside one of the explicit ranges. */
function mockAllows(string $remote, string $extra): bool
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

function mockJson(int $httpStatus, array $body): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

/** Append the complete evidence before acknowledging acceptance; serialize duplicate checks. */
function mockLog(string $path, array $entry, string $key, bool $accepted): array
{
    if ($path === '') throw new RuntimeException('LATRA_MOCK_API_LOG is required');
    $handle = @fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Evidence storage is unavailable');
    try {
        if (!chmod($path, 0600) || !flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock evidence storage');
        $duplicate = false;
        if ($accepted && $key !== '') {
            while (($line = fgets($handle)) !== false) {
                $previous = json_decode($line, true);
                if (($previous['accepted'] ?? false) && ($previous['idempotency_key'] ?? '') === $key) {
                    if (($previous['body_sha256'] ?? '') !== $entry['body_sha256']) {
                        $entry['errors'][] = 'Idempotency key reused with a different body';
                        $accepted = false;
                        break;
                    }
                    $duplicate = true;
                }
            }
        }
        $entry['accepted'] = $accepted;
        $entry['duplicate'] = $duplicate;
        $entry['idempotency_key'] = $key;
        fseek($handle, 0, SEEK_END);
        $start = ftell($handle);
        $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (fwrite($handle, $line) !== strlen($line) || !fflush($handle) || !fsync($handle)) {
            ftruncate($handle, $start);
            throw new RuntimeException('Cannot persist complete evidence');
        }
        return $entry;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

$logPath = (string) (getenv('LATRA_MOCK_API_LOG') ?: '');
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$allow = (string) (getenv('LATRA_MOCK_API_ALLOW') ?: '');

if (!mockAllows($remote, $allow)) {
    mockJson(403, latra_idd_response(false, 'Source address not allowed'));
    exit;
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$query = [];
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $query);

$requiredUser = (string) (getenv('LATRA_MOCK_API_USER') ?: '');
$requiredPass = (string) (getenv('LATRA_MOCK_API_PASS') ?: '');
$authRequired = $requiredUser !== '' || $requiredPass !== '';
$authUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$authPass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
$authOk = !$authRequired
    || ($authUser === $requiredUser && $authPass === $requiredPass);

if ($path === '/_health') {
    mockJson(200, [
        'ok' => true,
        'contract' => 'SUMATRA VTS GPS Vendor Data Integration, IDD rev 1.14',
        'auth_required' => $authRequired,
        'strict' => !isset($query['strict']) || (string) $query['strict'] !== '0',
        'three_n' => true,
        'max_items' => 200,
        'timestamp_unit' => 'epoch_milliseconds',
    ]);
    exit;
}

if (!$authOk) {
    header('WWW-Authenticate: Basic realm="SUMATRA VTS"');
    mockJson(401, latra_idd_response(false, 'Authentication required'));
    exit;
}

if ($path === '/_reset' && $method === 'POST') {
    if ($logPath !== '' && is_file($logPath)) {
        @unlink($logPath);
    }
    mockJson(200, ['ok' => true]);
    exit;
}

if (in_array($path, ['/_received', '/_received.csv'], true)) {
    if ($method !== 'GET') { mockJson(405, latra_idd_response(false, 'GET required')); exit; }
    $limit = max(1, min(200, (int) ($query['limit'] ?? 20)));
    $entries = [];
    $count = 0;
    $csv = $path === '/_received.csv';
    $output = $csv ? fopen('php://output', 'wb') : null;
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="latra-received.csv"');
        fputcsv($output, ['received_at','accepted','duplicate','vehicle_reg_no','imei',
            ...latra_idd_required_item_fields(), 'odometer','addon_info','fuel_info','errors'], ',', '"', '');
    }
    $handle = $logPath !== '' && is_file($logPath) ? fopen($logPath, 'rb') : false;
    if ($handle !== false) {
        flock($handle, LOCK_SH);
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) continue;
            $count++;
            if ($csv) {
                $items = $entry['payload']['items'] ?? [];
                if (!is_array($items) || $items === []) $items = [[]];
                foreach ($items as $item) {
                    if (!is_array($item)) $item = [];
                    $row = [$entry['at'], (int) $entry['accepted'], (int) $entry['duplicate'],
                        $entry['vehicle_reg_no'] ?? '', $entry['imei'] ?? ''];
                    foreach ([...latra_idd_required_item_fields(),'odometer','addon_info','fuel_info'] as $field) {
                        $value = $item[$field] ?? '';
                        $row[] = is_array($value) ? json_encode($value) : (is_scalar($value) ? (string) $value : '');
                    }
                    $row[] = implode('; ', $entry['errors']);
                    // Keep spreadsheet software from executing untrusted source text as a formula.
                    $row = array_map(static fn($v) => is_string($v) && !is_numeric($v) && preg_match('/^(?:[\t\r]|\s*[=+@-])/', $v)
                        ? "'" . $v : $v, $row);
                    fputcsv($output, $row, ',', '"', '');
                }
            } else {
                $entries[] = $entry;
                if (count($entries) > $limit) array_shift($entries);
            }
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    if ($csv) { fclose($output); exit; }
    mockJson(200, ['count' => $count, 'entries' => $entries]);
    exit;
}

if (!in_array($path, ['/data-integration/integration/gps', '/'], true)) {
    mockJson(404, latra_idd_response(false, 'Unknown route'));
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST');
    mockJson(405, latra_idd_response(false, 'POST required'));
    exit;
}

$mode = (string) ($query['mode'] ?? 'ok');
if ($mode === 'slow') {
    usleep(3_000_000);
} elseif ($mode === 'timeout') {
    sleep(30);
}

if ($mode === 'error500') {
    mockJson(500, latra_idd_response(false, 'Simulated server failure'));
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, MOCK_MAX_BODY_BYTES + 1);
if (strlen($raw) > MOCK_MAX_BODY_BYTES) {
    mockJson(413, latra_idd_response(false, 'Request body too large'));
    exit;
}

$payload = json_decode($raw, true);
$strict = !isset($query['strict']) || (string) $query['strict'] !== '0';
$validObject = str_starts_with(ltrim($raw), '{') && is_array($payload) && json_last_error() === JSON_ERROR_NONE;
$verdict = $validObject
    ? latra_idd_validate($payload, ['three_n' => $strict, 'strict_strings' => $strict])
    : ['errors' => ['Body must be a JSON object'], 'warnings' => []];
if ($mode === 'status0') $verdict['errors'][] = 'Simulated rejection';
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
try {
    $entry = mockLog($logPath, [
        'at' => gmdate('c'), 'remote' => $remote, 'mode' => $mode, 'strict' => $strict,
        'activity_ids' => array_values(array_map(static fn($item): string =>
            is_array($item) && is_scalar($item['activity_id'] ?? '') ? (string) ($item['activity_id'] ?? '') : '', $items)),
        'item_count' => count($items),
        'vehicle_reg_no' => is_string($payload['vehicle_reg_no'] ?? null) ? $payload['vehicle_reg_no'] : '',
        'imei' => is_string($payload['imei'] ?? null) ? $payload['imei'] : '',
        'errors' => $verdict['errors'], 'warnings' => $verdict['warnings'],
        'body_sha256' => hash('sha256', $raw), 'raw_body' => $raw, 'payload' => $payload,
    ], (string) ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''), $verdict['errors'] === []);
} catch (Throwable) {
    mockJson(503, latra_idd_response(false, 'Evidence storage unavailable; request not acknowledged'));
    exit;
}
if (!$entry['accepted']) {
    $http = !$validObject ? 400 : (isset($query['http']) ? (int) $query['http'] : 200);
    mockJson($http, latra_idd_response(false, implode('; ', $entry['errors'])));
    exit;
}
mockJson(200, latra_idd_response(true));
