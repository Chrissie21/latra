<?php
/** One validated vehicle crosses repository boundaries; missing identity stays missing. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Collection\RelayIdentityPolicy;
use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Exception\InvalidSubmission;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Infrastructure\Persistence\PdoFleetRepository;
use Latra\Infrastructure\Persistence\VehicleRowDecoder;

function boundaryExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function boundaryRejects(callable $operation, string $expected, string $class = RuntimeException::class): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        boundaryExpect($error instanceof $class && str_contains($error->getMessage(), $expected),
            'Unexpected failure: ' . get_class($error) . ': ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure containing: ' . $expected);
}

// Identity fields are separate facts; blank source values do not become zero or a fake registration.
$empty = VehicleRowDecoder::decode([
    'id' => '',
    'wialon_unit_id' => '',
    'vehicle_reg_no' => '',
    'imei' => '',
    'is_active' => 1,
]);
boundaryExpect($empty->id === null && $empty->wialon_unit_id === null
    && $empty->vehicle_reg_no === null && $empty->imei === null
    && $empty->is_active === true, 'Blank identity was coerced or invented');
boundaryRejects(static fn() => new Vehicle(id: -1), 'id must not be negative', InvalidArgumentException::class);
boundaryRejects(static fn() => new Vehicle(wialon_unit_id: -2), 'wialon_unit_id must not be negative', InvalidArgumentException::class);
boundaryRejects(static fn() => new Vehicle(lat: NAN), 'lat must be finite', InvalidArgumentException::class);

$owner = new Vehicle(id: 7, wialon_unit_id: 123, vehicle_reg_no: 'SYNTHETIC', imei: '123456789012345');
RelayIdentityPolicy::assertOwns($owner, ['vehicle_reg_no' => 'SYNTHETIC', 'imei' => null]);
RelayIdentityPolicy::assertOwns($owner, []);
boundaryRejects(
    static fn() => RelayIdentityPolicy::assertOwns($owner, ['vehicle_reg_no' => 'WRONG-PLATE']),
    'does not match the owning vehicle',
    InvalidSubmission::class,
);
boundaryRejects(
    static fn() => RelayIdentityPolicy::assertOwns(new Vehicle(id: 8), ['imei' => '123456789012345']),
    'does not match the owning vehicle',
    InvalidSubmission::class,
);

// Creation has no local id; persistence assigns one and decodes it as a separate fact.
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec('CREATE TABLE vehicles (
    id INTEGER PRIMARY KEY AUTOINCREMENT, wialon_unit_id INTEGER, vehicle_reg_no TEXT NOT NULL, imei TEXT,
    name TEXT, hw_type TEXT, phone TEXT, lat REAL, lng REAL, altitude INTEGER, speed REAL, bearing REAL,
    satellite_count INTEGER, hdop REAL, d2d3 INTEGER, rssi INTEGER, lac INTEGER, cell_id INTEGER,
    mcc INTEGER, mgs_id INTEGER, last_message_time INTEGER, last_sync_at TEXT, is_active INTEGER DEFAULT 1,
    speed_limit_kmh REAL, driver_id TEXT, odometer REAL, engine_param TEXT, fuel_level_sensor TEXT,
    fuel_cadence_min INTEGER DEFAULT 30
)');
$repository = new PdoFleetRepository($database);
$newVehicle = new Vehicle(
    wialon_unit_id: 321,
    vehicle_reg_no: 'SYNTHETIC',
    imei: '123456789012345',
    lat: -6.8,
    lng: 39.2,
    is_active: true,
    engine_param: 'ign',
    fuel_level_sensor: 'fuel',
);
boundaryRejects(static fn() => $repository->update($newVehicle), 'local id');
$id = $repository->insert($newVehicle);
$stored = $repository->findByUnitId(321);
boundaryExpect($stored !== null && $stored->id === $id, 'Insert did not round trip the assigned local id');
boundaryExpect($stored->wialon_unit_id === 321 && $stored->imei === '123456789012345'
    && $stored->fuel_level_sensor === 'fuel' && $stored->driver_id === null,
    'Insert decoded identity or optional fields incorrectly');

$operated = new Vehicle(
    id: $stored->id,
    wialon_unit_id: $stored->wialon_unit_id,
    vehicle_reg_no: 'UPDATED',
    imei: '123456789012345',
    lat: $stored->lat,
    lng: $stored->lng,
    is_active: true,
    speed_limit_kmh: 80,
    driver_id: 'driver-7',
    engine_param: 'ign',
    fuel_level_sensor: 'fuel',
    fuel_cadence_min: 15,
);
$repository->update($operated);
$reloaded = $repository->findById($id);
boundaryExpect($reloaded !== null && $reloaded->vehicle_reg_no === 'UPDATED'
    && $reloaded->speed_limit_kmh === 80.0 && $reloaded->driver_id === 'driver-7'
    && $reloaded->fuel_cadence_min === 15 && $reloaded->wialon_unit_id === 321,
    'Update lost operator settings or conflated vehicle identity');

// Omitted creation settings use schema defaults, while absent registration remains absent in Domain.
$unregisteredId = $repository->insert(new Vehicle(wialon_unit_id: 322));
$unregistered = $repository->findById($unregisteredId);
boundaryExpect($unregistered !== null && $unregistered->vehicle_reg_no === null
    && $unregistered->is_active === true && $unregistered->fuel_cadence_min === 30,
    'Insert rejected missing registration or replaced schema defaults with null');
$repository->update($unregistered->withSynced(new Vehicle(wialon_unit_id: 322, is_active: true)));
boundaryExpect($repository->findById($unregisteredId)?->vehicle_reg_no === null,
    'Refresh invented registration or failed to retain an unregistered vehicle');

// Incomplete required telemetry still builds a review candidate with real identity.
$incomplete = LatraPayloadPolicy::buildPayload(
    new Vehicle(vehicle_reg_no: 'SYNTHETIC', imei: '123456789012345'),
    \Latra\Domain\Ingestion\TelemetryMessage::fromStored(
        ['wialon_msg_time' => 1800000000, 'lng' => 39.2],
    ),
    1,
)->toWire();
$errors = LatraPayloadPolicy::validatePayload($incomplete);
boundaryExpect($incomplete['vehicle_reg_no'] === 'SYNTHETIC'
    && $incomplete['imei'] === '123456789012345'
    && in_array('items[0].latitude is required', $errors, true),
    'Missing required data was defaulted or required-review evidence was lost');

echo "PASS typed vehicle identities, insert/update round trip and required-review retention\n";
