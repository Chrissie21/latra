-- LATRA Compliance: Phase 3 GPS Update Frequency Monitoring
-- ============================================================
-- Tracks the latest GPS timestamp for every vehicle and flags vehicles
-- whose updates exceed the LATRA 3-minute interval (or are offline).
--
-- Idempotent: safe to run repeatedly.

USE latra_wialon;

CREATE TABLE IF NOT EXISTS gps_health (
    vehicle_id INT PRIMARY KEY,
    last_gps_time BIGINT NULL COMMENT 'Latest GPS message epoch (seconds)',
    last_gps_at DATETIME NULL,
    last_comm_time BIGINT NULL COMMENT 'Last server communication epoch',
    status VARCHAR(20) NOT NULL DEFAULT 'unknown' COMMENT 'ok|stale|offline|unknown',
    minutes_since INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_gpshealth_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_gps_status (status),
    INDEX idx_gps_time (last_gps_time)
) ENGINE=InnoDB;

-- Preseed from current message_log (latest wialon_msg_time per vehicle).
INSERT IGNORE INTO gps_health (vehicle_id, last_gps_time, last_gps_at)
SELECT vehicle_id, MAX(wialon_msg_time),
       FROM_UNIXTIME(MAX(wialon_msg_time))
FROM message_log
GROUP BY vehicle_id;
