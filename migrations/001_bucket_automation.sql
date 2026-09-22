-- LATRA-Wialon bucket automation migration
-- Run with:
-- mysql --skip-ssl -h 127.0.0.1 -u root latra_wialon < migrations/001_bucket_automation.sql

USE latra_wialon;

CREATE TABLE IF NOT EXISTS automation_buckets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bucket_name VARCHAR(100) NOT NULL,
    activity_id INT NOT NULL,
    interval_seconds INT NOT NULL DEFAULT 300,
    cooldown_seconds INT NOT NULL DEFAULT 30,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    run_count INT NOT NULL DEFAULT 0,
    fail_count INT NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bucket_due (is_enabled, next_run_at),
    FOREIGN KEY (activity_id) REFERENCES activity_definitions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bucket_vehicles (
    bucket_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    PRIMARY KEY (bucket_id, vehicle_id),
    FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS automation_runs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    bucket_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    total_count INT NOT NULL DEFAULT 0,
    success_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    manual_count INT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    INDEX idx_run_started (started_at),
    FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS automation_run_items (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    run_id BIGINT NOT NULL,
    bucket_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    activity_id INT NOT NULL,
    message_log_id BIGINT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    completed_at DATETIME NULL,
    INDEX idx_item_status (status),
    FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @add_attempts = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND COLUMN_NAME = 'latra_attempts'
    ),
    'SELECT 1',
    'ALTER TABLE message_log ADD COLUMN latra_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER latra_status'
);
PREPARE migration_statement FROM @add_attempts;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_manual_at = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND COLUMN_NAME = 'latra_manual_at'
    ),
    'SELECT 1',
    'ALTER TABLE message_log ADD COLUMN latra_manual_at DATETIME NULL AFTER pushed_at'
);
PREPARE migration_statement FROM @add_manual_at;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SELECT 'Bucket automation migration completed' AS result;
