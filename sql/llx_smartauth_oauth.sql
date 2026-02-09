-- Copyright (C) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
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
-- OAuth2/OIDC Tables for SmartAuth Identity Provider
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Table: llx_smartauth_oauth_clients
-- Stores registered OAuth2 client applications (Nextcloud, Dolibarr, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE llx_smartauth_oauth_clients (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    ref                 VARCHAR(128) NOT NULL,
    client_id           VARCHAR(80) NOT NULL,
    client_secret       VARCHAR(255),
    name                VARCHAR(255) NOT NULL,
    description         TEXT,
    logo_url            VARCHAR(2048),

    -- Redirect URIs (JSON array)
    redirect_uris       TEXT NOT NULL,

    -- Permissions (JSON arrays)
    allowed_scopes      TEXT NOT NULL,
    allowed_grants      TEXT NOT NULL,

    -- Client type
    is_confidential     TINYINT(1) DEFAULT 1,
    require_pkce        TINYINT(1) DEFAULT 0,

    -- Token lifetimes (seconds)
    access_token_lifetime   INTEGER DEFAULT 3600,
    refresh_token_lifetime  INTEGER DEFAULT 2592000,

    -- Metadata
    status              TINYINT(1) DEFAULT 1,
    fk_user_author      INTEGER,
    fk_user_modif       INTEGER,
    datec               DATETIME NOT NULL,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    entity              INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Table: llx_smartauth_oauth_codes
-- Stores temporary authorization codes (TTL ~10 minutes)
-- -----------------------------------------------------------------------------
CREATE TABLE llx_smartauth_oauth_codes (
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    code_hash               VARCHAR(255) NOT NULL,

    -- Relations
    fk_client               INTEGER NOT NULL,
    fk_user                 INTEGER NOT NULL,

    -- Request parameters
    redirect_uri            VARCHAR(2048) NOT NULL,
    scopes                  TEXT NOT NULL,
    state                   VARCHAR(255),
    nonce                   VARCHAR(255),

    -- PKCE
    code_challenge          VARCHAR(128),
    code_challenge_method   VARCHAR(10),

    -- Lifecycle
    expires_at              DATETIME NOT NULL,
    used_at                 DATETIME,

    datec                   DATETIME NOT NULL,
    entity                  INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Table: llx_smartauth_oauth_tokens
-- Stores issued tokens (access and refresh)
-- -----------------------------------------------------------------------------
CREATE TABLE llx_smartauth_oauth_tokens (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    token_hash          VARCHAR(255) NOT NULL,
    token_type          VARCHAR(20) NOT NULL,

    -- Relations
    fk_client           INTEGER NOT NULL,
    fk_user             INTEGER NOT NULL,

    -- Token data
    scopes              TEXT NOT NULL,
    jti                 VARCHAR(64),

    -- Lifecycle
    expires_at          DATETIME NOT NULL,
    revoked_at          DATETIME,

    -- Traceability
    fk_parent           INTEGER,
    ip_address          VARCHAR(45),
    user_agent          VARCHAR(512),

    datec               DATETIME NOT NULL,
    entity              INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Table: llx_smartauth_oauth_consents
-- Stores user consents to avoid re-prompting
-- -----------------------------------------------------------------------------
CREATE TABLE llx_smartauth_oauth_consents (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,

    -- Relations
    fk_client           INTEGER NOT NULL,
    fk_user             INTEGER NOT NULL,

    -- Granted scopes (JSON array)
    scopes              TEXT NOT NULL,

    -- Lifecycle
    granted_at          DATETIME NOT NULL,
    revoked_at          DATETIME,

    entity              INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
