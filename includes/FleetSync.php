<?php
/** Fleet sync. */
use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Application\Collection\SyncFleetVehicles;
use Latra\Infrastructure\Collection\SystemFleetSyncClock;
use Latra\Infrastructure\Persistence\PdoFleetRepository;
use Latra\Infrastructure\Wialon\WialonFleetUnitSource;

class FleetSync {
    public static function connector(): WialonAPI {
        return new WialonAPI(Vault::wialonUrl() ?: WIALON_BASE_URL, Vault::wialonToken() ?: WIALON_TOKEN);
    }

    public static function syncAll(): array {
        return self::sync()->all();
    }

    public static function syncOne(int $vehicleId): void {
        self::sync()->one($vehicleId);
    }

    /** Relay-only poll: bounded complete-window collection for one vehicle. */
    public static function pollVehicle(array $vehicle, int $windowSeconds = 180, int $limit = 50): array {
        return RelayRuntime::poll($vehicle, $windowSeconds, $limit);
    }

    /** Compatibility wrapper returning the queued count from a complete relay window. */
    public static function collectRelayVehicle(array $vehicle, DurableRelay $relay, RelayPayloadMapper $mapper,
        int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes, ?WialonAPI $wialon = null, string $stream = ''): int {
        return self::collectRelayWindow($vehicle, $relay, $mapper, $from, $to, $pageSize, $maxRecords, $maxBytes, $wialon, $stream)['queued'];
    }

    public static function collectRelayWindow(array $vehicle, DurableRelay $relay, RelayPayloadMapper $mapper,
        int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes, ?WialonAPI $wialon = null, string $stream = ''): array {
        $source = new \Latra\Infrastructure\Wialon\WialonMessageWindowSource($wialon ?? self::connector());
        $useCase = new \Latra\Application\Collection\CollectRelayWindow($source, $relay);
        return $useCase->collect(
            (int) $vehicle['wialon_unit_id'],
            'wialon:' . $vehicle['wialon_unit_id'] . $stream,
            $from,
            $to,
            $pageSize,
            $maxRecords,
            $maxBytes,
            [$mapper, 'map'],
        );
    }

    private static function sync(): SyncFleetVehicles {
        return new SyncFleetVehicles(
            new WialonFleetUnitSource(static fn(): WialonAPI => self::connector()),
            new PdoFleetRepository(Database::getInstance()),
            new SystemFleetSyncClock(),
            static function (int $new, int $updated): void {
                Logger::info('fleet.sync', ['new' => $new, 'updated' => $updated]);
            },
        );
    }
}
