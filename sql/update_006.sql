ALTER TABLE llx_smartauth_logs ADD COLUMN fk_device_id INTEGER NOT NULL AFTER user_agent;

ALTER TABLE llx_smartauth_auth ADD COLUMN fk_device_id INTEGER NOT NULL AFTER ip;

