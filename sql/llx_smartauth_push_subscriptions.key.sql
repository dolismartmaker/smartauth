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

-- Indexes and unique constraint for llx_smartauth_push_subscriptions.
-- The endpoint UNIQUE index uses a 500-char prefix (endpoint is TEXT). The
-- UPSERT logic does an explicit SELECT-then-INSERT/UPDATE and does NOT rely on
-- ON DUPLICATE KEY, so correctness does not depend on this index being created
-- (relevant on SQLite, which ignores the prefix length).
ALTER TABLE llx_smartauth_push_subscriptions ADD UNIQUE INDEX uk_push_endpoint (endpoint(500));
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_push_subject_user (subject_type, fk_user);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_push_subject_account (subject_type, fk_societe_account);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_push_subject_member (subject_type, fk_adherent);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_push_fk_device (fk_device);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_push_entity (entity);
ALTER TABLE llx_smartauth_push_subscriptions ADD INDEX idx_push_status (status);
