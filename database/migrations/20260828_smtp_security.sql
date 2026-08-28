ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS smtp_security VARCHAR(16) NOT NULL DEFAULT '' AFTER smtp_port;

UPDATE settings
SET smtp_security = CASE WHEN smtp_port = 465 THEN 'SSL' ELSE 'STARTTLS' END
WHERE smtp_security = '';

ALTER TABLE settings
    MODIFY COLUMN smtp_security VARCHAR(16) NOT NULL DEFAULT 'STARTTLS';
