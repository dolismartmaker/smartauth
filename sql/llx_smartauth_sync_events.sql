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

-- Table for sync events (audit trail)
CREATE TABLE llx_smartauth_sync_events (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_client INTEGER NOT NULL,
    event_type VARCHAR(32) NOT NULL COMMENT 'register, pull, push, conflict, resolve, error',
    table_name VARCHAR(64) DEFAULT NULL,
    object_id INTEGER DEFAULT NULL,
    event_data TEXT DEFAULT NULL COMMENT 'JSON: additional event details',
    date_creation DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
