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

-- Table for sync conflicts requiring resolution
CREATE TABLE llx_smartauth_sync_conflicts (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_client INTEGER NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    object_id INTEGER NOT NULL,
    client_data TEXT NOT NULL COMMENT 'JSON: client version of the data',
    server_data TEXT NOT NULL COMMENT 'JSON: server version of the data',
    client_tms DATETIME NOT NULL,
    server_tms DATETIME NOT NULL,
    field_conflicts TEXT DEFAULT NULL COMMENT 'JSON: list of conflicting fields with both values',
    status VARCHAR(16) DEFAULT 'pending' NOT NULL COMMENT 'pending, resolved, dismissed',
    resolution VARCHAR(16) DEFAULT NULL COMMENT 'client, server, merged',
    resolved_data TEXT DEFAULT NULL COMMENT 'JSON: final merged data if resolution=merged',
    resolved_at DATETIME DEFAULT NULL,
    resolved_by INTEGER DEFAULT NULL COMMENT 'fk_user who resolved',
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
