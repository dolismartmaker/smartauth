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


-- BEGIN MODULEBUILDER INDEXES
ALTER TABLE llx_smartauth_devices ADD INDEX idx_smartauth_devices_rowid (rowid);
ALTER TABLE llx_smartauth_devices ADD CONSTRAINT llx_smartauth_devices_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user(rowid);
ALTER TABLE llx_smartauth_devices ADD INDEX idx_smartauth_devices_status (status);
ALTER TABLE llx_smartauth_devices ADD UNIQUE INDEX idx_smartauth_devices_uuid (uuid);
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_smartauth_devices ADD UNIQUE INDEX uk_smartauth_devices_fieldxy(fieldx, fieldy);

--ALTER TABLE llx_smartauth_devices ADD CONSTRAINT llx_smartauth_devices_fk_field FOREIGN KEY (fk_field) REFERENCES llx_smartauth_myotherobject(rowid);

