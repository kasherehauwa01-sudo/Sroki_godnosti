CREATE DATABASE IF NOT EXISTS sroki_godnosti
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sroki_godnosti;

CREATE TABLE IF NOT EXISTS batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_source VARCHAR(32) NOT NULL DEFAULT 'Ручной',
    article VARCHAR(128) NOT NULL,
    code VARCHAR(128) NOT NULL DEFAULT '',
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    expiry_date DATE NOT NULL,
    expiry_full_date TINYINT(1) NOT NULL DEFAULT 0,
    expiry_invalid TINYINT(1) NOT NULL DEFAULT 0,
    expiry_raw VARCHAR(32) NULL,
    days_left INT NOT NULL DEFAULT 0,
    status ENUM('В наличии', 'Реализована', 'Списана', 'Нет в наличии') NOT NULL DEFAULT 'В наличии',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_batches_article (article),
    INDEX idx_batches_code (code),
    INDEX idx_batches_name (name),
    INDEX idx_batches_status (status),
    INDEX idx_batches_expiry_date (expiry_date),
    INDEX idx_batches_days_left (days_left)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(128) NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_logs_action (action),
    INDEX idx_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id TINYINT UNSIGNED NOT NULL,
    notify_0_days TINYINT(1) NOT NULL DEFAULT 0,
    notify_180_days TINYINT(1) NOT NULL DEFAULT 0,
    notify_90_days TINYINT(1) NOT NULL DEFAULT 0,
    notify_60_days TINYINT(1) NOT NULL DEFAULT 1,
    notify_30_days TINYINT(1) NOT NULL DEFAULT 1,
    notify_15_days TINYINT(1) NOT NULL DEFAULT 1,
    notify_7_days TINYINT(1) NOT NULL DEFAULT 0,
    notify_1_day TINYINT(1) NOT NULL DEFAULT 0,
    notification_email TEXT NULL,
    smtp_host VARCHAR(255) NULL,
    smtp_port SMALLINT UNSIGNED NULL,
    smtp_username VARCHAR(255) NULL,
    smtp_password TEXT NULL,
    smtp_from_email TEXT NULL,
    smtp_from_name VARCHAR(255) NULL,
    notification_time CHAR(5) NOT NULL DEFAULT '09:00',
    auto_import_time CHAR(5) NOT NULL DEFAULT '23:50',
    missing_filter_email TEXT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_missing_filter_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    codes TEXT NOT NULL,
    recipients TEXT NOT NULL,
    status ENUM('SUCCESS', 'ERROR') NOT NULL,
    error_message TEXT NULL,
    PRIMARY KEY (id),
    INDEX idx_missing_filter_created_at (created_at),
    INDEX idx_missing_filter_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (
    id,
    notify_0_days,
    notify_180_days,
    notify_90_days,
    notify_60_days,
    notify_30_days,
    notify_15_days,
    notify_7_days,
    notify_1_day,
    notification_email,
    smtp_host,
    smtp_port,
    smtp_username,
    smtp_password,
    smtp_from_email,
    smtp_from_name,
    notification_time,
    auto_import_time,
    missing_filter_email
) VALUES (1, 0, 0, 0, 1, 1, 1, 0, 0, 'vr-vk@yandex.ru', 'smtp.yandex.ru', 587, 'vr-vk@yandex.ru', NULL, 'vr-vk@yandex.ru', 'Сроки годности', '09:00', '23:50', NULL)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS warehouses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    email TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_warehouses_active_order (is_active, sort_order),
    UNIQUE KEY uniq_warehouses_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_stock (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_batch_stock_batch_warehouse (batch_id, warehouse_id),
    INDEX idx_batch_stock_batch (batch_id),
    INDEX idx_batch_stock_warehouse (warehouse_id),
    CONSTRAINT fk_batch_stock_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_batch_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO warehouses (name, sort_order, email, is_active) VALUES
    ('Авиаторов', 10, NULL, 1),
    ('Козловская', 20, NULL, 1),
    ('Цитрус', 30, NULL, 1),
    ('Привоз', 40, NULL, 1),
    ('Бахтурова', 50, NULL, 1),
    ('Ахтубинск', 60, NULL, 1),
    ('СтройГрад', 70, NULL, 1),
    ('Европа', 80, NULL, 1),
    ('Парк Хаус', 90, NULL, 1),
    ('ЦУМ', 100, NULL, 1),
    ('Простор', 110, NULL, 1),
    ('Универ', 120, NULL, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS stock_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(128) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    email TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    first_opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,
    last_changed_at DATETIME NULL,
    completed_at DATETIME NULL,
    status ENUM('Не открыта', 'Открыта', 'Частично заполнена', 'Заполнена', 'Просрочена', 'Закрыта администратором') NOT NULL DEFAULT 'Не открыта',
    PRIMARY KEY (id),
    INDEX idx_stock_notifications_warehouse (warehouse_id),
    INDEX idx_stock_notifications_status (status),
    INDEX idx_stock_notifications_created_at (created_at),
    CONSTRAINT fk_stock_notifications_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_notification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(128) NULL,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    status ENUM('Активна', 'Истек срок действия', 'Закрыта администратором') NOT NULL DEFAULT 'Активна',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_stock_token_hash (token_hash),
    INDEX idx_stock_token_notification (notification_id),
    INDEX idx_stock_token_expires (expires_at),
    CONSTRAINT fk_stock_token_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_notification_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    article VARCHAR(128) NOT NULL,
    code VARCHAR(128) NOT NULL DEFAULT '',
    name VARCHAR(255) NOT NULL DEFAULT '',
    expiry_date DATE NULL,
    expiry_full_date TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    INDEX idx_stock_items_notification (notification_id),
    INDEX idx_stock_items_batch (batch_id),
    CONSTRAINT fk_stock_items_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_items_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_change_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    old_quantity DECIMAL(14,3) NULL,
    new_quantity DECIMAL(14,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(64) NULL,
    user_agent TEXT NULL,
    PRIMARY KEY (id),
    INDEX idx_stock_change_notification (notification_id),
    INDEX idx_stock_change_batch (batch_id),
    CONSTRAINT fk_stock_change_notification FOREIGN KEY (notification_id) REFERENCES stock_notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_change_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_change_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_batch_notification_views (
    batch_id BIGINT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (batch_id),
    CONSTRAINT fk_stock_batch_views_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_notification_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_recipient_email (email),
    INDEX idx_purchase_recipient_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    event_days INT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipients JSON NULL,
    status ENUM('SUCCESS', 'ERROR') NOT NULL,
    error_message TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_batch_event (batch_id, event_days),
    INDEX idx_purchase_log_status (status),
    CONSTRAINT fk_purchase_log_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_event_notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    event_days INT NOT NULL,
    expiry_date DATE NOT NULL,
    access_token_hash CHAR(64) NOT NULL,
    recipients JSON NULL,
    status ENUM('PENDING', 'SUCCESS', 'ERROR') NOT NULL DEFAULT 'PENDING',
    error_message TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_event (event_key, event_date),
    UNIQUE KEY uniq_purchase_event_token (access_token_hash),
    INDEX idx_purchase_event_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_event_summary_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(128) NOT NULL,
    event_date DATE NOT NULL,
    event_days INT NOT NULL,
    expiry_date DATE NOT NULL,
    access_token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_purchase_summary_token (access_token_hash),
    INDEX idx_purchase_summary_event (event_key, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
