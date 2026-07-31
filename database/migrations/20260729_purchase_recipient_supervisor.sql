ALTER TABLE purchase_notification_recipients
    ADD COLUMN IF NOT EXISTS is_supervisor TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
