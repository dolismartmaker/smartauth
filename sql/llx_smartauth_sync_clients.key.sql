-- Copyright (C) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

ALTER TABLE llx_smartauth_sync_clients ADD UNIQUE INDEX uk_client_uuid (client_uuid);
ALTER TABLE llx_smartauth_sync_clients ADD INDEX idx_fk_device (fk_device);
ALTER TABLE llx_smartauth_sync_clients ADD INDEX idx_status (status);
ALTER TABLE llx_smartauth_sync_clients ADD INDEX idx_last_sync (last_sync_at);
