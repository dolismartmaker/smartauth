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
-- Table: llx_smartauth_sync_idempotency
--
-- Idempotency cache for the 'create' action of POST /sync/push. When a client
-- retries a push after a 2xx response was lost on the wire (flaky network,
-- app killed before the id_mapping was persisted), the create is recognised by
-- (client_uuid, temp_id, object_type) and the original server_id is replayed
-- instead of inserting a duplicate object.
--
-- Only creates need this: updates carry base_tms (optimistic concurrency) and
-- deletes are idempotent by id.
--
-- Cleanup (cron via SmartAuth::doScheduledJob): rows older than 24h are dropped
-- (a client that has not reconciled a create within 24h has given up on it).
--
-- NOTE on column naming: we avoid any column named *_key because Dolibarr's
-- SQLite SQL translator (used by the integration tests) strips a ", KEY <ident>
-- (<cols>)" fragment with a non-anchored regex that also matches a "<name>_key
-- VARCHAR(64)" column, silently dropping its type. temp_id / object_type are
-- safe labels for the same data.
-- =============================================================================
CREATE TABLE llx_smartauth_sync_idempotency (
    rowid         INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    client_uuid   VARCHAR(64) NOT NULL,
    temp_id       VARCHAR(64) NOT NULL,
    object_type   VARCHAR(64) NOT NULL,
    server_id     INTEGER NOT NULL,
    fk_user       INTEGER NOT NULL,
    created_at    DATETIME NOT NULL,
    entity        INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
