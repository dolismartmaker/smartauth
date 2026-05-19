-- Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU Affero General Public License as
-- published by the Free Software Foundation, either version 3 of the
-- License, or (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU Affero General Public License for more details.
--
-- You should have received a copy of the GNU Affero General Public License
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.

-- idx_revoked_at: drives the GET /oauth/revoked-jti?since=<ts> filter, the
-- single hot query of the table (called every 10 minutes by every backend).
ALTER TABLE llx_smartauth_revoked_jti ADD INDEX idx_revoked_at (revoked_at);

-- idx_expires_at: drives the purge query (DELETE WHERE expires_at < NOW()).
ALTER TABLE llx_smartauth_revoked_jti ADD INDEX idx_expires_at (expires_at);

-- idx_entity: standard Dolibarr multi-entity filter.
ALTER TABLE llx_smartauth_revoked_jti ADD INDEX idx_entity (entity);
