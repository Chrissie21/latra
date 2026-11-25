<?php
require_once __DIR__ . "/autoload.php";
/**
 * Scheduler.
 */
class Scheduler {
    /** Compatibility entry points compose the scheduling use case on one database session. */
    public static function tickBuckets(): void { self::work()->buckets(); }
    public static function tickJobs(): void { self::work()->jobs(); }
    public static function runBucket(array $bucket): void { self::work()->bucket($bucket); }

    private static function work(): \Latra\Application\Scheduling\RunScheduledWork {
        $pdo = Database::getInstance();
        return new \Latra\Application\Scheduling\RunScheduledWork(
            new \Latra\Infrastructure\Persistence\PdoScheduleRepository($pdo),
            new \Latra\Infrastructure\Scheduling\MysqlScheduleLocks($pdo),
            new \Latra\Infrastructure\Scheduling\SystemScheduleClock(DATE_FORMAT),
            static fn(): bool => Schema::config('scheduler_enabled', '1') === '1',
            self::jobs()->run(...),
            static fn(array $bucket): array => BucketRunner::run($bucket),
            static function(string $event, array $context, bool $warning): void {
                if ($warning) Logger::warn($event, $context); else Logger::error($event, $context);
            },
            static fn(string $event, array $context) => Webhooks::emit($event, $context),
            static function(string $message): void { echo $message; },
        );
    }

    /** Registered scheduled jobs; key selection itself lives in Application. */
    private static function jobs(): \Latra\Application\Scheduling\ScheduledJobDispatch {
        return new \Latra\Application\Scheduling\ScheduledJobDispatch([
            'reconcile' => self::jobReconcile(...),
            'fleet_sync' => self::jobFleetSync(...),
            'fuel_report' => self::jobFuelReport(...),
            'poll_messages' => self::jobPoll(...),
            'emergency_poll' => self::jobEmergencyPoll(...),
            'flush_outbox' => static fn(): int => self::jobFlush(false),
            'offline_sweep' => self::jobOffline(...),
            'backup' => self::jobBackup(...),
            'retention' => self::jobRetention(...),
            'purge_delivered' => self::purgeDelivered(...),
            'audit_rollup' => self::jobRollup(...),
        ]);
    }

    /**
     * Crash recovery for the relay-only architecture.
     *
     * The legacy outbox is retained as history and is never produced or drained,
     * so recovery only clears locks left behind by an interrupted scheduler run.
     */
    private static function jobReconcile(): int {
        return (new \Latra\Infrastructure\Persistence\PdoMaintenanceRepository(Database::getInstance()))->reconcileJobLocks();
    }

    private static function jobFleetSync(): int {
        $r = FleetSync::syncAll();
        return $r['synced'] + $r['updated'];
    }

    private static function jobPoll(): int {
        return self::polling()->ordinary(
            Schema::configInt('poll_interval_seconds', \Latra\Domain\Scheduling\SchedulePolicy::pollIntervalSeconds())
        );
    }

    private static function jobEmergencyPoll(): int {
        return self::polling()->emergency();
    }

    private static function polling(): \Latra\Application\Ingestion\PollScheduledVehicles {
        return new \Latra\Application\Ingestion\PollScheduledVehicles(
            new \Latra\Infrastructure\Persistence\PdoScheduledVehicleRepository(Database::getInstance()),
            static fn(array $vehicle, int $window, int $limit): array => FleetSync::pollVehicle($vehicle, $window, $limit),
            static fn(string $event, array $context) => Logger::warn($event, $context),
            static fn(bool $emergency): int => self::jobFlush($emergency),
        );
    }

    /** Relay-only flush with the original work and time budgets. */
    private static function jobFlush(bool $emergencyOnly): int {
        return RelayRuntime::flush(
            \Latra\Domain\Collection\RelayRuntimePolicy::flushLimit($emergencyOnly),
            $emergencyOnly,
            \Latra\Domain\Collection\RelayRuntimePolicy::flushSeconds(),
        );
    }

    private static function jobFuelReport(): int {
        return (new \Latra\Application\Ingestion\SubmitFuelReports(
            new \Latra\Infrastructure\Persistence\PdoFuelReportRepository(Database::getInstance()),
            new \Latra\Infrastructure\Wialon\WialonFuelReportSource(static fn(): WialonAPI => FleetSync::connector()),
            static fn(array $vehicle, array $payload, ?array $evidence=null): array =>
                RelayRuntime::queuePayload($vehicle, $payload, $evidence),
            static function(string $event, array $context, bool $warning): void {
                if ($warning) Logger::warn($event,$context); else Logger::info($event,$context);
            },
            true,
        ))->run();
    }

    private static function jobOffline(): int {
        return (new \Latra\Application\Maintenance\RunOfflineSweep(
            static fn(): array => (new \Latra\Application\DeviceStatus\RefreshGpsHealth(
                new \Latra\Infrastructure\Persistence\PdoHealthRepository(Database::getInstance()),
                new \Latra\Infrastructure\Ingestion\SystemDeviceStatusClock(), static fn(): int => time()))->run(),
            static fn(): int => (new \Latra\Application\DeviceStatus\MarkOfflineDevices(
                new \Latra\Infrastructure\Persistence\PdoHealthRepository(Database::getInstance()),
                new \Latra\Infrastructure\Ingestion\AuditDeviceStatusEffects(),
                new \Latra\Infrastructure\Ingestion\SystemDeviceStatusClock(), static fn(): int => time()))->run(),
            static fn(string $type, array $payload) => Webhooks::emit($type, $payload),
        ))->run();
    }

    private static function jobBackup(): int {
        return (new \Latra\Application\Maintenance\CreateBackup(
            new \Latra\Infrastructure\Maintenance\ShellBackupProcess(dirname(__DIR__) . '/scripts/backup.sh'),
            static fn(string $type, array $payload) => Webhooks::emit($type, $payload),
        ))->run();
    }

    /** Archive in small transactions while honoring the original daily retention window. */
    private static function jobRetention(): int {
        return (new \Latra\Application\Maintenance\ArchiveHistoricalData(
            static fn(int $days): int => Retention::archiveMessages($days), static fn(): int => self::purgeDelivered(),
            (new \Latra\Infrastructure\Maintenance\FilesystemLogPruner(LOGS_DIR))->before(...),
            static fn(): int => time(),
        ))->run(Schema::configInt('retention_days', \Latra\Domain\Maintenance\RetentionPolicy::defaultDays()));
    }

    /** Honor the original hour-based purge policy and its existing scheduled job. */
    public static function purgeDelivered(): int {
        return Retention::purgeDelivered();
    }

    private static function jobRollup(): int {
        return (new \Latra\Infrastructure\Persistence\PdoMaintenanceRepository(Database::getInstance()))->rollupDaily();
    }

}
