ALTER TABLE settings
    MODIFY COLUMN auto_import_time CHAR(5) NOT NULL DEFAULT '23:59',
    ADD COLUMN ftp_protocol VARCHAR(8) NOT NULL DEFAULT 'FTP' AFTER auto_import_time,
    ADD COLUMN ftp_host VARCHAR(255) NULL AFTER ftp_protocol,
    ADD COLUMN ftp_port SMALLINT UNSIGNED NOT NULL DEFAULT 21 AFTER ftp_host,
    ADD COLUMN ftp_username VARCHAR(255) NULL AFTER ftp_port,
    ADD COLUMN ftp_password TEXT NULL AFTER ftp_username,
    ADD COLUMN ftp_directory VARCHAR(1024) NOT NULL DEFAULT '/' AFTER ftp_password,
    ADD COLUMN ftp_connection_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER ftp_directory,
    ADD COLUMN ftp_retry_delay SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER ftp_connection_attempts;

UPDATE settings SET auto_import_time = '23:59' WHERE id = 1;
