<?php
/** Application cutover coordinator. Every active producer uses the same durable relay. */
use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Application\Collection\EnrollRelayVehicle;
use Latra\Application\Collection\CollectRelayBucket;
use Latra\Application\Collection\CollectRelayEventCycle;
use Latra\Domain\Collection\RelayDeliveryGatePolicy;
use Latra\Domain\Collection\RelayIdentityPolicy;
use Latra\Domain\Collection\RelayRuntimePolicy;
use Latra\Domain\Collection\RelaySettings;
use Latra\Domain\Delivery\RelayDeliveryTarget;
use Latra\Domain\Delivery\RelayDeliveryTransportPolicy;
use Latra\Domain\Ingestion\DeviceStatusPolicy;
use Latra\Infrastructure\Persistence\PdoIngestionSettingsRepository;
use Latra\Infrastructure\Persistence\PdoRelayEnrollmentRepository;
use Latra\Infrastructure\Persistence\PdoRelayRuntimeRepository;

final class RelayRuntime {
    public static function enabled(): bool {
        return defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay';
    }

    private static function repository(): PdoRelayRuntimeRepository {
        return new PdoRelayRuntimeRepository(Database::getInstance());
    }

    public static function settings(int $vehicleId): RelaySettings {
        $settings = self::settingsOrNull($vehicleId);
        if ($settings === null) throw new \Latra\Domain\Exception\NotEnrolled('Vehicle is not enrolled for relay collection');
        return $settings;
    }

    /** Enrolled settings, or null so iteration can skip instead of aborting the whole run. */
    public static function settingsOrNull(int $vehicleId): ?RelaySettings {
        // Budget validation happens in RelaySettings, so an invalid row cannot be read here.
        return self::repository()->relaySettings($vehicleId);
    }

    /** Activity ids enabled for a vehicle's automation buckets (CLI/manual collection scope). */
    public static function allowedActivities(int $vehicleId): array {
        return self::repository()->allowedBucketActivities($vehicleId);
    }

    private static function queue(RelaySettings $settings): DurableRelay {
        return new DurableRelay(Database::getInstance(), Schema::configInt('outbox_max_tries', 8),
            static fn(int $attempt): int => RelayRuntimePolicy::backoff($attempt),
            $settings->maxPendingRecords, $settings->maxPendingBytes);
    }

    /** Enrollment changes only relay settings, never activity mappings or schedules. */
    public static function enroll(array $vehicle, string $profile, array $budgets): void {
        (new EnrollRelayVehicle(new PdoRelayEnrollmentRepository(Database::getInstance())))
            ->enroll((int) $vehicle['id'], (int) $vehicle['wialon_unit_id'], $profile, $budgets);
    }

    /** Payload-free operator status includes each independent collection stream. */
    public static function status(array $vehicle): array {
        $settings = self::settings((int) $vehicle['id']);
        $status = self::repository()->relayStatus('wialon:' . $vehicle['wialon_unit_id']);
        return ['profile' => $settings->profile, 'streams' => $status['streams'], 'queue' => $status['queue']];
    }

    /** Operator-triggered recovery uses current identity and mapping policy without fetching source data. */
    public static function recoverReviews(array $vehicle): array {
        $settings = self::settings((int) $vehicle['id']);
        $vehicleUnit = 'wialon:' . $vehicle['wialon_unit_id'];
        $queue = self::queue($settings);
        $map = new \Latra\Application\Collection\MapRelayReview(
            $vehicleUnit,
            static fn(): array => self::repository()->enabledEventActivityIds(),
            static fn(): array => self::allowed((int) $vehicle['id']),
            static fn(array $source, array $allowed, bool $events): array =>
                self::mapper($vehicle, $settings, $allowed, $events)->map($source, []),
        );
        return $queue->recoverReviews($vehicleUnit, [$map, 'map']);
    }

    private static function allowed(int $vehicleId): array {
        return self::repository()->allowedBucketActivities($vehicleId);
    }

    /** Ingestion configuration (activity gates, speed limit) for payload mapping. */
    private static function ingestionSettings(): PdoIngestionSettingsRepository {
        return new PdoIngestionSettingsRepository(Database::getInstance());
    }

    private static function mapper(array $vehicle, RelaySettings $settings, array $allowed, bool $events): RelayPayloadMapper {
        $ingestion = self::ingestionSettings();
        return new RelayPayloadMapper($vehicle, $ingestion->enabledActivities((int) $vehicle['id']), $allowed,
            !empty($vehicle['speed_limit_kmh']) ? (float) $vehicle['speed_limit_kmh']
                : $ingestion->speedLimit((int) $vehicle['id']),
            Schema::configInt('overspeed_consecutive', RelayRuntimePolicy::OVERSPEED_CONSECUTIVE),
            Schema::configInt('battery_low_mv', DeviceStatusPolicy::BATTERY_LOW_MV), $settings->profile,
            $events ? max(0, Schema::configInt('event_engine_cooldown_seconds', RelayRuntimePolicy::EVENT_COOLDOWN_SECONDS)) : 0,
            ParamResolver::enabledAliases());
    }

    /** Independent stream cursors preserve bucket cadences while events use their own cadence. */
    public static function collect(array $vehicle, int $window, int $limit, string $stream, array $allowed,
        ?WialonAPI $source = null, ?int $explicitFrom = null, ?int $explicitTo = null): array {
        $settings = self::settingsOrNull((int) $vehicle['id']);
        if ($settings === null) {
            Logger::warn('relay.not_enrolled', ['vehicle' => (int) $vehicle['id']]);
            return ['fetched' => 0, 'queued' => 0, 'inserted' => 0, 'rejected' => 0, 'skipped' => 0];
        }
        $queue = self::queue($settings);
        $mapper = null;
        return (new \Latra\Application\Collection\CollectRelayStream(
            static fn(string $unit): ?array => $queue->status($unit)['progress'],
            static function (int $from, int $to, int $pageSize, int $maximum, int $maxBytes) use (
                $vehicle, $settings, $allowed, $source, $queue, $stream, &$mapper,
            ): array {
                $mapper ??= self::mapper($vehicle, $settings, $allowed, $stream === ':events');
                return FleetSync::collectRelayWindow(
                    $vehicle, $queue, $mapper, $from, $to, $pageSize, $maximum, $maxBytes, $source, $stream,
                );
            },
            static fn(): int => time(),
        ))->collect(
            'wialon:' . $vehicle['wialon_unit_id'], $stream, $window, $limit,
            $settings->maxRecords, $settings->maxBytes, $explicitFrom, $explicitTo,
        );
    }

    public static function poll(array $vehicle, int $window, int $limit): array {
        return self::collect($vehicle, $window, $limit, ':poll', self::allowed((int) $vehicle['id']));
    }

    public static function bucket(array $bucket, ?callable $sourceFactory = null): array {
        return (new CollectRelayBucket(
            new PdoRelayRuntimeRepository(Database::getInstance()),
            static fn(array $vehicle, int $window, int $limit, string $stream, array $allowed, ?WialonAPI $source): array =>
                self::collect($vehicle, $window, $limit, $stream, $allowed, $source),
            $sourceFactory ?? static fn(array $vehicle): null => null,
            static fn(string $channel, array $context): mixed => Logger::warn($channel, $context),
        ))->run($bucket);
    }

    public static function cycle(): array {
        $stats = ['vehicles' => 0, 'fetched' => 0, 'detected' => 0, 'sent' => 0, 'failed' => 0];
        if (Schema::config('scheduler_enabled', '1') !== '1' || Schema::config('event_engine_enabled', '1') !== '1') return $stats;
        return (new CollectRelayEventCycle(
            new PdoRelayRuntimeRepository(Database::getInstance()),
            static fn(array $vehicle, int $window, int $limit, string $stream, array $allowed): array =>
                self::collect($vehicle, $window, $limit, $stream, $allowed),
            static fn(string $channel, array $context): mixed => Logger::warn($channel, $context),
        ))->run(
            Schema::configInt('event_engine_window_seconds', 900),
            Schema::configInt('event_engine_limit', 100),
        );
    }

    /**
     * Composes the destination selected by RelayDeliveryTransportPolicy; the
     * safety rule (mock default, explicit latra mode, fail closed) lives in Domain.
     */
    private static function deliveryTransport(): ?\Closure {
        $selection = RelayDeliveryTransportPolicy::select(
            (string) Schema::config('relay_delivery_transport', RelayDeliveryTransportPolicy::MODE_MOCK),
            static fn(): string => (string) Schema::config('relay_mock_url', ''),
            static fn(): string => Vault::latraUrl(),
        );
        if ($selection->target === RelayDeliveryTarget::Disabled) return null;
        if ($selection->target === RelayDeliveryTarget::Mock) {
            $transport = new \Latra\Infrastructure\Delivery\LoopbackRelayTransport($selection->endpoint);
            return static fn(string $body, string $key): array => $transport($body, $key);
        }
        $transport = new \Latra\Infrastructure\Delivery\LatraRelayTransport(
            $selection->endpoint,
            Vault::latraUser(),
            Vault::latraPass(),
        );
        return static fn(string $body, string $key): array => $transport($body, $key);
    }

    public static function flush(int $limit, bool $emergencyOnly, int $seconds): int {
        $transport = self::deliveryTransport();
        if ($transport === null) return 0;
        $activeUnit = null;
        $activeVehicle = null;
        $findVehicle = static function (string $unit) use (&$activeUnit, &$activeVehicle): ?array {
            if ($activeUnit !== $unit) {
                $activeUnit = $unit;
                $unitVehicleId = RelayIdentityPolicy::unitVehicleId($unit);
                $activeVehicle = $unitVehicleId === null ? null
                    : self::repository()->activeVehicleForUnit($unitVehicleId);
            }
            return $activeVehicle;
        };
        $flush = new \Latra\Application\Delivery\FlushPendingRelayReports(
            static fn(int $now): array => self::repository()->pendingUnits($now, RelayRuntimePolicy::PENDING_UNITS_PER_CYCLE),
            static function (string $unit, int $now, callable $send, callable $canSend) use (
                $findVehicle,
                &$activeUnit,
                &$activeVehicle,
            ): string {
                try {
                    $vehicle = $findVehicle($unit);
                    if (!$vehicle) return 'empty';
                    $settings = self::settingsOrNull((int) $vehicle['id']);
                    if ($settings === null) return 'empty';
                    return self::queue($settings)->deliver(
                        $unit,
                        $now,
                        $send,
                        static fn(array $pending): bool => $canSend($unit, $pending),
                    );
                } finally {
                    $activeUnit = null;
                    $activeVehicle = null;
                }
            },
            static function (string $unit, array $pending) use ($findVehicle): bool {
                $vehicle = $findVehicle($unit);
                if (!$vehicle) return false;
                $enabled = self::ingestionSettings()->enabledActivities((int) $vehicle['id']);
                $allowed = self::allowed((int) $vehicle['id']);
                if (str_ends_with($unit, ':events') && Schema::config('event_engine_enabled', '1') === '1') {
                    $allowed = self::repository()->enabledEventActivityIds();
                }
                $activityIds = RelayDeliveryGatePolicy::activityIds($pending['payload']);
                return RelayDeliveryGatePolicy::allows($enabled, $allowed, $activityIds);
            },
            static fn(): int => Schema::configInt('latra_rate_per_minute', RelayRuntimePolicy::RATE_PER_MINUTE),
            static fn(string $body, string $key): array => $transport($body, $key),
        );
        return $flush->flush($limit, $emergencyOnly, $seconds);
    }

    /** Resolve the exact owning vehicle before any relay intent is enqueued. */
    public static function queueForVehicle(array $payload, ?int $vehicleId = null): array {
        $repository = self::repository();
        $vehicle = $vehicleId === null ? null : $repository->vehicle((int) $vehicleId);
        if (!$vehicle) {
            $vehicle = $repository->vehicleByIdentity(
                isset($payload['vehicle_reg_no']) ? (string) $payload['vehicle_reg_no'] : null,
                isset($payload['imei']) ? (string) $payload['imei'] : null,
            );
        }
        if (!$vehicle) {
            throw new \Latra\Domain\Exception\InvalidSubmission('Relay mode requires a known vehicle identity for queued payloads');
        }
        RelayIdentityPolicy::assertOwns($vehicle, $payload);
        return self::queuePayload($vehicle, $payload);
    }

    /** Preserve manual submission as durable intent, never a direct network bypass. */
    public static function queuePayload(array $vehicle, array $payload): array {
        $settings = self::settings((int) $vehicle['id']);
        return (new \Latra\Application\Collection\QueueManualRelayPayload(
            self::queue($settings), $settings->maxBytes,
        ))->queue($vehicle, $payload);
    }
}
