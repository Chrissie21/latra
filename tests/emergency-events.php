<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Emergency\BufferEmergencyRecord;
use Latra\Application\Emergency\CloseEndedEmergencyEvent;
use Latra\Application\Emergency\EmergencyActivityDetector;
use Latra\Application\Emergency\EmergencyEventClock;
use Latra\Application\Emergency\EmergencyEventEffects;
use Latra\Application\Emergency\EmergencyEventIdentifier;
use Latra\Application\Emergency\EmergencyEventRepository;
use Latra\Application\Emergency\EndEmergencyEvent;
use Latra\Application\Emergency\EnsureEmergencyEvent;
use Latra\Domain\Emergency\EmergencyEventPolicy;
use Latra\Infrastructure\Persistence\PdoEmergencyEventRepository;

function emergencyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class EmergencyTestClock implements EmergencyEventClock
{
    public string $now = '2026-11-16 10:00:00';

    public function now(): string
    {
        return $this->now;
    }
}

final class EmergencyTestIdentifier implements EmergencyEventIdentifier
{
    public array $calls = [];

    public function next(int $vehicleId): string
    {
        $this->calls[] = $vehicleId;
        return 'EMG-' . $vehicleId . '-ABCDEF1234';
    }
}

final class EmergencyTestEffects implements EmergencyEventEffects
{
    public array $started = [];
    public array $ended = [];

    public function started(array $event): void
    {
        $this->started[] = $event;
    }

    public function ended(array $event): void
    {
        $this->ended[] = $event;
    }
}

final class EmergencyTestRepository implements EmergencyEventRepository
{
    public array $events = [];
    public array $records = [];
    public array $increments = [];
    public array $ended = [];
    public bool $recordAccepted = true;
    private int $nextId = 1;

    public function seed(array $event): void
    {
        $this->events[(int) $event['id']] = $event;
        $this->nextId = max($this->nextId, (int) $event['id'] + 1);
    }

    public function activeByType(int $vehicleId, string $eventType): ?array
    {
        foreach ($this->events as $event) {
            if ((int) $event['vehicle_id'] === $vehicleId
                && $event['event_type'] === $eventType
                && $event['status'] === 'active') {
                return $event;
            }
        }
        return null;
    }

    public function activeForVehicle(int $vehicleId): ?array
    {
        foreach ($this->events as $event) {
            if ((int) $event['vehicle_id'] === $vehicleId && $event['status'] === 'active') {
                return $event;
            }
        }
        return null;
    }

    public function findById(int $eventId): ?array
    {
        return $this->events[$eventId] ?? null;
    }

    public function create(array $event): int
    {
        $id = $this->nextId++;
        $this->events[$id] = ['id' => $id, 'status' => 'active', 'record_count' => 0] + $event;
        return $id;
    }

    public function createRecord(array $record): bool
    {
        if (!$this->recordAccepted) {
            return false;
        }
        $this->records[] = $record;
        return true;
    }

    public function incrementRecordCount(int $eventId): void
    {
        $this->increments[] = $eventId;
    }

    public function end(int $eventId, string $endedAt): void
    {
        $this->ended[] = [$eventId, $endedAt];
        if (isset($this->events[$eventId])) {
            $this->events[$eventId]['status'] = 'ended';
            $this->events[$eventId]['ended_at'] = $endedAt;
        }
    }
}

final class EmergencyTestDetector implements EmergencyActivityDetector
{
    public array $seen = [];

    public function __construct(private bool $emergency)
    {
    }

    public function isEmergency(int $vehicleId, array $message): bool
    {
        $this->seen[] = [$vehicleId, $message];
        return $this->emergency;
    }
}

// ---------------------------------------------------------------------------
// Domain policy
// ---------------------------------------------------------------------------
emergencyAssert(EmergencyEventPolicy::typeForActivity(4) === 'overspeed', 'Speeding must map to overspeed');
emergencyAssert(EmergencyEventPolicy::typeForActivity(8) === 'panic', 'Driver panic must map to panic');
emergencyAssert(EmergencyEventPolicy::typeForActivity(13) === 'panic', 'Passenger panic must map to panic');
emergencyAssert(EmergencyEventPolicy::typeForActivity(12) === 'accident', 'Accident must map to accident');
emergencyAssert(EmergencyEventPolicy::typeForActivity(1) === null, 'Movement must not be an emergency');
emergencyAssert(EmergencyEventPolicy::typeForActivity(10) === null, 'Power disconnect must not be an emergency');
emergencyAssert(EmergencyEventPolicy::typeOrOther('accident') === 'accident', 'Known type must be retained');
emergencyAssert(EmergencyEventPolicy::typeOrOther('bogus') === 'other', 'Unknown type must fall back to other');
emergencyAssert(EmergencyEventPolicy::typeOrOther('') === 'other', 'Empty type must fall back to other');
emergencyAssert(EmergencyEventPolicy::defaultActivityFor('overspeed') === 4, 'Overspeed default activity changed');
emergencyAssert(EmergencyEventPolicy::defaultActivityFor('accident') === 12, 'Accident default activity changed');
emergencyAssert(EmergencyEventPolicy::defaultActivityFor('panic') === 8, 'Panic default activity changed');
emergencyAssert(EmergencyEventPolicy::defaultActivityFor('other') === 8, 'Other default activity changed');
emergencyAssert(EmergencyEventPolicy::activityFor(0, 'overspeed') === 4, 'Zero activity must fall back to default');
emergencyAssert(EmergencyEventPolicy::activityFor(12, 'panic') === 12, 'Explicit activity must be retained');
emergencyAssert(
    EmergencyEventPolicy::identifier(7, '00AbCd') === 'EMG-7-00ABCD',
    'Event identifier format changed',
);

// ---------------------------------------------------------------------------
// Ensure / buffer / close orchestration
// ---------------------------------------------------------------------------
$clock = new EmergencyTestClock();
$identifier = new EmergencyTestIdentifier();
$effects = new EmergencyTestEffects();
$repository = new EmergencyTestRepository();
$ensure = new EnsureEmergencyEvent($repository, $effects, $clock, $identifier);

$event = $ensure->ensure(9, 'panic', 'SOS button', 12.5, 8);
emergencyAssert($event !== null && (int) $event['id'] === 1, 'First emergency event was not created');
emergencyAssert($event['event_id'] === 'EMG-9-ABCDEF1234', 'Created event did not use the identifier port');
emergencyAssert($event['event_type'] === 'panic', 'Created event type changed');
emergencyAssert((int) $event['activity_id'] === 8, 'Created event activity changed');
emergencyAssert($event['status'] === 'active', 'Created event is not active');
emergencyAssert($event['started_at'] === '2026-11-16 10:00:00', 'Created event did not use the clock');
emergencyAssert($event['trigger_reason'] === 'SOS button', 'Trigger reason was not persisted');
emergencyAssert((float) $event['max_speed_kmh'] === 12.5, 'Trigger speed was not persisted');
emergencyAssert(count($effects->started) === 1 && $effects->ended === [], 'Start effect was not emitted once');
emergencyAssert($identifier->calls === [9], 'Identifier was used more than once');

$reused = $ensure->ensure(9, 'panic', 'second trigger', 20.0, 8);
emergencyAssert((int) $reused['id'] === 1, 'Active event of the same type was not reused');
emergencyAssert(count($effects->started) === 1, 'Reused event emitted a duplicate start effect');
emergencyAssert($identifier->calls === [9], 'Reused event generated a new identifier');

$other = $ensure->ensure(9, 'unexpected', null, null, 0);
emergencyAssert($other['event_type'] === 'other', 'Unknown event type was not normalised');
emergencyAssert((int) $other['activity_id'] === 8, 'Unknown event type did not use the default activity');

$accident = $ensure->ensure(9, 'accident', null, null, 12);
emergencyAssert((int) $accident['activity_id'] === 12, 'Explicit activity was not retained by the use case');

$bufferRepository = new EmergencyTestRepository();
$buffer = new BufferEmergencyRecord($bufferRepository);
$message = [
    't' => 1730000000,
    'pos' => ['x' => 39.200000001, 'y' => -6.800000001, 'z' => 50, 's' => 91.237, 'c' => 90, 'sc' => 12],
    'p' => ['hdop' => 1.4, 'pwr_ext' => 12.5],
];
$buffered = $buffer->buffer(['id' => 5, 'event_id' => 'EMG-9-ABCDEF1234'], 9, $message);
emergencyAssert($buffered === true, 'Emergency record was not buffered');
emergencyAssert(count($bufferRepository->records) === 1, 'Buffered record count changed');
$record = $bufferRepository->records[0];
emergencyAssert($record['event_id'] === 'EMG-9-ABCDEF1234', 'Buffered record event id changed');
emergencyAssert((int) $record['emergency_event_id'] === 5, 'Buffered record event reference changed');
emergencyAssert((int) $record['vehicle_id'] === 9, 'Buffered record vehicle changed');
emergencyAssert((int) $record['wialon_msg_time'] === 1730000000, 'Buffered record source time changed');
emergencyAssert((float) $record['lat'] === -6.80000000, 'Buffered latitude was not rounded to 8 places');
emergencyAssert((float) $record['lng'] === 39.20000000, 'Buffered longitude was not rounded to 8 places');
emergencyAssert((float) $record['speed'] === 91.24, 'Buffered speed was not rounded to 2 places');
emergencyAssert((int) $record['altitude'] === 50, 'Buffered altitude changed');
emergencyAssert((float) $record['hdop'] === 1.4, 'Buffered hdop changed');
emergencyAssert($record['latra_status'] === 'pending', 'Buffered record is not pending handoff');
emergencyAssert(json_decode($record['raw_data'], true) === $message, 'Buffered raw payload changed');
emergencyAssert($bufferRepository->increments === [5], 'Active event record counter was not incremented once');

$duplicateRepository = new EmergencyTestRepository();
$duplicateRepository->recordAccepted = false;
$duplicate = (new BufferEmergencyRecord($duplicateRepository))
    ->buffer(['id' => 6, 'event_id' => 'EMG-9-ABCDEF1234'], 9, $message);
emergencyAssert($duplicate === false, 'Duplicate emergency record must be reported as buffered=false');
emergencyAssert($duplicateRepository->increments === [], 'Duplicate record must not increment the counter');

// Closing: empty batch, no active event, still-emergency and ended.
$closeRepository = new EmergencyTestRepository();
$closeEffects = new EmergencyTestEffects();
$closeClock = new EmergencyTestClock();
$endUseCase = new EndEmergencyEvent($closeRepository, $closeEffects, $closeClock);
$detector = new EmergencyTestDetector(false);
$close = new CloseEndedEmergencyEvent($closeRepository, $detector, $endUseCase);
$close->close(9, []);
emergencyAssert($detector->seen === [], 'Empty message batch must not re-detect');
emergencyAssert($closeEffects->ended === [], 'Empty message batch must not end an event');

$close->close(9, [$message]);
emergencyAssert($closeEffects->ended === [] && $closeRepository->ended === [], 'Close ran without an active event');

$closeRepository->seed(['id' => 4, 'vehicle_id' => 9, 'event_type' => 'overspeed', 'status' => 'active']);
$stillEmergency = new EmergencyTestDetector(true);
(new CloseEndedEmergencyEvent($closeRepository, $stillEmergency, $endUseCase))->close(9, [$message]);
emergencyAssert(count($stillEmergency->seen) === 1, 'Close must inspect the fetched messages');
emergencyAssert($closeEffects->ended === [], 'An event still in emergency must not be closed');

$closeRepository->seed(['id' => 4, 'vehicle_id' => 9, 'event_type' => 'overspeed', 'status' => 'active']);
$ended = new EmergencyTestDetector(false);
(new CloseEndedEmergencyEvent($closeRepository, $ended, $endUseCase))->close(9, [$message]);
emergencyAssert($closeRepository->ended === [[4, '2026-11-16 10:00:00']], 'Ended event was not closed with the clock');
emergencyAssert(count($closeEffects->ended) === 1, 'Ended event did not emit the end effect');
emergencyAssert((int) $closeEffects->ended[0]['id'] === 4, 'End effect received the wrong event');

// ---------------------------------------------------------------------------
// SQLite-backed persistence and caller-owned transactions
// ---------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(
    "CREATE TABLE vehicles (id INTEGER PRIMARY KEY, vehicle_reg_no TEXT, imei TEXT);
     INSERT INTO vehicles (id, vehicle_reg_no, imei) VALUES (9, 'SYNTHETIC-EMERGENCY', '123456789012345');
     CREATE TABLE emergency_events (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id TEXT NOT NULL UNIQUE,
         vehicle_id INTEGER NOT NULL,
         event_type TEXT NOT NULL DEFAULT 'overspeed',
         activity_id INTEGER NULL,
         status TEXT NOT NULL DEFAULT 'active',
         started_at TEXT NOT NULL,
         ended_at TEXT NULL,
         trigger_reason TEXT NULL,
         max_speed_kmh REAL NULL,
         record_count INTEGER NOT NULL DEFAULT 0,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
     );
     CREATE TABLE emergency_queue (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id TEXT NOT NULL,
         emergency_event_id INTEGER NULL,
         vehicle_id INTEGER NOT NULL,
         wialon_msg_time INTEGER NOT NULL,
         lat REAL NULL,
         lng REAL NULL,
         altitude INTEGER NULL,
         speed REAL NULL,
         bearing REAL NULL,
         satellite_count INTEGER NULL,
         hdop REAL NULL,
         raw_data TEXT NULL,
         latra_status TEXT NOT NULL DEFAULT 'pending',
         latra_response TEXT NULL,
         submitted_at TEXT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP,
         UNIQUE (event_id, wialon_msg_time, vehicle_id)
     );"
);

$pdoRepository = new PdoEmergencyEventRepository($pdo);
$pdoEffects = new EmergencyTestEffects();
$pdoClock = new EmergencyTestClock();
$pdoIdentifier = new EmergencyTestIdentifier();
$pdoEnsure = new EnsureEmergencyEvent($pdoRepository, $pdoEffects, $pdoClock, $pdoIdentifier);
$pdoEvent = $pdoEnsure->ensure(9, 'overspeed', 'synthetic', 88.0, 4);
emergencyAssert((int) $pdoEvent['id'] === 1, 'SQLite event insert did not return the new id');
emergencyAssert($pdoEvent['event_id'] === 'EMG-9-ABCDEF1234', 'SQLite event identifier changed');
emergencyAssert((int) $pdoEvent['record_count'] === 0, 'SQLite event record count did not default to zero');
emergencyAssert($pdoRepository->activeByType(9, 'overspeed')['id'] === $pdoEvent['id'], 'Active event lookup failed');
emergencyAssert($pdoRepository->activeByType(9, 'panic') === null, 'Active lookup matched the wrong type');
$joined = $pdoRepository->activeForVehicle(9);
emergencyAssert($joined['vehicle_reg_no'] === 'SYNTHETIC-EMERGENCY', 'Active event join lost the vehicle identity');
emergencyAssert($joined['imei'] === '123456789012345', 'Active event join lost the vehicle imei');

emergencyAssert(
    $pdoRepository->createRecord([
        'event_id' => 'EMG-9-ABCDEF1234',
        'emergency_event_id' => 1,
        'vehicle_id' => 9,
        'wialon_msg_time' => 1730000000,
        'lat' => -6.8,
        'lng' => 39.2,
        'altitude' => 50,
        'speed' => 91.24,
        'bearing' => 90,
        'satellite_count' => 12,
        'hdop' => 1.4,
        'raw_data' => json_encode(['t' => 1730000000]),
        'latra_status' => 'pending',
    ]) === true,
    'First emergency record was not inserted',
);
emergencyAssert(
    $pdoRepository->createRecord([
        'event_id' => 'EMG-9-ABCDEF1234',
        'emergency_event_id' => 1,
        'vehicle_id' => 9,
        'wialon_msg_time' => 1730000000,
        'lat' => -6.8,
        'lng' => 39.2,
        'altitude' => 50,
        'speed' => 91.24,
        'bearing' => 90,
        'satellite_count' => 12,
        'hdop' => 1.4,
        'raw_data' => json_encode(['t' => 1730000000]),
        'latra_status' => 'pending',
    ]) === false,
    'Duplicate emergency record must be reported as not inserted',
);
$pdoRepository->incrementRecordCount(1);
$stored = $pdoRepository->findById(1);
emergencyAssert((int) $stored['record_count'] === 1, 'Emergency record counter did not increment');
$pdoRepository->end(1, '2026-11-16 11:00:00');
$closed = $pdoRepository->findById(1);
emergencyAssert($closed['status'] === 'ended', 'Emergency event was not ended');
emergencyAssert($closed['ended_at'] === '2026-11-16 11:00:00', 'Emergency event end time changed');

// A caller-owned transaction must own the writes: rollback leaves no event behind.
$pdo->beginTransaction();
$pdoRepository->create([
    'event_id' => 'EMG-9-ROLLBACK001',
    'vehicle_id' => 9,
    'event_type' => 'accident',
    'activity_id' => 12,
    'status' => 'active',
    'started_at' => '2026-11-16 12:00:00',
    'trigger_reason' => 'rollback probe',
    'max_speed_kmh' => null,
]);
$pdo->rollBack();
emergencyAssert(
    $pdoRepository->activeByType(9, 'accident') === null,
    'Repository writes escaped the caller-owned transaction',
);

// ---------------------------------------------------------------------------
// Legacy compatibility delegates remain wired to the extracted behavior
// ---------------------------------------------------------------------------
emergencyAssert(EmergencyHandler::mapEmergencyType(4) === 'overspeed', 'Legacy type mapping delegate changed');
emergencyAssert(EmergencyHandler::mapEmergencyType(12) === 'accident', 'Legacy accident mapping delegate changed');
emergencyAssert(EmergencyHandler::mapEmergencyType(1) === null, 'Legacy non-emergency mapping delegate changed');
emergencyAssert(
    EmergencyHandler::EMERGENCY_TYPES === EmergencyEventPolicy::TYPES,
    'Legacy emergency type list drifted from the domain policy',
);

echo "PASS emergency event policy, lifecycle use cases, buffering, dedupe and caller-owned persistence\n";
