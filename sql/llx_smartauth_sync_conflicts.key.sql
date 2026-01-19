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

ALTER TABLE llx_smartauth_sync_conflicts ADD INDEX idx_fk_client (fk_client);
ALTER TABLE llx_smartauth_sync_conflicts ADD INDEX idx_status (status);
ALTER TABLE llx_smartauth_sync_conflicts ADD INDEX idx_table_object (table_name, object_id);
ALTER TABLE llx_smartauth_sync_conflicts ADD CONSTRAINT fk_conflict_client FOREIGN KEY (fk_client) REFERENCES llx_smartauth_sync_clients(rowid) ON DELETE CASCADE;
