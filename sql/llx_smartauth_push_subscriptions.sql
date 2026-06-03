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
-- Table: llx_smartauth_push_subscriptions
--
-- W3C Web Push subscriptions, subject-aware (mirror of
-- llx_smartauth_oauth_tokens). A subscription belongs to a SmartAuth subject,
-- not only to a llx_user:
--   subject_type = 'user'    -> fk_user = llx_user.rowid             (others NULL)
--   subject_type = 'account' -> fk_societe_account = rowid, fk_user = 0
--   subject_type = 'member'  -> fk_adherent = rowid, fk_user = 0
-- fk_user stays NOT NULL (0 sentinel for external subjects) to avoid a
-- MODIFY COLUMN that SQLite cannot perform on update.
--
-- The endpoint is the push channel URL; it is globally unique (one channel per
-- browser install) and carries the UPSERT identity (see PushController).
--
-- status: 0 = disabled, 1 = active, 9 = expired (404/410 from the push service
-- or MAX_ERROR_COUNT consecutive failures). Expired rows are purged by
-- SmartAuth::doScheduledJob.
--
-- NOTE on column naming: key_p256dh / key_auth keep the "key_" prefix (key
-- immediately followed by "_", never "key" + whitespace) on purpose. Dolibarr's
-- SQLite SQL translator strips ", KEY <ident> (<cols>)" fragments with a
-- non-anchored regex /KEY\s+\w+\s*\(/. A column named "*_key VARCHAR(...)"
-- ("key" + space + VARCHAR + "(") would match and get its type dropped; the
-- key_* prefix form does not.
-- =============================================================================
CREATE TABLE llx_smartauth_push_subscriptions (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,

    subject_type        VARCHAR(16) DEFAULT 'user' NOT NULL,
    fk_user             INTEGER NOT NULL,
    fk_societe_account  INTEGER NULL DEFAULT NULL,
    fk_adherent         INTEGER NULL DEFAULT NULL,

    fk_device           INTEGER NULL DEFAULT NULL,
    entity              INTEGER DEFAULT 1 NOT NULL,

    endpoint            TEXT NOT NULL,
    key_p256dh          VARCHAR(255) NOT NULL,
    key_auth            VARCHAR(255) NOT NULL,

    user_agent          VARCHAR(255) NULL,
    label               VARCHAR(128) NULL,

    date_creation       DATETIME NOT NULL,
    date_last_used      DATETIME NULL,
    date_last_error     DATETIME NULL,
    last_error          VARCHAR(255) NULL,

    success_count       INTEGER DEFAULT 0,
    error_count         INTEGER DEFAULT 0,

    status              TINYINT DEFAULT 1 NOT NULL,

    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
