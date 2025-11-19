ALTER TABLE llx_smartauth_logs ADD COLUMN device_id VARCHAR(40) DEFAULT '' AFTER user_agent;

ALTER TABLE llx_smartauth_auth ADD COLUMN device_id VARCHAR(40) DEFAULT '' AFTER ip;
