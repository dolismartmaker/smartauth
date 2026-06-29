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

-- Real concurrency lock under MySQL/PG: a parallel replay that slips past the
-- application-level pre-check (findServerId) fails cleanly on this index.
-- Dolibarr's SQLite translator used in tests does not load this file, so the
-- application pre-check is the only guard there (sequential retry path).
ALTER TABLE llx_smartauth_sync_idempotency ADD UNIQUE INDEX uk_sync_idempotency (client_uuid, temp_id, object_type, entity);
ALTER TABLE llx_smartauth_sync_idempotency ADD INDEX idx_sync_idempotency_created (created_at);
