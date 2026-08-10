-- Фиксирует остаток в контексте конкретного события.
-- Общая batch_stock продолжает хранить последнее отображаемое значение, но больше
-- не используется как доказательство заполнения текущего события.
CREATE TABLE IF NOT EXISTS purchase_event_stock_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(14,3) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'user',
    notification_id BIGINT UNSIGNED NULL,
    filled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_event_stock (event_key, event_date, batch_id, warehouse_id),
    INDEX idx_purchase_event_stock_event (event_key, event_date),
    INDEX idx_purchase_event_stock_notification (notification_id),
    CONSTRAINT fk_purchase_event_stock_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_event_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_event_stock_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
