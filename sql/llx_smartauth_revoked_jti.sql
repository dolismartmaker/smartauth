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

-- =============================================================================
-- Table: llx_smartauth_revoked_jti
-- Published list of JWT IDs (jti) that resource servers (capTodo, capCRM, etc.)
-- must reject before token expiry. Polled by backends via GET /oauth/revoked-jti
-- on a short cycle (10 minutes nominal) so a contract closure or credential
-- compromise propagates faster than the TTL window. See PERFS.md §3.4.
--
-- Rows are removed once expires_at < NOW(): past that point the JWT is already
-- refused by the standard exp-claim check, keeping the table small.
-- =============================================================================
CREATE TABLE llx_smartauth_revoked_jti (
    jti             VARCHAR(64) NOT NULL PRIMARY KEY,
    revoked_at      DATETIME NOT NULL,
    expires_at      DATETIME NOT NULL,
    reason          VARCHAR(64) NOT NULL DEFAULT '',
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
