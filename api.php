<?php
/**
 * HTTP entry point. Authentication, CSRF, JSON/SSE framing and audit stay here;
 * every action delegates to an application use case or an infrastructure adapter.
 */
require_once 'config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Mutating actions must be POST + carry a CSRF token. Read-only actions stay GET.
$writeActions = [
    'sync_wialon', 'sync_single_vehicle', 'fetch_messages', 'bulk_fetch_messages',
    'toggle_activity', 'toggle_vehicle', 'push_single', 'bulk_push_activity',
    'post_to_latra', 'push_custom_payload', 'delete_push', 'retry_push',
    'clear_data', 'latra_interrogate',
];
$sseActions = ['bulk_fetch_messages', 'bulk_push_activity'];
$pushActions = ['push_single', 'bulk_push_activity', 'post_to_latra', 'push_custom_payload', 'retry_push'];

// Authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Basic per-client rate limit (all api traffic).
if (!Auth::rateLimit(
    \Latra\Domain\Security\RateLimitPolicy::apiKey(Auth::clientKey()),
    \Latra\Domain\Security\RateLimitPolicy::API_PER_MINUTE,
    \Latra\Domain\Security\RateLimitPolicy::API_WINDOW_SECONDS,
)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Try again shortly.']);
    exit;
}

// SSE actions can only be GET (EventSource) — enforce CSRF via query token instead.
if (in_array($action, $sseActions, true)) {
    if (!Auth::verifyCsrf($_GET['_csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Write actions: require POST + CSRF, and cap downstream push volume per client/day.
if (in_array($action, $writeActions, true)) {
    // SSE actions are GET EventSource streams — CSRF is already enforced via the
    // query string above, so skip the POST + form-CSRF requirements for them.
    if (!in_array($action, $sseActions, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST required for this action']);
            exit;
        }
        Auth::requireCsrf();
    }
    // LATRA roles may only run the read-backed interrogation action below.
    // Everything else stays restricted to internal admin/operator roles.
    if ($action !== 'latra_interrogate') {
        Auth::requireWrite();
    }
    if (in_array($action, $pushActions, true) && !Auth::rateLimit(
        \Latra\Domain\Security\RateLimitPolicy::pushKey(Auth::clientKey()),
        \Latra\Domain\Security\RateLimitPolicy::PUSH_PER_DAY,
        \Latra\Domain\Security\RateLimitPolicy::PUSH_WINDOW_SECONDS,
    )) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Daily push limit reached.']);
        exit;
    }
}

/** Parse the existing EventSource CSV contract before starting an SSE response. */
function bulkVehicleIds(): array {
    $raw = $_GET['vehicle_ids'] ?? '';
    if (!is_string($raw)) {
        throw new InvalidArgumentException('vehicle_ids must be a comma-separated string');
    }
    $ids = array_filter(array_map('intval', explode(',', $raw)));
    if (!$ids) throw new InvalidArgumentException('No vehicle IDs provided');
    return $ids;
}

function operatorReadRepository(): \Latra\Application\Operations\OperatorReadRepository {
    return new \Latra\Infrastructure\Persistence\DatabaseOperatorReadRepository();
}

function operatorActionRepository(): \Latra\Application\Operations\OperatorActionRepository {
    return new \Latra\Infrastructure\Persistence\DatabaseOperatorActionRepository();
}

function operatorPayloadRepository(): \Latra\Application\Operations\OperatorPayloadRepository {
    return new \Latra\Infrastructure\Persistence\DatabaseOperatorReadRepository();
}

function operatorFetch(): \Latra\Application\Operations\FetchOperatorMessages {
    return new \Latra\Application\Operations\FetchOperatorMessages(
        operatorReadRepository(),
        static fn(array $vehicle,int $window,int $limit): array => RelayRuntime::poll($vehicle,$window,$limit),
    );
}

function operatorEvidence(): \Latra\Application\Operations\ReadSubmissionEvidence {
    return new \Latra\Application\Operations\ReadSubmissionEvidence(
        operatorReadRepository(),
        static fn(array $vehicle,array $source,int $activity,array $addon,array $fuel): array => LatraMapper::buildPayload($vehicle,$source,$activity,$addon,$fuel),
    );
}

function operatorSettings(): \Latra\Application\Operations\ManageOperatorSettings {
    return new \Latra\Application\Operations\ManageOperatorSettings(operatorActionRepository());
}

function operatorSubmit(): \Latra\Application\Operations\SubmitOperatorPayload {
    return new \Latra\Application\Operations\SubmitOperatorPayload(
        operatorActionRepository(),
        static fn(array $vehicle,array $source,int $activity,array $addon,array $fuel): array => LatraMapper::buildPayload($vehicle,$source,$activity,$addon,$fuel),
        static fn(array $payload): array => LatraMapper::validatePayload($payload),
        static fn(array $vehicle,array $payload): array => RelayRuntime::queueForVehicle($payload, (int) $vehicle['id']),
    );
}

function operatorBulkPush(): \Latra\Application\Operations\PushActivityToVehicles {
    return new \Latra\Application\Operations\PushActivityToVehicles(
        operatorActionRepository(),
        static fn(array $vehicle,array $source,int $activity,array $addon,array $fuel): array => LatraMapper::buildPayload($vehicle,$source,$activity,$addon,$fuel),
        static fn(int $activity,array $params,array $custom): ?array => LatraMapper::buildAddonInfo($activity,$params,$custom),
        static fn(array $params): array => LatraMapper::buildFuelInfo($params),
        static fn(int $vehicle,int $at): array => LatraMapper::buildTripStats($vehicle,$at),
        static fn(array $vehicle,array $payload): array => RelayRuntime::queueForVehicle($payload, (int) $vehicle['id']),
        static fn(): int => time(),
    );
}

function operatorHealth(): \Latra\Application\Operations\ReadSchedulerHealth {
    return new \Latra\Application\Operations\ReadSchedulerHealth(
        operatorActionRepository(),
        static fn(): string|false => @file_get_contents(LOGS_DIR . '/last_tick.txt'),
        APP_ENV, APP_VERSION,
    );
}

function startSse(): void {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_flush();
    echo ": connected\n\n";
    flush();
}

function sendSse(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

try {
    if (in_array($action, $pushActions, true) && !RelayRuntime::enabled()) {
        throw new RuntimeException('Direct LATRA delivery is disabled; use the durable relay.');
    }
    switch ($action) {

        case 'sync_wialon':
            $sync=FleetSync::syncAll();
            echo json_encode(['success'=>true,'message'=>"Synced: {$sync['synced']} new, {$sync['updated']} updated"]);
            break;

        case 'sync_single_vehicle':
            FleetSync::syncOne((int)($_REQUEST['id'] ?? 0));
            echo json_encode(['success'=>true,'message'=>'Vehicle data updated from Wialon']);
            break;

        case 'fetch_messages':
            echo json_encode(operatorFetch()->one((int)($_REQUEST['vehicle_id'] ?? 0)));
            break;

        case 'toggle_activity':
            operatorSettings()->setActivity(
                intval($_REQUEST['vehicle_id'] ?? 0),
                intval($_REQUEST['activity_id'] ?? 0),
                intval($_REQUEST['enabled'] ?? 0),
            );
            echo json_encode(['success' => true]);
            break;

        case 'toggle_vehicle':
            operatorSettings()->setVehicleActive(
                intval($_REQUEST['id'] ?? 0),
                intval($_REQUEST['status'] ?? 0),
            );
            echo json_encode(['success' => true]);
            break;

        case 'push_single':
            echo json_encode(operatorSubmit()->fromMessage(intval($_REQUEST['message_id'] ?? 0)));
            break;

        case 'get_response':
            echo json_encode(operatorEvidence()->response((int)($_GET['log_id'] ?? 0)));
            break;

        case 'get_payload':
            echo json_encode(operatorEvidence()->payload(isset($_GET['log_id']) ? (int)$_GET['log_id'] : null,(int)($_GET['message_id'] ?? 0)));
            break;

        case 'post_to_latra':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            echo json_encode(operatorSubmit()->fromMessagePayload(
                intval($_POST['message_id'] ?? 0),
                (string) ($_POST['payload'] ?? ''),
            ));
            break;

        case 'activity_stats':
            echo json_encode(operatorEvidence()->activityStatistics());
            break;

        case 'test_wialon':
            $ok = (new \Latra\Infrastructure\Wialon\WialonConnectionProbe(
                static fn(): WialonAPI => new WialonAPI(),
            ))->check();
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Wialon connection OK' : 'Wialon login failed']);
            break;

        case 'test_latra':
            echo json_encode((new \Latra\Infrastructure\Latra\LatraEndpointProbe(
                Vault::latraUrl(), Vault::latraUser(), Vault::latraPass(),
            ))->probe());
            break;

        case 'generate_payload':
            echo json_encode((new \Latra\Application\Operations\BuildOperatorPayload(
                operatorPayloadRepository(),
                new \Latra\Infrastructure\Wialon\WialonLatestVehicleSource(static fn(): WialonAPI => new WialonAPI()),
                static fn(int $vehicle,int $at): array => LatraMapper::buildTripStats($vehicle,$at),static fn(): int => time(),
            ))->build((int)($_GET['vehicle_id'] ?? 0),(int)($_GET['activity_id'] ?? 0),(string)($_GET['mode'] ?? 'single')));
            break;

        case 'push_custom_payload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST request required');
            echo json_encode(operatorSubmit()->fromCustomPayload(
                intval($_POST['vehicle_id'] ?? 0),
                (string) ($_POST['payload'] ?? ''),
            ));
            break;

        case 'delete_push':
            operatorSettings()->deletePushLog(intval($_REQUEST['id'] ?? 0));
            echo json_encode(['success' => true, 'message' => 'Log entry deleted successfully']);
            break;

        case 'clear_data':
            $category = trim((string) ($_POST['category'] ?? ''));
            $filters = [
                'vehicle' => trim((string) ($_POST['vehicle'] ?? '')),
                'imei' => trim((string) ($_POST['imei'] ?? '')),
                'activity' => trim((string) ($_POST['activity'] ?? '')),
                'status' => trim((string) ($_POST['status'] ?? '')),
                'event_type' => trim((string) ($_POST['event_type'] ?? '')),
                'event' => trim((string) ($_POST['event'] ?? '')),
                'response_status' => trim((string) ($_POST['response_status'] ?? '')),
                'from' => trim((string) ($_POST['from'] ?? '')),
                'to' => trim((string) ($_POST['to'] ?? '')),
                'older_than_days' => trim((string) ($_POST['older_than_days'] ?? '')),
            ];
            if ($category === 'reset_all') {
                $result = DataCleaner::resetAll();
                Auth::audit('data.reset_all', '', json_encode($result), 'admin');
                echo json_encode(['success' => true, 'message' => 'All generated data reset', 'result' => $result]);
                break;
            }
            $result = DataCleaner::clear($category, $filters);
            Auth::audit('data.clear', $category, json_encode(['filters' => $filters, 'result' => $result]), 'admin');
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $category)) . ' cleared', 'result' => $result]);
            break;

        case 'retry_push':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST request required');
            echo json_encode(operatorSubmit()->fromStoredPayload(intval($_POST['id'] ?? 0)));
            break;

        case 'bulk_fetch_messages':
            $vehicleIds = bulkVehicleIds();
            startSse();
            operatorFetch()->bulk($vehicleIds,static fn(array $event) => sendSse($event));
            break;

        case 'bulk_push_activity':
            $vehicleIds = bulkVehicleIds();
            $aid = intval($_GET['activity_id'] ?? 0);
            if (!$aid) throw new Exception('Activity ID required');
            startSse();
            operatorBulkPush()->run($vehicleIds, $aid, static fn(array $event) => sendSse($event));
            break;

        case 'latra_interrogate':
            Auth::requirePermission('device.interrogate');
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                throw new Exception('vehicle_id is required');
            }
            $snapshot=(new \Latra\Application\Operations\ReadDeviceSnapshot(
                operatorReadRepository(),static fn(): int => time(),
            ))->read($vehicleId);
            $vehicle=$snapshot['vehicle'];
            Auth::audit(
                'latra.interrogate',
                (string) $vehicle['vehicle_reg_no'],
                json_encode([
                    'request_id' => 'INT-' . strtoupper(substr(hash('sha256', microtime() . Auth::clientKey()), 0, 10)),
                    'vehicle_id' => (int) $vehicle['id'],
                    'imei' => $vehicle['imei'] ?? '',
                    'state' => $snapshot['stale'] ? 'stale' : 'current',
                ])
            );
            echo json_encode(['success' => true, 'snapshot' => $snapshot]);
            break;

        case 'scheduler_health':
            echo json_encode(operatorHealth()->read());
            break;

        default:
            throw new Exception('Unknown action: ' . $action);

    }

    if (in_array($action, $writeActions, true)) {
        $target = $_POST['target_label'] ?? $_POST['id'] ?? $_POST['message_id'] ?? $_POST['vehicle_id'] ?? $_POST['activity_id'] ?? '';
        Auth::audit('api.' . $action, (string) $target);
    }
} catch (\Latra\Domain\Exception\InvalidSubmission|\Latra\Domain\Exception\OwnershipLost|\Latra\Domain\Exception\CapacityExceeded|\Latra\Domain\Exception\SourceUnavailable|\Latra\Domain\Exception\NotEnrolled|\Latra\Domain\Exception\RequiresReview $e) {
    $failure = \Latra\Infrastructure\EntryPoint\WorkflowFailurePresenter::present($e);
    http_response_code($failure['status']);
    echo json_encode($failure['body']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
