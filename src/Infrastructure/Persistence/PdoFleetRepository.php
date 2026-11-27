<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Collection\FleetRepository;
use Latra\Domain\Collection\Vehicle;
use PDO;
use RuntimeException;

final class PdoFleetRepository implements FleetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUnitId(int $unitId): ?Vehicle
    {
        $statement = $this->pdo->prepare('SELECT * FROM vehicles WHERE wialon_unit_id = ?');
        $statement->execute([$unitId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : VehicleRowDecoder::decode($row);
    }

    public function findById(int $vehicleId): ?Vehicle
    {
        $statement = $this->pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
        $statement->execute([$vehicleId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : VehicleRowDecoder::decode($row);
    }

    public function insert(Vehicle $vehicle): int
    {
        if ($vehicle->id !== null) {
            throw new RuntimeException('A new vehicle must not carry a local id');
        }
        // Absent creation settings must use the existing database defaults (e.g. cadence 30),
        // rather than explicitly replacing every omitted field with SQL NULL.
        $values = array_filter(VehicleRowDecoder::encode($vehicle), static fn($value): bool => $value !== null);
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(
            'INSERT INTO vehicles (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute(array_values($values));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(Vehicle $vehicle): void
    {
        if ($vehicle->id === null) {
            throw new RuntimeException('Missing local id for vehicle update');
        }
        $values = VehicleRowDecoder::encode($vehicle);
        $assignments = implode(', ', array_map(static fn(string $column): string => "`$column` = ?", array_keys($values)));
        $statement = $this->pdo->prepare("UPDATE vehicles SET $assignments WHERE id = ?");
        $statement->execute([...array_values($values), $vehicle->id]);
    }

    public function seedActivityMappings(int $vehicleId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO activity_mappings (vehicle_id, activity_id, is_enabled)
             SELECT ?, id, 0 FROM activity_definitions'
        );
        $statement->execute([$vehicleId]);
    }
}
