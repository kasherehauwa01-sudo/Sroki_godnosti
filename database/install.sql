CREATE DATABASE IF NOT EXISTS sroki_godnosti
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sroki_godnosti;

CREATE TABLE IF NOT EXISTS batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    article VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    expiry_date DATE NOT NULL,
    expiry_full_date TINYINT(1) NOT NULL DEFAULT 0,
    expiry_invalid TINYINT(1) NOT NULL DEFAULT 0,
    expiry_raw VARCHAR(32) NULL,
    days_left INT NOT NULL DEFAULT 0,
    status ENUM('В наличии', 'Реализована', 'Списана') NOT NULL DEFAULT 'В наличии',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_batches_article (article),
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
    smtp_from_email VARCHAR(255) NULL,
    smtp_from_name VARCHAR(255) NULL,
    notification_time CHAR(5) NOT NULL DEFAULT '09:00',
    PRIMARY KEY (id)
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
    notification_time
) VALUES (1, 0, 0, 0, 1, 1, 1, 0, 0, 'vr-vk@yandex.ru', 'smtp.yandex.ru', 587, 'vr-vk@yandex.ru', NULL, 'vr-vk@yandex.ru', 'Сроки годности', '09:00')
ON DUPLICATE KEY UPDATE id = id;
