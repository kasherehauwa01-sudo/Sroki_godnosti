CREATE DATABASE IF NOT EXISTS sroki_godnosti
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sroki_godnosti;

CREATE TABLE IF NOT EXISTS batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    article VARCHAR(128) NOT NULL,
    code VARCHAR(128) NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    expiry_date DATE NOT NULL,
    days_left INT NOT NULL DEFAULT 0,
    status ENUM('В наличии', 'Реализована', 'Списана') NOT NULL DEFAULT 'В наличии',
    store_name VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_batches_article (article),
    INDEX idx_batches_code (code),
    INDEX idx_batches_name (name),
    INDEX idx_batches_status (status),
    INDEX idx_batches_store_name (store_name),
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
    notify_90_days TINYINT(1) NOT NULL DEFAULT 0,
    notify_60_days TINYINT(1) NOT NULL DEFAULT 1,
    notify_30_days TINYINT(1) NOT NULL DEFAULT 1,
    notify_15_days TINYINT(1) NOT NULL DEFAULT 1,
    notify_7_days TINYINT(1) NOT NULL DEFAULT 0,
    notify_1_day TINYINT(1) NOT NULL DEFAULT 0,
    notification_email TEXT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (
    id,
    notify_90_days,
    notify_60_days,
    notify_30_days,
    notify_15_days,
    notify_7_days,
    notify_1_day,
    notification_email
) VALUES (1, 0, 1, 1, 1, 0, 0, 'vr-vk@yandex.ru')
ON DUPLICATE KEY UPDATE id = id;
