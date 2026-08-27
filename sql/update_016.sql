-- Migration: drop the llx_user foreign keys that contradict the fk_user = 0
-- sentinel of external subjects.
--
-- THE DEFECT. Since the subject refactor, a token subject is a (type, id)
-- couple: 'user' (llx_user), 'account' (llx_societe_account) or 'member'
-- (llx_adherent). External subjects carry their id in fk_societe_account /
-- fk_adherent and write the SENTINEL 0 into fk_user -- the column stays
-- NOT NULL because SQLite has no MODIFY COLUMN (see the header of
-- update_015.sql, which introduced the sentinel and is where this was missed).
--
-- But the .key.sql files still declared:
--     ADD CONSTRAINT fk_..._user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid)
--
-- On InnoDB, 0 is not NULL: it must match a llx_user row of rowid 0, which
-- never exists (AUTO_INCREMENT starts at 1). So on MySQL / MariaDB EVERY insert
-- of an acc:/mbr: row failed with error 1452, silently breaking self-service
-- registration, password reset and the whole OAuth2 flow for external subjects
-- -- the exact population the SSO door admits.
--
-- Invisible in the test suite: the SQLite harness does not load the .key.sql
-- files and runs with foreign_keys=OFF, so no constraint ever existed there.
--
-- ONLY these four are dropped. The three other fk_user foreign keys of the
-- module (llx_smartauth_qr_pairings, llx_smartauth_upload_idempotency,
-- llx_smartauth_user_devices) are KEPT on purpose: those tables carry no
-- subject_type column, they are user-only by design, and their constraint is a
-- genuine integrity rule.
--
-- A DROP on a constraint that was never created (fresh install after the
-- .key.sql fix, or a backend where it was not applied) reports an error and the
-- migration carries on -- the standard Dolibarr behaviour for update files.
ALTER TABLE llx_smartauth_email_validation DROP FOREIGN KEY fk_email_validation_user;
ALTER TABLE llx_smartauth_oauth_codes DROP FOREIGN KEY fk_oauth_code_user;
ALTER TABLE llx_smartauth_oauth_tokens DROP FOREIGN KEY fk_oauth_token_user;
ALTER TABLE llx_smartauth_oauth_consents DROP FOREIGN KEY fk_oauth_consent_user;
