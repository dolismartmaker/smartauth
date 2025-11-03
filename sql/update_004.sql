ALTER TABLE llx_smartauth_auth ADD COLUMN token_type VARCHAR(20) DEFAULT 'access' AFTER auth_element;
ALTER TABLE llx_smartauth_auth ADD COLUMN parent_token_id INTEGER DEFAULT NULL AFTER fk_authid;
ALTER TABLE llx_smartauth_auth ADD COLUMN refresh_count INTEGER DEFAULT 0 AFTER date_lastused;
