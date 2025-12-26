-- Rename parent_token_id to family_id for clarity
ALTER TABLE llx_smartauth_auth CHANGE COLUMN parent_token_id family_id INTEGER DEFAULT NULL;
