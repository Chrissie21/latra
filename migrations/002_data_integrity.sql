-- LATRA Compliance: Phase 1 Data Integrity & Validation
-- ============================================================
-- Adds:
--   * validation_errors table (per-field validation failures)
--   * message_log: validation_status, data_received_at, data_processed_at columns
--
-- Idempotent: safe to run repeatedly.

USE latra_wialon;

-- ------------------------------------------------------------
-- VALIDATION ERRORS — records every validation failure so no
-- invalid record is silently dropped or submitted.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS validation_errors (
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
) ENGINE=InnoDB;

-- Helper: add column if it does not exist (MariaDB 10.4 lacks ADD COLUMN IF NOT EXISTS)
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND COLUMN_NAME = 'validation_status') = 0,
    'ALTER TABLE message_log ADD COLUMN validation_status VARCHAR(20) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND COLUMN_NAME = 'data_received_at') = 0,
    'ALTER TABLE message_log ADD COLUMN data_received_at BIGINT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND COLUMN_NAME = 'data_processed_at') = 0,
    'ALTER TABLE message_log ADD COLUMN data_processed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes for validation_status queries
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND INDEX_NAME = 'idx_validation_status') = 0,
    'ALTER TABLE message_log ADD INDEX idx_validation_status (validation_status)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
