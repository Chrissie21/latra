-- LATRA Compliance: Phase 5 Device Status Monitoring
-- ============================================================
-- Tracks per-device health: online/offline, internal battery low,
-- external power disconnected, device tampering, last communication,
-- and last GPS update. Generates status change events.
--
-- Idempotent: safe to run repeatedly.

USE latra_wialon;

CREATE TABLE IF NOT EXISTS device_status (
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
) ENGINE=InnoDB;

-- Seeded automatically from vehicles is_active state on first DeviceMonitor run.
