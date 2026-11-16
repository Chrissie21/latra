<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Collection\FleetRepository;
use PDO;

final class PdoFleetRepository implements FleetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUnitId(int $unitId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM vehicles WHERE wialon_unit_id = ?');
        $statement->execute([$unitId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findById(int $vehicleId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
        $statement->execute([$vehicleId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insert(array $vehicle): int
    {
        $columns = array_keys($vehicle);
        $statement = $this->pdo->prepare(
            'INSERT INTO vehicles (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute(array_values($vehicle));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $vehicleId, array $vehicle): void
    {
        $columns = array_keys($vehicle);
        $assignments = implode(', ', array_map(static fn(string $column): string => "`$column` = ?", $columns));
        $statement = $this->pdo->prepare("UPDATE vehicles SET $assignments WHERE id = ?");
        $statement->execute([...array_values($vehicle), $vehicleId]);
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
