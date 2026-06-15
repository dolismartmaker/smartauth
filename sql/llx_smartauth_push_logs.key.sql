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

-- Indexes for llx_smartauth_push_logs.
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_subject (subject_type, fk_user);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_subject_account (subject_type, fk_societe_account);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_subject_member (subject_type, fk_adherent);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_subscription (fk_subscription);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_type (notification_type);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_success (success);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_entity (entity);
ALTER TABLE llx_smartauth_push_logs ADD INDEX idx_pushlog_date (date_creation);
