ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS email_log_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 365 AFTER missing_filter_email;

CREATE TABLE IF NOT EXISTS email_notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notification_type VARCHAR(128) NOT NULL,
    subject VARCHAR(998) NOT NULL,
    recipients JSON NOT NULL,
    status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
    smtp_code VARCHAR(16) NULL,
    diagnostic_code TEXT NULL,
    error_reason VARCHAR(255) NULL,
    smtp_response MEDIUMTEXT NULL,
    message_id VARCHAR(255) NULL,
    duration_ms INT UNSIGNED NULL,
    retry_payload LONGTEXT NULL,
    PRIMARY KEY (id),
    INDEX idx_email_log_created_at (created_at),
    INDEX idx_email_log_status (status),
    INDEX idx_email_log_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
