-- Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
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

-- =============================================================================
-- Table: llx_smartauth_user_devices
--
-- Logical physical device of a user, e.g. "mon iPhone". One row per
-- physical device the user owns, regardless of how many PWAs are installed
-- on it. Each row in llx_smartauth_devices (the per-PWA UUID) points to
-- one of these rows through fk_user_device, so revoking a user_device
-- cascades to every PWA session on that device.
--
-- Lifecycle:
--   status = 1 active
--   status = 9 revoked (user pressed "revoke this device")
--
-- Unicity: a single user cannot have two devices with the same label in
-- the same entity. Two distinct users can both name a device "iPhone Max"
-- without colliding (the rowid is owned by fk_user).
-- =============================================================================
CREATE TABLE llx_smartauth_user_devices (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_user         INTEGER NOT NULL,
    label           VARCHAR(100) NOT NULL,
    icon            VARCHAR(32) DEFAULT 'phone' NOT NULL,
    date_creation   DATETIME NOT NULL,
    date_lastseen   DATETIME NULL,
    status          INTEGER DEFAULT 1 NOT NULL,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
