-- Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- =============================================================================
-- Indexes and Foreign Keys for llx_smartauth_user_devices
-- =============================================================================
ALTER TABLE llx_smartauth_user_devices ADD UNIQUE INDEX uk_smartauth_user_devices_user_label_entity (fk_user, label, entity);
ALTER TABLE llx_smartauth_user_devices ADD INDEX idx_smartauth_user_devices_fk_user (fk_user);
ALTER TABLE llx_smartauth_user_devices ADD INDEX idx_smartauth_user_devices_status (status);
ALTER TABLE llx_smartauth_user_devices ADD CONSTRAINT fk_smartauth_user_devices_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid);
