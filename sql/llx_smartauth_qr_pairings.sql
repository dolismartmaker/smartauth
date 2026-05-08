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
-- Table: llx_smartauth_qr_pairings
--
-- Cross-device QR pairing rows used by the "Mon iPhone" / "Mon Android" flow
-- on /custom/smartauth/user/qrpair.php.
--
-- Lifecycle:
--   pending   -> row created server-side from the Dolibarr session (PC)
--   claimed   -> mobile scanned the QR, posted /qr-pair/{id}/claim
--   confirmed -> PC user pressed the "Autoriser" button on the tab page
--   consumed  -> mobile polled and exchanged the row for an access+refresh JWT
--   expired / cancelled -> terminal rejection states (no token ever issued)
--
-- The table holds NO clear secrets. claim_token is stored as sha256.
-- Pairings live at most a few minutes (TTL 60s pending, 300s once claimed).
-- =============================================================================
CREATE TABLE llx_smartauth_qr_pairings (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    pairing_id          VARCHAR(64) NOT NULL,
    claim_token_hash    VARCHAR(64) NULL,
    fk_user             INTEGER NOT NULL,
    status              VARCHAR(16) NOT NULL,
    device_label        VARCHAR(128) NULL,
    device_uuid_hash    VARCHAR(64) NULL,
    initiator_ip        VARCHAR(45) NULL,
    claim_ip            VARCHAR(45) NULL,
    claim_user_agent    VARCHAR(255) NULL,
    expires_at          DATETIME NOT NULL,
    datec               DATETIME NOT NULL,
    confirmed_at        DATETIME NULL,
    consumed_at         DATETIME NULL,
    entity              INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;
