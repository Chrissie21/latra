<?php
/**
 * Wialon event-cycle entry point.
 *
 * Delivery is relay-only. The event cycle is composed by RelayRuntime::cycle()
 * over the relay collection use cases; the former in-process event engine
 * (persistent Wialon session, alarm-channel state, symmetric-event grouping,
 * event dedupe and direct LATRA submission) was removed with the legacy
 * direct-send delivery path.
 *
 * This class keeps the idempotent event schema/bootstrap entry point and the
 * cycle delegate used by cron.php and bin/worker.php.
 */
class EventEngine {

    /** Canonical internal event-type slug per LATRA activity (central mapping). */
    private const TYPE_BY_ACTIVITY = [
        1 => 'movement',
        2 => 'engine_on',
        3 => 'engine_off',
        4 => 'speeding',
        5 => 'hard_braking',
        6 => 'hard_turning',
        7 => 'hard_acceleration',
        8 => 'panic_driver',
        9 => 'battery_low',
        10 => 'power_disconnected',
        11 => 'excessive_idle',
        12 => 'accident',
        13 => 'panic_passenger',
        14 => 'tampering',
        15 => 'black_box',
        16 => 'fuel_report',
        17 => 'invalid_scan',
        18 => 'engine_start',
        19 => 'engine_stop',
        20 => 'enter_boundary',
        21 => 'leave_boundary',
        22 => 'enter_checkpoint',
        23 => 'leave_checkpoint',
        24 => 'ibutton_regular',
    ];

    // ------------------------------------------------------------------
    // Schema + seeds
    // ------------------------------------------------------------------

    public static function ensureEventSchema(): void {
        Database::query("CREATE TABLE IF NOT EXISTS wialon_events (
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

        // Centralized mapping: Wialon event keyword / derived event → LATRA activity.
        Database::query("CREATE TABLE IF NOT EXISTS wialon_event_map (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_keyword VARCHAR(64) NOT NULL,
            activity_id INT NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'derived',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_event_keyword (event_keyword)
        ) ENGINE=InnoDB");

        // Cursor so each cycle only reads messages newer than the last seen.
        if (!self::hasColumn('vehicle_state', 'event_last_msg_time')) {
            Database::query("ALTER TABLE vehicle_state ADD COLUMN event_last_msg_time BIGINT NULL AFTER last_event_at");
        }

        // External-power presence persisted by EventDetector::persistState();
        // used as the ALARM channel reset for power events.
        if (!self::hasColumn('vehicle_state', 'external_power')) {
            Database::query("ALTER TABLE vehicle_state ADD COLUMN external_power TINYINT(1) NOT NULL DEFAULT 0 AFTER engine_status");
        }

        // Per-vehicle channel state for edge-triggered (state-transition) events.
        // A channel tracks the last pushed state so the SAME condition is only
        // submitted once, until its complement clears it (e.g. power returns).
        Database::query("CREATE TABLE IF NOT EXISTS vehicle_event_state (
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
        self::seedConfig();
    }

    private static function seedEventMap(): void {
        $map = EventDetector::EVENT_MAP;
        $desc = self::TYPE_BY_ACTIVITY;
        foreach ($map as $keyword => $activityId) {
            Database::query(
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

    private static function seedConfig(): void {
        $defaults = [
            ['event_engine_enabled', '1', 'Real-time event engine master switch'],
            ['event_engine_window_seconds', '900', 'Event engine fetch window (seconds)'],
            ['event_engine_limit', '100', 'Event engine max messages per vehicle per cycle'],
            ['event_engine_cooldown_seconds', '300', 'Min interval between repeated momentary events per vehicle'],
        ];
        foreach ($defaults as [$k, $v, $d]) {
            Database::query(
                "INSERT IGNORE INTO config (config_key, config_value, description) VALUES (?, ?, ?)",
                [$k, $v, $d]
            );
        }
    }

    /** Relay-only event cycle: bounded collection per enrolled vehicle. */
    public static function runCycle(): array {
        return RelayRuntime::cycle();
    }

    private static function hasColumn(string $table, string $column): bool {
        $row = Database::fetch(
            "SELECT COUNT(*) AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }
}
