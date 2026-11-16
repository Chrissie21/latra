<?php
require_once 'config.php'; require_once 'includes/Database.php'; require_once 'includes/WialonAPI.php';
require_once 'includes/LatraMapper.php'; require_once 'includes/Auth.php'; require_once 'includes/Automation.php';
require_once 'includes/GpsValidator.php'; require_once 'includes/EventDetector.php'; require_once 'includes/EmergencyHandler.php'; require_once 'includes/DeviceMonitor.php'; require_once 'includes/DataCleaner.php';

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
if (!Auth::rateLimit('api:' . Auth::clientKey(), 300, 60)) {
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
    if (in_array($action, $pushActions, true) && !Auth::rateLimit('push:' . Auth::clientKey(), 500, 86400)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Daily push limit reached.']);
        exit;
    }
}

function operatorFetch(): \Latra\Application\Operations\FetchOperatorMessages {
    return new \Latra\Application\Operations\FetchOperatorMessages(
        new \Latra\Infrastructure\Persistence\DatabaseOperatorReadRepository(),RelayRuntime::enabled(),
        static fn(array $vehicle,int $window,int $limit): array => RelayRuntime::poll($vehicle,$window,$limit),
        static fn(array $vehicle,array $messages): array => IngestPipeline::importVehicle($vehicle,$messages),
        static fn(): \Latra\Application\Operations\RetainedMessageSource => new \Latra\Infrastructure\Wialon\WialonRetainedMessageSource(new WialonAPI()),
        static fn(): int => time(),
    );
}

function operatorEvidence(): \Latra\Application\Operations\ReadSubmissionEvidence {
    return new \Latra\Application\Operations\ReadSubmissionEvidence(
        new \Latra\Infrastructure\Persistence\DatabaseOperatorReadRepository(),
        static fn(array $vehicle,array $source,int $activity,array $addon,array $fuel): array => LatraMapper::buildPayload($vehicle,$source,$activity,$addon,$fuel),
    );
}

function extractWialonParams(array $message): array {
    return \Latra\Domain\Collection\OperatorPayloadPolicy::parameters($message);
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

function queueRelayPayload(array $vehicle, array $payload): ?array {
    if (!RelayRuntime::enabled()) {
        return null;
    }
    try {
        $result = RelayRuntime::queueForVehicle($payload, (int) $vehicle['id']);
        return [
            'success' => (bool) ($result['success'] ?? false),
            'queued' => (int) ($result['queued'] ?? 0),
            'message' => $result['message'] ?? 'Saved to the durable relay. Real LATRA delivery is blocked.',
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
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
            $vid = intval($_REQUEST['vehicle_id'] ?? 0);
            $aid = intval($_REQUEST['activity_id'] ?? 0);
            $enabled = intval($_REQUEST['enabled'] ?? 0);

            $exists = Database::fetch("SELECT id FROM activity_mappings WHERE vehicle_id = ? AND activity_id = ?", [$vid, $aid]);
            if ($exists) {
                Database::update('activity_mappings', ['is_enabled' => $enabled], 'id = ?', [$exists['id']]);
            } else {
                Database::insert('activity_mappings', [
                    'vehicle_id' => $vid,
                    'activity_id' => $aid,
                    'is_enabled' => $enabled
                ]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'toggle_vehicle':
            $id = intval($_REQUEST['id'] ?? 0);
            $status = intval($_REQUEST['status'] ?? 0);
            Database::update('vehicles', ['is_active' => $status], 'id = ?', [$id]);
            echo json_encode(['success' => true]);
            break;

        case 'push_single':
            $msgId = intval($_REQUEST['message_id'] ?? 0);
            $msg = Database::fetch("SELECT ml.*, v.vehicle_reg_no, v.imei, v.name, ml.id as msg_id FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?", [$msgId]);
            if (!$msg) throw new Exception('Message not found');
            $payload = LatraMapper::buildPayload($msg, $msg, $msg['activity_id'], 
                json_decode($msg['addon_info'] ?? '[]', true),
                json_decode($msg['fuel_info'] ?? '[]', true)
            );

            if (RelayRuntime::enabled()) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [(int) $msg['vehicle_id']]);
                if (!$vehicle) throw new Exception('Vehicle not found');
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }

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

            $msgId = intval($_POST['message_id'] ?? 0);
            $msg = Database::fetch("SELECT ml.*, v.vehicle_reg_no, v.imei, v.name FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?", [$msgId]);
            if (!$msg) throw new Exception('Message not found');

            $payload = json_decode($_POST['payload'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
                throw new Exception('Payload must contain an items array');
            }
            $validationErrors = LatraMapper::validatePayload($payload);
            if ($validationErrors) {
                throw new Exception('Invalid LATRA payload: ' . implode('; ', $validationErrors));
            }
            if (RelayRuntime::enabled()) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [(int) $msg['vehicle_id']]);
                if (!$vehicle) throw new Exception('Vehicle not found');
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }
            break;

        case 'activity_stats':
            echo json_encode(operatorEvidence()->activityStatistics());
            break;

        case 'test_wialon':
            $wialon = new WialonAPI();
            $ok = $wialon->login();
            if ($ok) $wialon->logout();
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Wialon connection OK' : 'Wialon login failed']);
            break;

        case 'test_latra':
            $url = Vault::latraUrl();
            if ($url === '') {
                echo json_encode(['success' => false, 'message' => 'LATRA_API_URL is not configured in .env']);
                break;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode(Vault::latraUser() . ':' . Vault::latraPass()),
                ],
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $start = microtime(true);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $latency = (int) round((microtime(true) - $start) * 1000);
            curl_close($ch);
            if ($httpCode > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Endpoint reachable (HTTP ' . $httpCode . ' in ' . $latency . ' ms). ' .
                        (in_array($httpCode, [401, 403], true) ? 'Basic-auth check: credentials ' . ($httpCode === 401 ? 'rejected' : 'insufficient'). '.' : 'No auth challenge at this path.'),
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Unreachable: ' . ($curlError ?: 'no response')]);
            }
            break;

        case 'generate_payload':
            echo json_encode((new \Latra\Application\Operations\BuildOperatorPayload(
                new \Latra\Infrastructure\Persistence\DatabaseOperatorReadRepository(),
                new \Latra\Infrastructure\Wialon\WialonLatestVehicleSource(static fn(): WialonAPI => new WialonAPI()),
                static fn(int $vehicle,int $at): array => LatraMapper::buildTripStats($vehicle,$at),static fn(): int => time(),
            ))->build((int)($_GET['vehicle_id'] ?? 0),(int)($_GET['activity_id'] ?? 0),(string)($_GET['mode'] ?? 'single')));
            break;

        case 'push_custom_payload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST request required');
            $vid = intval($_POST['vehicle_id'] ?? 0);
            $aid = intval($_POST['activity_id'] ?? 0);
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            $payloadJson = $_POST['payload'] ?? '';
            $payload = json_decode($payloadJson, true);
            if (!$payload) throw new Exception('Invalid JSON payload');
            $validationErrors = LatraMapper::validatePayload($payload);
            if ($validationErrors) {
                throw new Exception('Invalid LATRA payload: ' . implode('; ', $validationErrors));
            }
            if (RelayRuntime::enabled()) {
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }
            break;

        case 'delete_push':
            $id = intval($_REQUEST['id'] ?? 0);
            Database::query("DELETE FROM push_log WHERE id = ?", [$id]);
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
            $id = intval($_POST['id'] ?? 0);
            $log = Database::fetch("SELECT * FROM push_log WHERE id = ?", [$id]);
            if (!$log) throw new Exception('Log entry not found');
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [(int) $log['vehicle_id']]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            $payloadJson = $log['payload'];
            $payload = json_decode($payloadJson ?? '', true);
            if (!$payload) throw new Exception('Stored payload is invalid JSON. Use Edit to rebuild it.');
            $validationErrors = LatraMapper::validatePayload($payload);
            if ($validationErrors) {
                throw new Exception('Invalid LATRA payload: ' . implode('; ', $validationErrors) . '. Use Edit and Update JSON before retrying.');
            }
            if (RelayRuntime::enabled()) {
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }
            break;

        case 'bulk_fetch_messages':
            $vehicleIds=array_filter(array_map('intval',explode(',',$_GET['vehicle_ids'] ?? '')));
            if (!$vehicleIds) throw new Exception('No vehicle IDs provided');
            startSse();
            operatorFetch()->bulk($vehicleIds,static fn(array $event) => sendSse($event));
            break;

        case 'bulk_push_activity':
            // SSE streaming: pushes a single activity to LATRA for multiple vehicles
            $rawIds = $_GET['vehicle_ids'] ?? '';
            $vehicleIds = array_filter(array_map('intval', explode(',', $rawIds)));
            $aid = intval($_GET['activity_id'] ?? 0);
            if (empty($vehicleIds)) throw new Exception('No vehicle IDs provided');
            if (!$aid) throw new Exception('Activity ID required');
            startSse();

            $total = count($vehicleIds);
            $successCount = 0;
            $failCount = 0;
            sendSse(['type' => 'init', 'total' => $total, 'message' => 'Connected. Starting LATRA push...']);

            foreach ($vehicleIds as $i => $vid) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
                if (!$vehicle) {
                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => 'Vehicle #' . $vid, 'latra_status' => 'fail'
                    ]);
                    $failCount++;
                    continue;
                }

                try {
                    // Use latest message or fall back to vehicle data
                    $latest = Database::fetch("SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1", [$vid]);
                    $source = $latest ?: $vehicle;
                    $params = $latest ? extractWialonParams($latest) : [];
                    $addonCustom = [];
                    if ($aid === EventDetector::ENGINE_OFF) {
                        $addonCustom = LatraMapper::buildTripStats($vid, (int) ($source['wialon_msg_time'] ?? $latest['wialon_msg_time'] ?? time()));
                    }
                    $addonInfo = LatraMapper::buildAddonInfo($aid, $params, $addonCustom) ?? [];
                    $fuelInfo = $aid === 16 ? LatraMapper::buildFuelInfo($params) : [];
                    $payload = LatraMapper::buildPayload($vehicle, $source, $aid, $addonInfo, $fuelInfo);
                    if (RelayRuntime::enabled()) {
                        $result = queueRelayPayload($vehicle, $payload);
                        $latraStatus = ($result['success'] ?? false) ? 'queued' : 'fail';
                        sendSse([
                            'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                            'vehicle' => $vehicle['vehicle_reg_no'], 'latra_status' => $latraStatus,
                            'message' => $result['message'] ?? 'Saved to the durable relay. Real LATRA delivery is blocked.'
                        ]);
                        if ($latraStatus === 'queued') $successCount++; else $failCount++;
                        continue;
                    }
                } catch (Exception $ex) {
                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => $vehicle['vehicle_reg_no'], 'latra_status' => 'fail'
                    ]);
                    $failCount++;
                }
            }

            sendSse(['type' => 'done', 'success' => $successCount, 'failed' => $failCount]);
            break;

        case 'latra_interrogate':
            Auth::requirePermission('device.interrogate');
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                throw new Exception('vehicle_id is required');
            }
            $snapshot=(new \Latra\Application\Operations\ReadDeviceSnapshot(
                new \Latra\Infrastructure\Persistence\DatabaseOperatorReadRepository(),static fn(): int => time(),
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
            $lastRun = Database::fetch("SELECT MAX(started_at) AS s FROM automation_runs")['s'] ?? null;
            $enabledBuckets = (int) (Database::fetch("SELECT COUNT(*) AS c FROM automation_buckets WHERE is_enabled = 1")['c'] ?? 0);
            $backlog = (int) (Database::fetch("SELECT COUNT(*) AS c FROM message_log WHERE latra_status = 'pending'")['c'] ?? 0);
            $deadLetter = (int) (Database::fetch("SELECT COUNT(*) AS c FROM message_log WHERE latra_status IN ('manual','dead')")['c'] ?? 0);
            $lastTick = @file_get_contents(LOGS_DIR . '/last_tick.txt');
            echo json_encode([
                'ok' => true,
                'last_tick_at' => $lastTick,
                'last_run_at' => $lastRun,
                'enabled_buckets' => $enabledBuckets,
                'backlog' => $backlog,
                'dead_letter' => $deadLetter,
                'app_env' => APP_ENV,
                'app_version' => APP_VERSION,
            ]);
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
