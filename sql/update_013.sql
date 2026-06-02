-- Migration: abstract token subject (account + user).
--
-- See documentation/SPEC_SMARTAUTH_SUBJECT.md. The token subject is no longer
-- always a Dolibarr user: it becomes a couple (subject_type, id) so that a
-- billed client's portal account (llx_societe_account) can be the subject, with
-- llx_user kept as the exception (internal staff).
--
-- subject_type discriminates which id column is meaningful:
--   'user'    -> fk_user holds llx_user.rowid       (default, backfilled here)
--   'account' -> fk_societe_account holds llx_societe_account.rowid; fk_user is
--                set to the sentinel 0.
--
-- fk_user stays NOT NULL on purpose: that avoids a MODIFY COLUMN nullability
-- change, which SQLite does not support without a full table rebuild. The
-- 'account' case uses fk_user = 0 instead of NULL. Existing rows are all
-- 'user' subjects; the DEFAULT 'user' on the new column backfills them.
ALTER TABLE llx_smartauth_oauth_codes ADD COLUMN subject_type VARCHAR(16) DEFAULT 'user' NOT NULL;
ALTER TABLE llx_smartauth_oauth_codes ADD COLUMN fk_societe_account INTEGER NULL DEFAULT NULL;

ALTER TABLE llx_smartauth_oauth_tokens ADD COLUMN subject_type VARCHAR(16) DEFAULT 'user' NOT NULL;
ALTER TABLE llx_smartauth_oauth_tokens ADD COLUMN fk_societe_account INTEGER NULL DEFAULT NULL;
