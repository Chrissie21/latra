<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

/**
 * Idempotent schema bootstrap and seed data.
 *
 * Every statement is safe to repeat so an interrupted upgrade resumes instead of
 * failing; the compatibility `Schema` facade delegates here.
 */
class DatabaseSchema {
    public const VERSION = 9;

    public static function ensure(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        self::ensureAutomation();
        self::ensureEventSchema();
        \Auth::ensureTables();
        \Vault::ensureTable();

        self::addColumn('app_users', 'role VARCHAR(20) NOT NULL DEFAULT \'operator\'');
        self::addColumn('app_users', 'failed_logins INT NOT NULL DEFAULT 0');
        self::addColumn('app_users', 'locked_until DATETIME NULL');
        self::addColumn('app_users', 'password_changed_at DATETIME NULL');

        self::addColumn('vehicles', 'speed_limit_kmh DECIMAL(6,2) NULL');
        self::addColumn('vehicles', 'driver_id VARCHAR(32) NULL');
        self::addColumn('vehicles', 'odometer DECIMAL(12,2) NULL');
        self::addColumn('vehicles', 'engine_param VARCHAR(50) NULL');
        self::addColumn('vehicles', 'fuel_level_sensor VARCHAR(64) NULL');
        self::addColumn('vehicles', 'fuel_cadence_min INT UNSIGNED NULL DEFAULT 30');

        self::addColumn('message_log', 'driver_id VARCHAR(32) NULL');
        self::addColumn('message_log', 'odometer DECIMAL(12,2) NULL');
        self::addColumn('message_log', 'gps_time DATETIME NULL');
        self::addColumn('message_log', 'data_received_time DATETIME NULL');
        self::addColumn('message_log', 'data_processed_time DATETIME NULL');
        self::addColumn('message_log', 'event_id VARCHAR(40) NULL');
        self::addColumn('message_log', 'battery_pct DECIMAL(5,2) NULL');
        self::addColumn('message_log', 'battery_mv DECIMAL(10,2) NULL');
        self::addColumn('message_log', 'idempotency_key VARCHAR(64) NULL');

        self::addColumn('device_status', 'internal_battery_pct DECIMAL(5,2) NULL');
        self::addColumn('overspeed_config', 'consecutive_samples INT NOT NULL DEFAULT 2');

        self::addColumn('audit_log', 'before_json JSON NULL');
        self::addColumn('audit_log', 'after_json JSON NULL');

        \Database::query("CREATE TABLE IF NOT EXISTS param_aliases (
            id INT PRIMARY KEY AUTO_INCREMENT,
            device_type VARCHAR(80) NOT NULL DEFAULT '*',
            param_key VARCHAR(80) NOT NULL,
            canonical_key VARCHAR(80) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uk_alias (device_type, param_key, canonical_key)
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS outbox (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            message_id BIGINT NOT NULL,
            vehicle_id INT NOT NULL,
            activity_id INT NOT NULL DEFAULT 1,
            event_id VARCHAR(40) NULL,
            idempotency_key VARCHAR(64) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            tries INT NOT NULL DEFAULT 0,
            max_tries INT NOT NULL DEFAULT 8,
            next_attempt_at DATETIME NULL,
            in_flight_at DATETIME NULL,
            last_error TEXT NULL,
            failure_class VARCHAR(20) NULL,
            priority TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_outbox_idem (idempotency_key),
            INDEX idx_outbox_due (status, next_attempt_at, priority),
            INDEX idx_outbox_vehicle (vehicle_id),
            INDEX idx_outbox_msg (message_id)
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS jobs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            job_key VARCHAR(60) NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            interval_seconds INT NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_run_at DATETIME NULL,
            next_run_at DATETIME NULL,
            locked_until DATETIME NULL,
            last_status VARCHAR(20) NULL,
            last_error TEXT NULL,
            run_count INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS job_runs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            job_key VARCHAR(60) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            duration_ms INT NULL,
            items_processed INT NOT NULL DEFAULT 0,
            error TEXT NULL,
            INDEX idx_jobruns (job_key, started_at)
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS device_documents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            device_type VARCHAR(80) NULL,
            protocol VARCHAR(80) NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NULL,
            uploaded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS webhook_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_type VARCHAR(60) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            tries INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wh_status (status)
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS kpi_daily (
            day DATE PRIMARY KEY,
            vehicles INT NOT NULL DEFAULT 0,
            messages INT NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            rejected INT NOT NULL DEFAULT 0,
            emergencies INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");

        try {
            \Database::query("CREATE TABLE IF NOT EXISTS message_log_archive LIKE message_log");
        } catch (\PDOException $e) {
            // archive is optional if FK copy is unsupported
        }
        try {
            \Database::query("CREATE TABLE IF NOT EXISTS push_log_archive LIKE push_log");
        } catch (\PDOException $e) {
        }

        self::seedJobs();
        self::seedAliases();
        self::seedConfig();

        \Database::query(
            "INSERT INTO config (config_key, config_value, description) VALUES ('schema_version', ?, 'Platform schema version')
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)",
            [(string) self::VERSION]
        );
    }

    private static function seedJobs(): void {
        $jobs = [
            ['fleet_sync', 'Wialon fleet sync', 86400],
            ['poll_messages', 'Poll GPS messages', 180],
            ['emergency_poll', 'Emergency 1 Hz poll', 1],
            ['flush_outbox', 'Flush outbox to LATRA', 30],
            ['fuel_report', 'Fuel data report', 1800],
            ['offline_sweep', 'Device offline sweep', 300],
            ['backup', 'Encrypted DB backup', 86400],
            ['retention', 'Log rotation / archive', 86400],
            ['purge_delivered', 'Purge LATRA-confirmed rows', 3600],
            ['audit_rollup', 'Daily KPI rollup', 86400],
            ['reconcile', 'Crash recovery', 60],
        ];
        foreach ($jobs as [$key, $label, $interval]) {
            \Database::query(
                "INSERT IGNORE INTO jobs (job_key, label, interval_seconds, next_run_at) VALUES (?, ?, ?, NOW())",
                [$key, $label, $interval]
            );
        }
    }

    private static function seedAliases(): void {
        $aliases = [
            ['*', 'pwr_int', 'battery_mv'],
            ['*', 'io_67', 'battery_mv'],
            ['*', 'battery_charge', 'battery_mv'],
            ['*', 'int_battery', 'battery_mv'],
            ['*', 'battery', 'battery_pct'],
            ['*', 'battery_pct', 'battery_pct'],
            ['*', 'pwr_ext', 'ext_power_mv'],
            ['*', 'ext_power', 'ext_power_mv'],
            ['*', 'io_66', 'ext_power_mv'],
            ['*', 'gsm', 'rssi'],
            ['*', 'gsm_lac', 'lac'],
            ['*', 'lbs_lac', 'lac'],
            ['*', 'cellid', 'cell_id'],
            ['*', 'cid', 'cell_id'],
            ['*', 'gsm_cellid', 'cell_id'],
            ['*', 'gsm_cell_id', 'cell_id'],
            ['*', 'lbs_cell_id', 'cell_id'],
            ['*', 'mileage', 'odometer'],
            ['*', 'mil', 'odometer'],
            ['*', 'odometer', 'odometer'],
            ['*', 'pass', 'driver_id'],
            ['*', 'tco_driver1_id', 'driver_id'],
            ['*', 'driver_id', 'driver_id'],
        ];
        foreach ($aliases as [$type, $from, $to]) {
            \Database::query(
                "INSERT IGNORE INTO param_aliases (device_type, param_key, canonical_key) VALUES (?, ?, ?)",
                [$type, $from, $to]
            );
        }
    }

    private static function seedConfig(): void {
        $defaults = [
            ['poll_interval_seconds', '180', 'Normal GPS poll cadence'],
            ['emergency_interval_seconds', '1', 'Emergency poll cadence'],
            ['offline_seconds', '900', 'Mark device offline after'],
            ['latra_rate_per_minute', '300', 'Outbound LATRA cap per minute'],
            ['latra_daily_cap', '500', 'Soft daily cap alert threshold (per vehicle)'],
            ['default_speed_limit_kmh', '80', 'Default speed limit'],
            ['overspeed_consecutive', '2', 'Samples above limit before event'],
            ['battery_low_pct', '20', 'Low battery percent threshold'],
            ['battery_low_mv', '3200', 'Low battery millivolt threshold'],
            ['outbox_max_tries', '8', 'Max outbox retries'],
            ['retention_days', '90', 'Keep hot message_log this many days'],
            ['delivered_retention_hours', '24', 'Delete rows LATRA has confirmed after this many hours'],
            ['webhook_url', '', 'Operator alert webhook'],
            ['latra_ip_allowlist', '', 'Comma-separated IPs for LATRA-facing API'],
            ['scheduler_enabled', '1', 'Master scheduler switch'],
            ['gps_max_delay_seconds', '300', 'Compliance: max acceptable GPS delivery delay'],
            ['gps_min_frequency_seconds', '120', 'Compliance: min expected GPS reporting frequency'],
        ];
        foreach ($defaults as [$k, $v, $d]) {
            \Database::query(
                "INSERT IGNORE INTO config (config_key, config_value, description) VALUES (?, ?, ?)",
                [$k, $v, $d]
            );
        }
    }

    public static function config(string $key, string $default = ''): string {
        $row = \Database::fetch("SELECT config_value FROM config WHERE config_key = ?", [$key]);
        return $row ? (string) $row['config_value'] : $default;
    }

    public static function configInt(string $key, int $default = 0): int {
        $v = self::config($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private static function addColumn(string $table, string $definition): void {
        try {
            \Database::query("ALTER TABLE `$table` ADD COLUMN $definition");
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }

    /** Automation, device-status, event and emergency tables; safe to repeat. */
    public static function ensureAutomation(): void
    {
        \Database::query("CREATE TABLE IF NOT EXISTS automation_buckets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            bucket_name VARCHAR(100) NOT NULL,
            activity_id INT NOT NULL,
            interval_seconds INT NOT NULL DEFAULT 300,
            cooldown_seconds INT NOT NULL DEFAULT 30,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            max_items_per_run INT NOT NULL DEFAULT 100,
            max_run_seconds INT NOT NULL DEFAULT 300,
            claim_lease_seconds INT NOT NULL DEFAULT 120,
            run_on_demand TINYINT(1) NOT NULL DEFAULT 0,
            consecutive_failures INT NOT NULL DEFAULT 0,
            last_run_at TIMESTAMP NULL,
            next_run_at TIMESTAMP NULL,
            run_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bucket_due (is_enabled, next_run_at),
            FOREIGN KEY (activity_id) REFERENCES activity_definitions(id)
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS device_status (
            vehicle_id INT PRIMARY KEY,
            is_online TINYINT(1) NOT NULL DEFAULT 0,
            online_at DATETIME NULL,
            last_comm_time BIGINT NULL,
            last_comm_at DATETIME NULL,
            last_gps_at BIGINT NULL,
            internal_battery_low TINYINT(1) NOT NULL DEFAULT 0,
            internal_battery_mv DECIMAL(8,2) NULL,
            external_power_disconnected TINYINT(1) NOT NULL DEFAULT 0,
            external_power_mv DECIMAL(8,2) NULL,
            tampered TINYINT(1) NOT NULL DEFAULT 0,
            status_changed_at DATETIME NULL,
            last_status TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_devstatus_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            INDEX idx_devstatus_online (is_online),
            INDEX idx_devstatus_bat (internal_battery_low),
            INDEX idx_devstatus_pwr (external_power_disconnected),
            INDEX idx_devstatus_tamper (tampered)
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS bucket_vehicles (
            bucket_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            PRIMARY KEY (bucket_id, vehicle_id),
            FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS automation_runs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            bucket_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            triggered_by VARCHAR(10) NOT NULL DEFAULT 'schedule',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            duration_seconds INT NOT NULL DEFAULT 0,
            total_count INT NOT NULL DEFAULT 0,
            success_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            manual_count INT NOT NULL DEFAULT 0,
            skipped_count INT NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            INDEX idx_run_started (started_at),
            FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS automation_run_items (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            run_id BIGINT NOT NULL,
            bucket_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            activity_id INT NOT NULL,
            message_log_id BIGINT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_retry_at DATETIME NULL,
            claim_id VARCHAR(40) NULL,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
            failure_class VARCHAR(20) NULL,
            last_error TEXT DEFAULT NULL,
            completed_at DATETIME NULL,
            INDEX idx_item_status (status),
            INDEX idx_item_retry (status, next_retry_at),
            FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // LATRA compliance: validation error log.
        \Database::query("CREATE TABLE IF NOT EXISTS validation_errors (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            message_log_id BIGINT NULL,
            vehicle_id INT NULL,
            vehicle_reg_no VARCHAR(50) NULL,
            imei VARCHAR(20) NULL,
            field VARCHAR(64) NOT NULL,
            invalid_value TEXT NULL,
            reason VARCHAR(255) NOT NULL,
            error_code VARCHAR(64) NULL,
            activity_id INT NULL,
            validation_status VARCHAR(20) NOT NULL DEFAULT 'failed',
            gps_timestamp BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_veh (vehicle_id),
            INDEX idx_imei (imei),
            INDEX idx_status (validation_status),
            INDEX idx_created (created_at),
            INDEX idx_message (message_log_id),
            CONSTRAINT fk_valerr_msg FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL,
            CONSTRAINT fk_valerr_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // LATRA compliance: event detection configuration and state.
        \Database::query("CREATE TABLE IF NOT EXISTS overspeed_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id INT NULL,
            speed_limit_kmh DECIMAL(6,2) NOT NULL DEFAULT 80.00,
            duration_seconds INT NOT NULL DEFAULT 5,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_overspeed_vehicle (vehicle_id),
            CONSTRAINT fk_overspeed_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS geofence_definitions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            type ENUM('boundary','checkpoint') NOT NULL DEFAULT 'boundary',
            shape ENUM('circle','polygon') NOT NULL DEFAULT 'circle',
            latitude DECIMAL(10,8) NULL,
            longitude DECIMAL(11,8) NULL,
            radius_meters DECIMAL(12,3) NULL,
            polygon_geo JSON NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_geotype (type),
            INDEX idx_enabled (is_enabled)
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS vehicle_state (
            vehicle_id INT PRIMARY KEY,
            engine_status VARCHAR(20) DEFAULT NULL,
            last_speed_kmh DECIMAL(8,2) DEFAULT NULL,
            last_lat DECIMAL(10,8) DEFAULT NULL,
            last_lng DECIMAL(11,8) DEFAULT NULL,
            last_geofence_id INT NULL,
            last_geofence_inside TINYINT(1) NOT NULL DEFAULT 0,
            last_checkpoint_id INT NULL,
            overspending_active TINYINT(1) NOT NULL DEFAULT 0,
            last_event_activity_id INT NULL,
            last_event_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_vstate_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS gps_health (
            vehicle_id INT PRIMARY KEY,
            last_gps_time BIGINT NULL,
            last_gps_at DATETIME NULL,
            last_comm_time BIGINT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            minutes_since INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_gpshealth_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            INDEX idx_gps_status (status),
            INDEX idx_gps_time (last_gps_time)
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS emergency_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_id VARCHAR(40) NOT NULL,
            vehicle_id INT NOT NULL,
            event_type VARCHAR(30) NOT NULL DEFAULT 'overspeed',
            activity_id INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            trigger_reason TEXT NULL,
            max_speed_kmh DECIMAL(8,2) NULL,
            record_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emergency_event (event_id),
            INDEX idx_emergency_vehicle (vehicle_id),
            INDEX idx_emergency_status (status),
            INDEX idx_emergency_active (vehicle_id, status),
            CONSTRAINT fk_emergency_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        \Database::query("CREATE TABLE IF NOT EXISTS emergency_queue (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_id VARCHAR(40) NOT NULL,
            emergency_event_id BIGINT NULL,
            vehicle_id INT NOT NULL,
            wialon_msg_time BIGINT NOT NULL,
            lat DECIMAL(10,8) NULL,
            lng DECIMAL(11,8) NULL,
            altitude INT NULL,
            speed DECIMAL(8,2) NULL,
            bearing DECIMAL(6,2) NULL,
            satellite_count INT NULL,
            hdop DECIMAL(6,2) NULL,
            raw_data JSON NULL,
            latra_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            latra_response TEXT NULL,
            submitted_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emergency_msg (event_id, wialon_msg_time, vehicle_id),
            INDEX idx_emq_status (latra_status),
            INDEX idx_emq_vehicle (vehicle_id),
            INDEX idx_emq_event (event_id),
            CONSTRAINT fk_emq_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        // message_log: attempts bookkeeping (legacy), then recovery columns.
        self::addColumn('message_log', 'latra_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::addColumn('message_log', 'latra_manual_at DATETIME NULL');
        self::addColumn('message_log', 'latra_claim_id VARCHAR(40) NULL');
        self::addColumn('message_log', 'latra_claimed_at DATETIME NULL');
        self::addColumn('message_log', 'next_retry_at DATETIME NULL');
        self::addColumn('message_log', 'max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5');
        self::addColumn('message_log', 'validation_status VARCHAR(20) NULL');
        self::addColumn('message_log', 'data_received_at BIGINT NULL');
        self::addColumn('message_log', 'data_processed_at DATETIME NULL');

        self::addColumn('automation_buckets', 'max_items_per_run INT NOT NULL DEFAULT 100');
        self::addColumn('automation_buckets', 'max_run_seconds INT NOT NULL DEFAULT 300');
        self::addColumn('automation_buckets', 'claim_lease_seconds INT NOT NULL DEFAULT 120');
        self::addColumn('automation_buckets', 'run_on_demand TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumn('automation_buckets', 'consecutive_failures INT NOT NULL DEFAULT 0');
        self::addColumn('automation_buckets', 'is_event TINYINT(1) NOT NULL DEFAULT 0');

        // Movement (activity 1) is interval-driven; all other activities are event-driven.
        \Database::query("UPDATE automation_buckets SET is_event = CASE WHEN activity_id = 1 THEN 0 ELSE 1 END WHERE activity_id IS NOT NULL");

        self::addColumn('bucket_vehicles', 'last_pushed_at TIMESTAMP NULL');
        self::addColumn('bucket_vehicles', 'last_pushed_at_message_time BIGINT NULL');

        self::addColumn('automation_runs', 'triggered_by VARCHAR(10) NOT NULL DEFAULT \'schedule\'');
        self::addColumn('automation_runs', 'duration_seconds INT NOT NULL DEFAULT 0');
        self::addColumn('automation_runs', 'skipped_count INT NOT NULL DEFAULT 0');

        // Buckets run on an exact three-minute cadence; cron checks due buckets.
        \Database::query("UPDATE automation_buckets SET interval_seconds = 180, cooldown_seconds = 0 WHERE interval_seconds <> 180 OR cooldown_seconds <> 0");

        \Database::query(
            "UPDATE automation_buckets SET last_error = NULL
             WHERE last_error = ?",
            ['Intentional: Activity 1 (Movement/Logging) is stored locally but never forwarded to LATRA.']
        );
        \Database::query(
            "UPDATE automation_runs SET error_message = NULL
             WHERE error_message = ?",
            ['Intentional: Activity 1 (Movement/Logging) is stored locally but never forwarded to LATRA.']
        );

        self::addColumn('automation_run_items', 'next_retry_at DATETIME NULL');
        self::addColumn('automation_run_items', 'claim_id VARCHAR(40) NULL');
        self::addColumn('automation_run_items', 'attempt TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::addColumn('automation_run_items', 'failure_class VARCHAR(20) NULL');

        self::addColumn('push_log', 'claim_id VARCHAR(40) NULL');
        self::addColumn('push_log', 'attempt TINYINT UNSIGNED NOT NULL DEFAULT 1');

        // Unique guard against duplicate messages (race-safe ingest).
        $idx = \Database::fetch(
            "SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'message_log' AND index_name = 'uk_msg_dedup'"
        );
        if ((int) ($idx['c'] ?? 0) === 0) {
            \Database::query(
                "DELETE m1 FROM message_log m1
                 JOIN message_log m2 ON m1.vehicle_id = m2.vehicle_id
                  AND m1.wialon_msg_time = m2.wialon_msg_time AND m1.id < m2.id"
            );
            try {
                \Database::query("ALTER TABLE message_log ADD UNIQUE KEY uk_msg_dedup (vehicle_id, wialon_msg_time)");
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') === false
                    && strpos($e->getMessage(), 'already exists') === false) throw $e;
            }
        }
    }

    /** Canonical internal event-type slug per LATRA activity (seed descriptions only). */
    private const EVENT_TYPE_BY_ACTIVITY = [
        1 => 'movement', 2 => 'engine_on', 3 => 'engine_off', 4 => 'speeding',
        5 => 'hard_braking', 6 => 'hard_turning', 7 => 'hard_acceleration',
        8 => 'panic_driver', 9 => 'battery_low', 10 => 'power_disconnected',
        11 => 'excessive_idle', 12 => 'accident', 13 => 'panic_passenger',
        14 => 'tampering', 15 => 'black_box', 16 => 'fuel_report', 17 => 'invalid_scan',
        18 => 'engine_start', 19 => 'engine_stop', 20 => 'enter_boundary',
        21 => 'leave_boundary', 22 => 'enter_checkpoint', 23 => 'leave_checkpoint',
        24 => 'ibutton_regular',
    ];

    /** Detected-event storage, its keyword mapping and edge-trigger channel state. */
    public static function ensureEventSchema(): void
    {
        \Database::query("CREATE TABLE IF NOT EXISTS wialon_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            vehicle_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            activity_id INT NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'derived',
            message_log_id BIGINT NULL,
            wialon_msg_time BIGINT NOT NULL,
            lat DECIMAL(10,8) NULL,
            lng DECIMAL(11,8) NULL,
            altitude INT NULL,
            speed DECIMAL(8,2) NULL,
            bearing DECIMAL(6,2) NULL,
            satellite_count INT NULL,
            hdop DECIMAL(6,2) NULL,
            sensor_values JSON NULL,
            raw_data JSON NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'detected',
            submitted_at DATETIME NULL,
            pushed_at DATETIME NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_event_dedup (vehicle_id, event_type, wialon_msg_time),
            CONSTRAINT fk_wevent_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            CONSTRAINT fk_wevent_msg FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL,
            INDEX idx_wevent_status (status),
            INDEX idx_wevent_vehicle (vehicle_id),
            INDEX idx_wevent_time (wialon_msg_time)
        ) ENGINE=InnoDB");

        \Database::query("CREATE TABLE IF NOT EXISTS wialon_event_map (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_keyword VARCHAR(64) NOT NULL,
            activity_id INT NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'derived',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_event_keyword (event_keyword)
        ) ENGINE=InnoDB");

        if (!self::hasColumn('vehicle_state', 'event_last_msg_time')) {
            \Database::query("ALTER TABLE vehicle_state ADD COLUMN event_last_msg_time BIGINT NULL AFTER last_event_at");
        }
        // External-power presence; also the ALARM channel reset for power events.
        if (!self::hasColumn('vehicle_state', 'external_power')) {
            \Database::query("ALTER TABLE vehicle_state ADD COLUMN external_power TINYINT(1) NOT NULL DEFAULT 0 AFTER engine_status");
        }

        \Database::query("CREATE TABLE IF NOT EXISTS vehicle_event_state (
            vehicle_id INT NOT NULL,
            channel VARCHAR(24) NOT NULL,
            state VARCHAR(40) NULL,
            event_type VARCHAR(50) NULL,
            wialon_msg_time BIGINT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (vehicle_id, channel),
            CONSTRAINT fk_vevstate_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        self::seedEventMap();
        self::seedEventConfig();
    }

    /** @return array<int, string> Activity-id to internal event-type slug. */
    private static function eventTypeNames(): array
    {
        return self::EVENT_TYPE_BY_ACTIVITY;
    }

    private static function seedEventMap(): void
    {
        $desc = self::eventTypeNames();
        foreach (\Latra\Domain\Ingestion\ActivityDetectionPolicy::EVENT_MAP as $keyword => $activityId) {
            \Database::query(
                "INSERT IGNORE INTO wialon_event_map (event_keyword, activity_id, source, is_enabled, description)
                 VALUES (?, ?, 'notification', 1, ?)",
                [
                    $keyword,
                    (int) $activityId,
                    'Wialon event-name keyword -> activity ' . (int) $activityId . ' (' . ($desc[$activityId] ?? '') . ')',
                ]
            );
        }
    }

    private static function seedEventConfig(): void
    {
        $defaults = [
            ['event_engine_enabled', '1', 'Real-time event engine master switch'],
            ['event_engine_window_seconds', '900', 'Event engine fetch window (seconds)'],
            ['event_engine_limit', '100', 'Event engine max messages per vehicle per cycle'],
            ['event_engine_cooldown_seconds', '300', 'Min interval between repeated momentary events per vehicle'],
        ];
        foreach ($defaults as [$key, $value, $description]) {
            \Database::query(
                "INSERT IGNORE INTO config (config_key, config_value, description) VALUES (?, ?, ?)",
                [$key, $value, $description]
            );
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        $row = \Database::fetch(
            "SELECT COUNT(*) AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }
}
