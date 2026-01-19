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

-- Table for registered sync clients (linked to devices)
CREATE TABLE llx_smartauth_sync_clients (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_device INTEGER NOT NULL,
    client_uuid VARCHAR(64) NOT NULL,
    last_sync_at DATETIME DEFAULT NULL,
    sync_scope TEXT DEFAULT NULL COMMENT 'JSON: list of enabled object types for this client',
    app_version VARCHAR(32) DEFAULT NULL,
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status INTEGER DEFAULT 1 NOT NULL COMMENT '0=disabled, 1=active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
