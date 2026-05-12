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
-- Table: llx_smartauth_upload_idempotency
--
-- Per-user idempotency cache for POST /upload. Lets a PWA that retries an
-- upload after a flaky network (200 lost, client never saw it) recover the
-- same upload_id instead of creating a silent duplicate on the filesystem.
--
-- Lifecycle:
--   processing -> row inserted before the actual storage logic runs
--   completed  -> row updated with upload_id + response_body once 2xx returned
--
-- A row in "processing" returns 409 on a concurrent retry (the client waits
-- retry_after_ms and tries again). A row in "completed" replays the original
-- 2xx response verbatim and does NOT re-touch the filesystem.
--
-- Scope is (idempotency_token, fk_user, entity): two different users that
-- happen to send the same UUID create independent uploads.
--
-- Cleanup (cron via SmartAuth::doScheduledJob):
--   - completed rows: retention 24h
--   - processing rows: retention 10min (orphans of killed processes)
-- =============================================================================
-- NOTE on column naming: we deliberately use idempotency_token instead of
-- idempotency_key because Dolibarr's SQLite SQL translator (used by the
-- integration tests) strips any ", KEY <ident> (<cols>)" fragment from
-- CREATE TABLE statements with a non-anchored regex on /KEY\s+\w+\s*\(/.
-- A column named *_key VARCHAR(64) ... matches that regex and gets its
-- type silently dropped, producing a corrupt schema. The data we store
-- IS the Idempotency-Key header value -- the column name is just an
-- internal label.
CREATE TABLE llx_smartauth_upload_idempotency (
    rowid             INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    idempotency_token VARCHAR(64) NOT NULL,
    fk_user           INTEGER NOT NULL,
    status          VARCHAR(16) NOT NULL,
    upload_id       VARCHAR(64) NULL,
    response_body   TEXT NULL,
    http_status     INTEGER NULL,
    created_at      DATETIME NOT NULL,
    completed_at    DATETIME NULL,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
