<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Collection\FleetRepository;
use Latra\Application\Collection\FleetUnitSource;
use Latra\Application\Collection\SyncFleetVehicles;
use Latra\Domain\Collection\FleetSyncPolicy;
use Latra\Domain\Collection\Vehicle;
use Latra\Infrastructure\Persistence\VehicleRowDecoder;
use Latra\Infrastructure\Wialon\WialonSourceReader;

function fleetAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fleetRejects(callable $operation, string $needle): void
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

final class FakeFleetSource implements FleetUnitSource
{
    public bool $opens = true;
    public int $openCalls = 0;
    public int $closeCalls = 0;
    public array $units = [];
    public array $engines = [];
    public array $unitData = [];

    public function open(): bool
    {
        $this->openCalls++;
        return $this->opens;
    }

    public function units(): array
    {
        return $this->units;
    }

    public function unit(int $unitId): ?array
    {
        return $this->unitData[$unitId] ?? null;
    }

    public function engineSensorParam(int $unitId): ?string
    {
        return $this->engines[$unitId] ?? null;
    }

    public function close(): void
    {
        $this->closeCalls++;
    }
}

final class FakeFleetRepository implements FleetRepository
{
    public array $byUnit = [];
    public array $byId = [];
    public array $inserts = [];
    public array $updates = [];
    public array $mappings = [];
    private int $nextId = 100;

    public function findByUnitId(int $unitId): ?Vehicle
    {
        return $this->byUnit[$unitId] ?? null;
    }

    public function findById(int $vehicleId): ?Vehicle
    {
        return $this->byId[$vehicleId] ?? null;
    }

    public function insert(Vehicle $vehicle): int
    {
        $id = $this->nextId++;
        $this->inserts[$id] = new Vehicle(...[...(array) $vehicle, 'id' => $id]);
        return $id;
    }

    public function update(Vehicle $vehicle): void
    {
        $this->updates[$vehicle->id] = $vehicle;
    }

    public function seedActivityMappings(int $vehicleId): void
    {
        $this->mappings[] = $vehicleId;
    }
}

// ---------------------------------------------------------------------------
// Domain row policy
// ---------------------------------------------------------------------------
$unit = [
    'id' => 77,
    'nm' => 'T123ABC',
    'uid' => '',
    'pos' => ['t' => 1700000000, 'x' => 39.2, 'y' => -6.8, 's' => 42.5, 'c' => 90, 'sc' => 9],
    'lmsg' => ['t' => 1700000001, 'p' => ['hdop' => 1.25]],
];
$row = FleetSyncPolicy::vehicle((new WialonSourceReader())->decodeUnit($unit), '2026-11-16 10:00:00', 'ign');
fleetAssert($row->wialon_unit_id === 77, 'Mapped unit id changed');
fleetAssert($row->vehicle_reg_no === 'T123ABC', 'Registration extraction changed');
fleetAssert($row->imei === null, 'Blank imei was not normalised to null');
fleetAssert($row->last_sync_at === '2026-11-16 10:00:00', 'Sync timestamp changed');
fleetAssert($row->is_active === true, 'Synced vehicle is not active');
fleetAssert($row->engine_param === 'ign', 'Engine sensor param changed');
fleetAssert($row->lat === -6.8 && $row->lng === 39.2, 'Mapped position changed');

$withImei = FleetSyncPolicy::vehicle(
    (new WialonSourceReader())->decodeUnit(['id' => 78, 'nm' => 'Synthetic', 'uid' => '123456789012345']),
    'now',
    null,
);
fleetAssert($withImei->imei === '123456789012345', 'Present imei was discarded');
fleetAssert($withImei->engine_param === null, 'Absent engine sensor became a value');
fleetAssert($withImei->lat === null && $withImei->lng === null, 'Missing position fabricated coordinates');

// ---------------------------------------------------------------------------
// Full sync: insert new, update existing, seed mappings, close session
// ---------------------------------------------------------------------------
$source = new FakeFleetSource();
$source->units = [['id' => 77, 'nm' => 'T456DEF', 'uid' => '1'], ['id' => 88, 'nm' => 'New', 'uid' => '2']];
$source->engines = [77 => 'ign', 88 => null];
$repository = new FakeFleetRepository();
$repository->byUnit[77] = VehicleRowDecoder::decode([
    'id' => 5,
    'wialon_unit_id' => 77,
    'speed_limit_kmh' => 80,
    'fuel_cadence_min' => 15,
]);
$logs = [];
$clock = new class implements \Latra\Application\Collection\FleetSyncClock {
    public function now(): string
    {
        return '2026-11-16 11:00:00';
    }
};
$sync = new SyncFleetVehicles(
    $source,
    $repository,
    $clock,
    static function (int $new, int $updated) use (&$logs): void {
        $logs[] = [$new, $updated];
    },
    static fn(array $unit): Vehicle => (new WialonSourceReader())->decodeUnit($unit),
);
$stats = $sync->all();
fleetAssert($stats === ['synced' => 1, 'updated' => 1], 'Sync counters changed: ' . json_encode($stats));
fleetAssert($source->openCalls === 1 && $source->closeCalls === 1, 'Session was not opened and closed once');
fleetAssert(isset($repository->updates[5]), 'Existing vehicle was not updated');
fleetAssert($repository->updates[5]->vehicle_reg_no === 'T456DEF', 'Existing vehicle mapping changed');
fleetAssert($repository->updates[5]->imei === '1', 'Fleet refresh lost the source IMEI');
fleetAssert($repository->updates[5]->engine_param === 'ign', 'Existing engine param changed');
fleetAssert($repository->updates[5]->last_sync_at === '2026-11-16 11:00:00', 'Clock was not used');
fleetAssert($repository->updates[5]->speed_limit_kmh === 80.0 && $repository->updates[5]->fuel_cadence_min === 15,
    'Fleet sync erased an operator-owned setting');
fleetAssert(count($repository->inserts) === 1, 'New vehicle was not inserted');
$inserted = reset($repository->inserts);
fleetAssert($inserted->id === 100, 'Insert input was not assigned a local id by the repository');
fleetAssert($inserted->wialon_unit_id === 88 && $inserted->imei === '2', 'Inserted row changed');
fleetAssert($repository->mappings === [5, 100], 'Activity mappings were not seeded for every vehicle');

$source->opens = false;
fleetRejects(fn() => $sync->all(), 'Wialon login failed');
fleetAssert($source->closeCalls === 1, 'Failed login tried to close a session it never opened');

// ---------------------------------------------------------------------------
// One-vehicle sync
// ---------------------------------------------------------------------------
$source = new FakeFleetSource();
$source->unitData[123] = ['id' => 123, 'nm' => 'T789GHI', 'uid' => '123456789012345'];
$source->engines[123] = 'ign';
$repository = new FakeFleetRepository();
$repository->byId[4] = VehicleRowDecoder::decode(['id' => 4, 'wialon_unit_id' => 123]);
$sync = new SyncFleetVehicles($source, $repository, $clock, static function (): void {},
    static fn(array $unit): Vehicle => (new WialonSourceReader())->decodeUnit($unit));
$sync->one(4);
fleetAssert(isset($repository->updates[4]), 'Single sync did not update the vehicle');
fleetAssert($repository->updates[4]->vehicle_reg_no === 'T789GHI', 'Single sync mapping changed');
fleetAssert($repository->updates[4]->imei === '123456789012345', 'Single refresh lost the source IMEI');
fleetAssert($repository->updates[4]->engine_param === 'ign', 'Single sync engine param changed');
fleetAssert($source->openCalls === 1 && $source->closeCalls === 1, 'Single sync session lifecycle changed');
fleetAssert($repository->mappings === [], 'Single sync seeded activity mappings');

$repository->byId[4] = $repository->byId[4]->withSynced(new Vehicle(wialon_unit_id: 999));
$sync->one(4);
fleetAssert(!isset($repository->updates[999]) && count($repository->updates) === 1, 'Missing unit produced an update');

fleetRejects(fn() => $sync->one(999), 'Vehicle not found');

$source->opens = false;
fleetRejects(fn() => $sync->one(4), 'Wialon login failed');

// ---------------------------------------------------------------------------
// Legacy compatibility delegates point at the extracted use case
// ---------------------------------------------------------------------------
$method = new ReflectionMethod('FleetSync', 'syncAll');
fleetAssert($method->getDeclaringClass()->getName() === 'FleetSync', 'FleetSync entry point disappeared');
fleetAssert(method_exists('FleetSync', 'collectRelayWindow'), 'Relay window entry point disappeared');
fleetAssert(
    !preg_match('/SELECT .*vehicles|INSERT INTO activity_mappings/s', file_get_contents(__DIR__ . '/../includes/FleetSync.php')),
    'FleetSync still contains inline fleet-sync SQL',
);

echo "PASS fleet mapping policy, full/one-vehicle sync use case and legacy entry points\n";
