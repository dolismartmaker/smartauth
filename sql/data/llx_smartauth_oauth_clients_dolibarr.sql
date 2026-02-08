-- Copyright (C) 2024-2025 Eric Seigne <eric.seigne@cap-rel.fr>
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
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.

-- SmartAuth OAuth Client: Dolibarr ERP (internal)
-- This script creates the pre-configured OAuth client for Dolibarr authentication
--
-- IMPORTANT:
-- - Update redirect_uris with your actual Dolibarr URL before running
-- - This is a PUBLIC client (no secret required) that uses PKCE for security
-- - The client_id 'dolibarr-erp' must match SMARTAUTH_OAUTH_CLIENT_ID in Dolibarr config

-- Delete existing client if present (to allow re-running this script)
DELETE FROM llx_smartauth_oauth_clients WHERE client_id = 'dolibarr-erp';

-- Insert Dolibarr OAuth client
INSERT INTO llx_smartauth_oauth_clients (
    ref,
    client_id,
    client_secret,
    name,
    description,
    logo_url,
    redirect_uris,
    allowed_scopes,
    allowed_grants,
    is_confidential,
    require_pkce,
    access_token_lifetime,
    refresh_token_lifetime,
    status,
    fk_user_author,
    datec,
    entity
) VALUES (
    'DOLIBARR-INTERNAL',
    'dolibarr-erp',
    NULL,
    'Dolibarr ERP',
    'Internal OAuth client for Dolibarr authentication via SmartAuth. This client uses PKCE (Proof Key for Code Exchange) for secure authorization without a client secret.',
    NULL,
    '["https://erp.example.com/index.php", "https://localhost/dolibarr/index.php"]',
    '["openid", "profile", "email"]',
    '["authorization_code", "refresh_token"]',
    0,
    1,
    3600,
    2592000,
    1,
    1,
    NOW(),
    1
);

-- Note: After installation, update redirect_uris via admin interface or SQL:
-- UPDATE llx_smartauth_oauth_clients
-- SET redirect_uris = '["https://your-dolibarr-url.com/index.php"]'
-- WHERE client_id = 'dolibarr-erp';
