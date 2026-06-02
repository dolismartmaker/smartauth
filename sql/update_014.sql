-- Migration: add the `member` token subject (llx_adherent).
--
-- See documentation/SPEC_SMARTAUTH_SUBJECT.md. The token subject can now also
-- be an association member portal account (llx_adherent), alongside the
-- existing 'account' (llx_societe_account) and 'user' (llx_user) subjects.
--
-- subject_type 'member' -> fk_adherent holds llx_adherent.rowid; fk_user is set
-- to the sentinel 0 and fk_societe_account stays NULL (same NOT NULL strategy
-- as 'account' to avoid a MODIFY COLUMN, which SQLite does not support).
--
-- The column is nullable with no default: existing rows (all 'user'/'account'
-- subjects) keep fk_adherent NULL, so no backfill is needed.
ALTER TABLE llx_smartauth_oauth_codes ADD COLUMN fk_adherent INTEGER NULL DEFAULT NULL;

ALTER TABLE llx_smartauth_oauth_tokens ADD COLUMN fk_adherent INTEGER NULL DEFAULT NULL;
