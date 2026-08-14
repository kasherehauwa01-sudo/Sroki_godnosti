CREATE TABLE IF NOT EXISTS stock_event_completion_log (
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_key, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
