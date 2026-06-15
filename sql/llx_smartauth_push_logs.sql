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
-- Table: llx_smartauth_push_logs
--
-- One row per Web Push dispatch attempt (one recipient subscription = one row).
-- Written by PushSender::send() when SMARTAUTH_PUSH_LOG_ENABLED is on. Powers
-- the push_logs_list.php audit page. Retention is bounded and purged by
-- SmartAuth::doScheduledJob (SMARTAUTH_PUSH_LOG_RETENTION_DAYS, default 90).
--
-- Subject identity mirrors llx_smartauth_push_subscriptions:
--   subject_type = 'user'    -> fk_user = llx_user.rowid             (others NULL)
--   subject_type = 'account' -> fk_societe_account = rowid, fk_user = 0
--   subject_type = 'member'  -> fk_adherent = rowid, fk_user = 0
-- fk_user stays NOT NULL (0 sentinel for external subjects).
--
-- http_status / success / error_message capture the Push Service response. Web
-- Push gives no delivery receipt at the protocol level: "success" means the
-- Push Service accepted the message (2xx), not that the user saw it.
-- =============================================================================
CREATE TABLE llx_smartauth_push_logs (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,

    fk_subscription     INTEGER NULL DEFAULT NULL,

    subject_type        VARCHAR(16) DEFAULT 'user' NOT NULL,
    fk_user             INTEGER NOT NULL,
    fk_societe_account  INTEGER NULL DEFAULT NULL,
    fk_adherent         INTEGER NULL DEFAULT NULL,
    entity              INTEGER DEFAULT 1 NOT NULL,

    notification_type   VARCHAR(64) NULL,
    notification_title  VARCHAR(255) NULL,
    notification_body   TEXT NULL,
    notification_data   TEXT NULL,

    http_status         SMALLINT NULL,
    success             TINYINT DEFAULT 0 NOT NULL,
    error_message       VARCHAR(255) NULL,

    date_creation       DATETIME NOT NULL,

    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
