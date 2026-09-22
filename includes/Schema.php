<?php
/**
 * Schema setup.
 */
class Schema {
    public const VERSION = 9;

    public static function ensure(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        Automation::ensureSchema();
        Auth::ensureTables();
        Vault::ensureTable();
        if (class_exists('AIAssistant')) {
            AIAssistant::ensureSchema();
        }

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

        Database::query("CREATE TABLE IF NOT EXISTS param_aliases (
            id INT PRIMARY KEY AUTO_INCREMENT,
            device_type VARCHAR(80) NOT NULL DEFAULT '*',
            param_key VARCHAR(80) NOT NULL,
            canonical_key VARCHAR(80) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uk_alias (device_type, param_key, canonical_key)
        ) ENGINE=InnoDB");

        Database::query("CREATE TABLE IF NOT EXISTS outbox (
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

        Database::query("CREATE TABLE IF NOT EXISTS jobs (
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

        Database::query("CREATE TABLE IF NOT EXISTS job_runs (
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

        Database::query("CREATE TABLE IF NOT EXISTS device_documents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            device_type VARCHAR(80) NULL,
            protocol VARCHAR(80) NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NULL,
            uploaded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        Database::query("CREATE TABLE IF NOT EXISTS webhook_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_type VARCHAR(60) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            tries INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wh_status (status)
        ) ENGINE=InnoDB");

        Database::query("CREATE TABLE IF NOT EXISTS kpi_daily (
            day DATE PRIMARY KEY,
            vehicles INT NOT NULL DEFAULT 0,
            messages INT NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            rejected INT NOT NULL DEFAULT 0,
            emergencies INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");

        try {
            Database::query("CREATE TABLE IF NOT EXISTS message_log_archive LIKE message_log");
        } catch (PDOException $e) {
            // archive is optional if FK copy is unsupported
        }
        try {
            Database::query("CREATE TABLE IF NOT EXISTS push_log_archive LIKE push_log");
        } catch (PDOException $e) {
        }

        self::seedJobs();
        self::seedAliases();
        self::seedConfig();

        Database::query(
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
            ['audit_rollup', 'Daily KPI rollup', 86400],
            ['reconcile', 'Crash recovery', 60],
        ];
        foreach ($jobs as [$key, $label, $interval]) {
            Database::query(
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
            Database::query(
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
            ['webhook_url', '', 'Operator alert webhook'],
            ['latra_ip_allowlist', '', 'Comma-separated IPs for LATRA-facing API'],
            ['scheduler_enabled', '1', 'Master scheduler switch'],
            ['gps_max_delay_seconds', '300', 'Compliance: max acceptable GPS delivery delay'],
            ['gps_min_frequency_seconds', '120', 'Compliance: min expected GPS reporting frequency'],
        ];
        foreach ($defaults as [$k, $v, $d]) {
            Database::query(
                "INSERT IGNORE INTO config (config_key, config_value, description) VALUES (?, ?, ?)",
                [$k, $v, $d]
            );
        }
    }

    public static function config(string $key, string $default = ''): string {
        $row = Database::fetch("SELECT config_value FROM config WHERE config_key = ?", [$key]);
        return $row ? (string) $row['config_value'] : $default;
    }

    public static function configInt(string $key, int $default = 0): int {
        $v = self::config($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private static function addColumn(string $table, string $definition): void {
        try {
            Database::query("ALTER TABLE `$table` ADD COLUMN $definition");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }
}
