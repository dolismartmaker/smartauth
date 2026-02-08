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
-- Indexes and Foreign Keys for OAuth2/OIDC Tables
-- =============================================================================

-- -----------------------------------------------------------------------------
-- llx_smartauth_oauth_clients
-- -----------------------------------------------------------------------------
ALTER TABLE llx_smartauth_oauth_clients ADD UNIQUE INDEX uk_client_id (client_id);
ALTER TABLE llx_smartauth_oauth_clients ADD INDEX idx_entity (entity);
ALTER TABLE llx_smartauth_oauth_clients ADD INDEX idx_status (status);

-- -----------------------------------------------------------------------------
-- llx_smartauth_oauth_codes
-- -----------------------------------------------------------------------------
ALTER TABLE llx_smartauth_oauth_codes ADD UNIQUE INDEX uk_code_hash (code_hash);
ALTER TABLE llx_smartauth_oauth_codes ADD INDEX idx_fk_client (fk_client);
ALTER TABLE llx_smartauth_oauth_codes ADD INDEX idx_fk_user (fk_user);
ALTER TABLE llx_smartauth_oauth_codes ADD INDEX idx_expires (expires_at);
ALTER TABLE llx_smartauth_oauth_codes ADD CONSTRAINT fk_oauth_code_client FOREIGN KEY (fk_client) REFERENCES llx_smartauth_oauth_clients(rowid) ON DELETE CASCADE;
ALTER TABLE llx_smartauth_oauth_codes ADD CONSTRAINT fk_oauth_code_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;

-- -----------------------------------------------------------------------------
-- llx_smartauth_oauth_tokens
-- -----------------------------------------------------------------------------
ALTER TABLE llx_smartauth_oauth_tokens ADD UNIQUE INDEX uk_token_hash (token_hash);
ALTER TABLE llx_smartauth_oauth_tokens ADD INDEX idx_fk_client (fk_client);
ALTER TABLE llx_smartauth_oauth_tokens ADD INDEX idx_fk_user (fk_user);
ALTER TABLE llx_smartauth_oauth_tokens ADD INDEX idx_token_type (token_type);
ALTER TABLE llx_smartauth_oauth_tokens ADD INDEX idx_expires (expires_at);
ALTER TABLE llx_smartauth_oauth_tokens ADD INDEX idx_jti (jti);
ALTER TABLE llx_smartauth_oauth_tokens ADD CONSTRAINT fk_oauth_token_client FOREIGN KEY (fk_client) REFERENCES llx_smartauth_oauth_clients(rowid) ON DELETE CASCADE;
ALTER TABLE llx_smartauth_oauth_tokens ADD CONSTRAINT fk_oauth_token_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;

-- -----------------------------------------------------------------------------
-- llx_smartauth_oauth_consents
-- -----------------------------------------------------------------------------
ALTER TABLE llx_smartauth_oauth_consents ADD UNIQUE INDEX uk_client_user (fk_client, fk_user, entity);
ALTER TABLE llx_smartauth_oauth_consents ADD INDEX idx_fk_user (fk_user);
ALTER TABLE llx_smartauth_oauth_consents ADD CONSTRAINT fk_oauth_consent_client FOREIGN KEY (fk_client) REFERENCES llx_smartauth_oauth_clients(rowid) ON DELETE CASCADE;
ALTER TABLE llx_smartauth_oauth_consents ADD CONSTRAINT fk_oauth_consent_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;
