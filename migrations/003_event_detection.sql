-- LATRA Compliance: Phase 2 Multi-Activity Event Detection
-- ============================================================
-- Adds:
--   * overspeed_config   (per-vehicle or global speed limits for speeding detection)
--   * geofence_definitions (boundary/checkpoint/geofence areas)
--   * vehicle_state      (per-vehicle running state for event detection)
--
-- Idempotent: safe to run repeatedly.

USE latra_wialon;

-- ------------------------------------------------------------
-- OVERSPEED CONFIG — speed limit used to detect "Speeding" (activity 4)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS overspeed_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NULL,
    speed_limit_kmh DECIMAL(6,2) NOT NULL DEFAULT 80.00,
    duration_seconds INT NOT NULL DEFAULT 5 COMMENT 'Seconds above limit before triggering',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_overspeed_vehicle (vehicle_id),
    CONSTRAINT fk_overspeed_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- GEOFENCE DEFINITIONS — boundaries and checkpoints
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geofence_definitions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    type ENUM('boundary','checkpoint') NOT NULL DEFAULT 'boundary',
    shape ENUM('circle','polygon') NOT NULL DEFAULT 'circle',
    latitude DECIMAL(10,8) NULL COMMENT 'Center latitude (circle)',
    longitude DECIMAL(11,8) NULL COMMENT 'Center longitude (circle)',
    radius_meters DECIMAL(12,3) NULL COMMENT 'Radius in meters (circle)',
    polygon_geo JSON NULL COMMENT 'GeoJSON polygon coordinates',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geotype (type),
    INDEX idx_enabled (is_enabled)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- VEHICLE STATE — per-vehicle running state used by EventDetector
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicle_state (
    vehicle_id INT PRIMARY KEY,
    engine_status VARCHAR(20) DEFAULT NULL COMMENT 'on|off',
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
) ENGINE=InnoDB;
