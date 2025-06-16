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
ALTER TABLE llx_smartauth_logs ADD INDEX idx_smartauth_logs_rowid (rowid);
ALTER TABLE llx_smartauth_logs ADD INDEX idx_smartauth_logs_appuid (appuid);
ALTER TABLE llx_smartauth_logs ADD INDEX idx_smartauth_logs_fk_key (fk_key);
ALTER TABLE llx_smartauth_logs ADD INDEX idx_smartauth_logs_dol_element (dol_element);
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_smartauth_logs ADD UNIQUE INDEX uk_smartauth_logs_fieldxy(fieldx, fieldy);

--ALTER TABLE llx_smartauth_logs ADD CONSTRAINT llx_smartauth_logs_fk_field FOREIGN KEY (fk_field) REFERENCES llx_smartauth_myotherobject(rowid);

