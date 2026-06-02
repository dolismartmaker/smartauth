-- Migration: make email-validation tokens subject-aware (account/member/user).
--
-- The password-reset flow no longer stores its token in llx_user.pass_temp; it
-- stores it here, keyed by the token subject, so a reset works uniformly for a
-- Dolibarr user, a portal account (llx_societe_account) or a member
-- (llx_adherent). register / email_change tokens stay 'user'.
--
-- Same NOT NULL strategy as the oauth tables: fk_user stays NOT NULL and holds
-- the sentinel 0 for external subjects (no MODIFY COLUMN, which SQLite lacks).
-- The new columns are nullable with no default, so existing rows (all 'user'
-- via the DEFAULT 'user' on subject_type) need no backfill.
ALTER TABLE llx_smartauth_email_validation ADD COLUMN subject_type VARCHAR(16) DEFAULT 'user' NOT NULL;
ALTER TABLE llx_smartauth_email_validation ADD COLUMN fk_societe_account INTEGER NULL DEFAULT NULL;
ALTER TABLE llx_smartauth_email_validation ADD COLUMN fk_adherent INTEGER NULL DEFAULT NULL;
