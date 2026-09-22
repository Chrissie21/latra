-- LATRA Compliance: Phase 4 Emergency Event Mode
-- ============================================================
-- Implements high-frequency (1-second) GPS submission during emergency
-- events (notably over-speeding) per LATRA requirements.
--
-- emergency_events : an active (or completed) emergency lifecycle.
-- emergency_queue  : high-frequency records buffered for a vehicle while
--                    an emergency event is ACTIVE; submitted with the event_id,
--                    and closed when the event ends or connectivity returns.
--
-- Idempotent: safe to run repeatedly.

USE latra_wialon;

-- ------------------------------------------------------------
-- EMERGENCY EVENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS emergency_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    event_id VARCHAR(40) NOT NULL,
    vehicle_id INT NOT NULL,
    event_type VARCHAR(30) NOT NULL DEFAULT 'overspeed' COMMENT 'overspeed|panic|accident|other',
    activity_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|ended|expired',
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
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- EMERGENCY QUEUE — high-frequency GPS records for active events
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS emergency_queue (
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
    latra_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|failed',
    latra_response TEXT NULL,
    submitted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_emergency_msg (event_id, wialon_msg_time, vehicle_id),
    INDEX idx_emq_status (latra_status),
    INDEX idx_emq_vehicle (vehicle_id),
    INDEX idx_emq_event (event_id),
    CONSTRAINT fk_emq_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
