CREATE TABLE IF NOT EXISTS stock_notification_reminder_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NOT NULL,
    reminder_number INT UNSIGNED NOT NULL,
    status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
    error_message TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_stock_event_reminder (event_key, event_date, warehouse_id, reminder_number),
    INDEX idx_stock_reminder_due (event_key, event_date, warehouse_id, sent_at),
    CONSTRAINT fk_stock_reminder_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_reminder_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
