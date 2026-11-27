<?php
require_once __DIR__.'/vts-fixtures.php';
/** The active application must route its ordinary entry points through the relay, with no legacy writes. */
if (PHP_SAPI !== 'cli') exit(1);
define('DELIVERY_ARCHITECTURE', 'relay');
const DATE_FORMAT = 'Y-m-d H:i:s';
const WIALON_BASE_URL = 'http://wialon.test/wialon/ajax.html';
const WIALON_TOKEN = 'synthetic';
class RelayRuntime {
    public static array $calls = [];
    public static array $queuedPayloads = [];
    public static function enabled(): bool { return true; }
    public static function queueForVehicle(array $payload, ?int $vehicleId = null): array {
        $vehicle = new \Latra\Domain\Collection\Vehicle(
            id: 1,
            vehicle_reg_no: 'SYNTHETIC',
            imei: '123456789012345',
        );
        if (($vehicleId !== null && $vehicleId !== $vehicle->id)
            || ($payload['vehicle_reg_no'] ?? null) !== $vehicle->vehicle_reg_no
            || ($payload['imei'] ?? null) !== $vehicle->imei) {
            throw new RuntimeException('Relay payload identity does not match the owning vehicle');
        }
        return self::queuePayload($vehicle, $payload);
    }
    public static function queuePayload(\Latra\Domain\Collection\Vehicle $vehicle, array $payload): array {
        if (($payload['vehicle_reg_no'] ?? null) !== $vehicle->vehicle_reg_no
            || ($payload['imei'] ?? null) !== $vehicle->imei) {
            throw new RuntimeException('Relay payload identity does not match enrolled vehicle');
        }
        $errors = LatraMapper::validatePayload($payload);
        self::$queuedPayloads[] = [$vehicle, $payload];
        return ['success'=>!$errors,'review'=>(bool)$errors,'queued'=>1];
    }
    public static function poll(\Latra\Domain\Collection\Vehicle $vehicle, int $window, int $limit): array {
        self::$calls[] = ['poll', $vehicle->id, $window, $limit];
        return ['inserted' => 2, 'rejected' => 0, 'skipped' => 0];
    }
    public static function bucket(array $bucket): array { self::$calls[] = ['bucket', $bucket['id']]; return ['pushed' => 0, 'inserted' => 2]; }
    public static function cycle(): array { self::$calls[] = ['cycle']; return ['vehicles' => 1, 'sent' => 0]; }
    public static function flush(int $limit, bool $emergency, int $seconds): int { self::$calls[] = ['flush', $limit, $emergency, $seconds]; return 2; }
}
require __DIR__ . '/../includes/FleetSync.php';
require __DIR__ . '/../includes/BucketRunner.php';
require __DIR__ . '/../includes/Scheduler.php';
require __DIR__ . '/../includes/EmergencyHandler.php';
function expect(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
class Database {
    public static function getInstance(): PDO {
        $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE vehicles (id INTEGER,wialon_unit_id INTEGER,name TEXT,fuel_level_sensor TEXT,vehicle_reg_no TEXT,imei TEXT,is_active INTEGER)');
        $db->exec("INSERT INTO vehicles VALUES (1,123,'Synthetic vehicle','fuel','SYNTHETIC','123456789012345',1)");
        return $db;
    }
    public static function fetchAll(string $sql, array $params = []): array {
        $vehicle = ['id'=>1,'wialon_unit_id'=>123,'name'=>'Synthetic vehicle','fuel_level_sensor'=>'fuel',
            'vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345'];
        if (!str_contains($sql, 'SELECT v.*')) {
            unset($vehicle['vehicle_reg_no'], $vehicle['imei']);
        }
        return [$vehicle];
    }
    public static function fetch(string $sql, array $params = []): ?array {
        return ['id'=>1,'wialon_unit_id'=>123,'name'=>'Synthetic vehicle',
            'fuel_level_sensor'=>'fuel','vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345'];
    }
}
class WialonAPI extends \Latra\Infrastructure\Wialon\WialonApiClient {
    public static bool $missingPosition = false;
    public static bool $failLogin = false;
    public static int $logouts = 0;
    public function __construct(...$args) {}
    public function login(): bool { return !self::$failLogin; }
    public function logout(): void { self::$logouts++; }
    public function getUnitData(int $unitId): array {
        $data = ['sens'=>[['t'=>'fuel','v'=>'50','u'=>'percent']]];
        if (!self::$missingPosition) {
            $data['pos'] = ['t'=>time(),'x'=>39.2,'y'=>-6.8,'z'=>10,'s'=>20,'c'=>90,'sc'=>8];
        }
        return $data;
    }
}
class Vault {
    public static function wialonUrl(): string { return ''; }
    public static function wialonToken(): string { return ''; }
}
require __DIR__ . '/../includes/LatraMapper.php';
class Logger {
    public static function info(...$args): void {}
    public static function warn(...$args): void {}
}
expect(FleetSync::pollVehicle(new \Latra\Domain\Collection\Vehicle(id: 1, wialon_unit_id: 123), 180, 80)['inserted'] === 2,
    'Scheduled polling bypassed the relay');
expect(BucketRunner::run(['id' => 4])['inserted'] === 2, 'Bucket entered retained-source pipeline');
expect(RelayRuntime::cycle()['vehicles'] === 1, 'Event engine entered retained-source pipeline');
$flush = new ReflectionMethod(Scheduler::class, 'jobFlush');
expect($flush->invoke(null, false) === 2 && $flush->invoke(null, true) === 2, 'Flush bypassed the relay');
expect(in_array(['flush', 80, false, 120], RelayRuntime::$calls, true)
    && in_array(['flush', 40, true, 120], RelayRuntime::$calls, true), 'Existing flush work/time budgets changed');
echo "PASS ordinary poll, bucket, event engine and flush enter relay with existing budgets\n";
foreach (['LatraAdapter', 'Delivery'] as $retired) {
    expect(!class_exists($retired), 'Retired direct-send class is still loadable: ' . $retired);
}
echo "PASS legacy direct-send transport classes are retired\n";
$fuelReport = new ReflectionMethod(Scheduler::class, 'jobFuelReport');
expect($fuelReport->invoke(null) === 0 && count(RelayRuntime::$queuedPayloads) === 1,
    'Incomplete fuel report must reach the queue for review without claiming it is sendable');
expect(RelayRuntime::$queuedPayloads[0][1]['vehicle_reg_no'] === 'SYNTHETIC'
    && RelayRuntime::$queuedPayloads[0][1]['imei'] === '123456789012345'
    && RelayRuntime::$queuedPayloads[0][1]['items'][0]['latitude'] === '-6.8'
    && RelayRuntime::$queuedPayloads[0][1]['items'][0]['longitude'] === '39.2',
    'Scheduled fuel report lost vehicle identity or live position before relay queueing');
echo "PASS scheduled fuel reports preserve vehicle identity and queue through relay\n";
WialonAPI::$missingPosition = true;
expect($fuelReport->invoke(null) === 0 && count(RelayRuntime::$queuedPayloads) === 2,
    'Fuel report without a live source position was not retained for review');
WialonAPI::$missingPosition = false;
$loginFailed = false;
WialonAPI::$failLogin = true;
try {
    $fuelReport->invoke(null);
} catch (RuntimeException $error) {
    $loginFailed = str_contains($error->getMessage(), 'Wialon login failed');
}
WialonAPI::$failLogin = false;
expect($loginFailed, 'Fuel-report Wialon login failure was not surfaced');
echo "PASS missing position is retained for review without fabricated data and Wialon login errors are surfaced\n";
foreach (['IngestPipeline', 'Outbox'] as $legacyAdapter) {
    expect(!is_file(__DIR__ . '/../includes/' . $legacyAdapter . '.php'),
        'Legacy direct-delivery adapter reintroduced: ' . $legacyAdapter);
}
echo "PASS relay mode has no direct retained-source write path\n";

$emergencyMismatch = EmergencyHandler::pushPayload([
    'vehicle_reg_no' => 'WRONG-PLATE',
    'imei' => '123456789012345',
    'items' => [['activity_id' => '8', 'timestamp' => (string) (time() * 1000), 'latitude' => '-6.8', 'longitude' => '39.2']],
], 1);
expect(!$emergencyMismatch['ok'] && str_contains($emergencyMismatch['response'], 'owning vehicle'),
    'Emergency queueing accepted a payload whose identity was not the owning vehicle');
$emergencyPayload = \Latra\Domain\Ingestion\LatraPayloadPolicy::buildPayload(
    new \Latra\Domain\Collection\Vehicle(vehicle_reg_no: 'SYNTHETIC', imei: '123456789012345'),
    \Latra\Domain\Ingestion\TelemetryMessage::fromStored(
        vtsTestMessage(['lat'=>-6.8,'lng'=>39.2,'wialon_msg_time'=>time()]),
    ),
    8,
)->toWire();
$emergencyOk = EmergencyHandler::pushPayload($emergencyPayload, 1);
expect($emergencyOk['ok'] === true && ($emergencyOk['queued'] ?? 0) === 1,
    'Emergency queueing rejected a valid payload for the owning vehicle');
echo "PASS emergency relay queueing enforces the vehicle owner before delivery\n";
