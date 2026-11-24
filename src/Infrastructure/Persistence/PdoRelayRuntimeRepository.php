<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Collection\RelayRuntimeRepository;
use Latra\Domain\Collection\RelaySettings;
use PDO;
use PDOStatement;

final class PdoRelayRuntimeRepository implements RelayRuntimeRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function activeVehiclesForBucket(int $bucketId): array
    {
        return $this->run(
            'SELECT v.* FROM vehicles v JOIN bucket_vehicles bv ON bv.vehicle_id=v.id
             WHERE bv.bucket_id=? AND v.is_active=1',
            [$bucketId],
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeVehiclesForEventCollection(): array
    {
        return $this->run(
            'SELECT DISTINCT v.* FROM vehicles v
             JOIN relay_vehicle_settings r ON r.vehicle_id=v.id AND r.is_enabled=1
             JOIN bucket_vehicles bv ON bv.vehicle_id=v.id
             JOIN automation_buckets b ON b.id=bv.bucket_id AND b.is_enabled=1
             WHERE v.is_active=1',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function enabledEventActivities(): array
    {
        return $this->enabledEventActivityIds();
    }

    public function relaySettings(int $vehicleId): ?RelaySettings
    {
        $row = $this->run(
            'SELECT * FROM relay_vehicle_settings WHERE vehicle_id=? AND is_enabled=1',
            [$vehicleId],
        )->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : RelaySettings::fromRow($row, $vehicleId);
    }

    public function relayStatus(string $unit): array
    {
        $wildcard = $unit . ':%';
        $streams = $this->run(
            'SELECT unit,collected_through,delivered_time,attempted_time FROM relay_units
             WHERE unit=? OR unit LIKE ? ORDER BY unit',
            [$unit, $wildcard],
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = $this->run(
            "SELECT COUNT(*) AS unresolved,COALESCE(SUM(status='review'),0) AS review,
                COALESCE(SUM(OCTET_LENGTH(payload)),0) AS bytes FROM relay_pending
             WHERE unit=? OR unit LIKE ?",
            [$unit, $wildcard],
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['streams' => $streams, 'queue' => array_map('intval', $counts)];
    }

    public function enabledEventActivityIds(): array
    {
        return array_map(
            'intval',
            array_column($this->run(
                'SELECT DISTINCT activity_id FROM wialon_event_map
                 WHERE activity_id<>1 AND is_enabled=1',
            )->fetchAll(PDO::FETCH_ASSOC), 'activity_id'),
        );
    }

    public function allowedBucketActivities(int $vehicleId): array
    {
        return array_map(
            'intval',
            array_column($this->run(
                'SELECT DISTINCT b.activity_id FROM automation_buckets b
                 JOIN bucket_vehicles bv ON bv.bucket_id=b.id
                 WHERE b.is_enabled=1 AND bv.vehicle_id=?',
                [$vehicleId],
            )->fetchAll(PDO::FETCH_ASSOC), 'activity_id'),
        );
    }

    public function pendingUnits(int $now, int $limit): array
    {
        return $this->run(
            "SELECT DISTINCT p.unit FROM relay_pending p
             WHERE p.status='pending' AND p.next_attempt<=? ORDER BY p.unit LIMIT " . max(1, $limit),
            [$now],
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeVehicleForUnit(int $unitId): ?array
    {
        $row = $this->run(
            'SELECT * FROM vehicles WHERE wialon_unit_id=? AND is_active=1',
            [$unitId],
        )->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function vehicle(int $vehicleId): ?array
    {
        $row = $this->run('SELECT * FROM vehicles WHERE id = ? LIMIT 1', [$vehicleId])->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function vehicleByIdentity(?string $vehicleRegNo, ?string $imei): ?array
    {
        $clauses = [];
        $parameters = [];
        if ($vehicleRegNo !== null) {
            $clauses[] = 'vehicle_reg_no = ?';
            $parameters[] = $vehicleRegNo;
        }
        if ($imei !== null) {
            $clauses[] = 'imei = ?';
            $parameters[] = $imei;
        }
        if ($clauses === []) return null;
        $row = $this->run(
            'SELECT * FROM vehicles WHERE ' . implode(' OR ', $clauses) . ' LIMIT 1',
            $parameters,
        )->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
