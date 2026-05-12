-- Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- =============================================================================
-- Indexes and Foreign Keys for llx_smartauth_upload_idempotency
-- =============================================================================
ALTER TABLE llx_smartauth_upload_idempotency ADD UNIQUE INDEX uk_upload_idempotency_token_user (idempotency_token, fk_user, entity);
ALTER TABLE llx_smartauth_upload_idempotency ADD INDEX idx_upload_idempotency_status (status);
ALTER TABLE llx_smartauth_upload_idempotency ADD INDEX idx_upload_idempotency_created (created_at);
ALTER TABLE llx_smartauth_upload_idempotency ADD CONSTRAINT fk_upload_idempotency_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;
