-- Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- =============================================================================
-- Indexes and Foreign Keys for llx_smartauth_qr_pairings
-- =============================================================================
ALTER TABLE llx_smartauth_qr_pairings ADD UNIQUE INDEX uk_qr_pairings_pairing_id (pairing_id);
ALTER TABLE llx_smartauth_qr_pairings ADD INDEX idx_qr_pairings_fk_user (fk_user);
ALTER TABLE llx_smartauth_qr_pairings ADD INDEX idx_qr_pairings_status (status);
ALTER TABLE llx_smartauth_qr_pairings ADD INDEX idx_qr_pairings_expires_at (expires_at);
ALTER TABLE llx_smartauth_qr_pairings ADD CONSTRAINT fk_qr_pairings_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;
