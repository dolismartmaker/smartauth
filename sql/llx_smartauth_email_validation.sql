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
-- Table: llx_smartauth_email_validation
--
-- Stores time-limited tokens used to validate ownership of an email address.
-- Created when:
--   - a new user submits the public /register form (purpose='register')
--   - a connected user adds an alternative email per service (purpose='email_change')
--   - the password-reset flow generates a token (purpose='password_reset')
--
-- Tokens are stored as sha256(plain) and never in clear text. Single-use:
-- consumed via used_at = NOW(). Default TTL is 24h, configurable via
-- SMARTAUTH_REGISTER_TOKEN_TTL.
-- =============================================================================
CREATE TABLE llx_smartauth_email_validation (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    -- Subject identity (same model as the oauth tables). subject_type
    -- discriminates which id column is meaningful:
    --   'user'    -> fk_user holds llx_user.rowid (default).
    --   'account' -> fk_societe_account holds llx_societe_account.rowid, fk_user = 0.
    --   'member'  -> fk_adherent holds llx_adherent.rowid, fk_user = 0.
    -- register / email_change tokens are always 'user'; password_reset tokens
    -- can target any of the three.
    fk_user         INTEGER NOT NULL,
    subject_type    VARCHAR(16) DEFAULT 'user' NOT NULL,
    fk_societe_account INTEGER NULL DEFAULT NULL,
    fk_adherent     INTEGER NULL DEFAULT NULL,
    purpose         VARCHAR(32) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    ip_address      VARCHAR(45),
    context         TEXT,
    datec           DATETIME NOT NULL,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
