-- Migration: add per-user idempotency cache for POST /upload.
--
-- Lets a PWA recover the original upload_id when retrying an upload after
-- a 2xx response was lost on the wire, instead of creating a duplicate
-- file on the filesystem.
--
-- See sql/llx_smartauth_upload_idempotency.sql for the column rationale.
CREATE TABLE llx_smartauth_upload_idempotency (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    idempotency_token VARCHAR(64) NOT NULL,
    fk_user         INTEGER NOT NULL,
    status          VARCHAR(16) NOT NULL,
    upload_id       VARCHAR(64) NULL,
    response_body   TEXT NULL,
    http_status     INTEGER NULL,
    created_at      DATETIME NOT NULL,
    completed_at    DATETIME NULL,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

ALTER TABLE llx_smartauth_upload_idempotency ADD UNIQUE INDEX uk_upload_idempotency_token_user (idempotency_token, fk_user, entity);
ALTER TABLE llx_smartauth_upload_idempotency ADD INDEX idx_upload_idempotency_status (status);
ALTER TABLE llx_smartauth_upload_idempotency ADD INDEX idx_upload_idempotency_created (created_at);
ALTER TABLE llx_smartauth_upload_idempotency ADD CONSTRAINT fk_upload_idempotency_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;
