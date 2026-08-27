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
-- Indexes and Foreign Keys for llx_smartauth_email_validation
-- =============================================================================
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_token_hash (token_hash);
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_fk_user (fk_user);
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_purpose (purpose);
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_expires_at (expires_at);
-- NO foreign key on fk_user, deliberately. An external subject (account /
-- member) stores its id in fk_societe_account / fk_adherent and writes the
-- SENTINEL 0 here, the column being NOT NULL. On InnoDB, 0 is not NULL: the
-- constraint required a llx_user row of rowid 0, which never exists, so every
-- acc:/mbr: insert failed with error 1452. Dropped for existing installs by
-- sql/update_016.sql. Do not restore it without first making fk_user nullable
-- and writing NULL instead of 0 for external subjects.
