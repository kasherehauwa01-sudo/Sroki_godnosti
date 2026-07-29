ALTER TABLE email_notification_log
    ADD COLUMN IF NOT EXISTS distribution_details JSON NULL AFTER retry_payload;

ALTER TABLE purchase_event_summary_links
    ADD COLUMN IF NOT EXISTS recipient_id BIGINT UNSIGNED NULL AFTER expiry_date,
    ADD COLUMN IF NOT EXISTS assigned_batch_ids JSON NULL AFTER access_token_hash,
    ADD COLUMN IF NOT EXISTS unassigned_batch_ids JSON NULL AFTER assigned_batch_ids;

CREATE TABLE IF NOT EXISTS purchase_event_distribution_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    article VARCHAR(128) NOT NULL,
    manager_value VARCHAR(255) NULL,
    matched_recipient_id BIGINT UNSIGNED NULL,
    distribution_type ENUM('PERSONAL', 'UNASSIGNED') NOT NULL,
    distribution_reason VARCHAR(255) NOT NULL,
    actual_recipients JSON NOT NULL,
    send_status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
    smtp_error TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_distribution (event_key, event_date, batch_id),
    INDEX idx_purchase_distribution_created (created_at),
    INDEX idx_purchase_distribution_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_event_recipient_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
    error_message TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_event_recipient (event_key, event_date, recipient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
