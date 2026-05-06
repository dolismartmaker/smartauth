-- Add email validation table for /register, /register/resend, /email-alternative/confirm
CREATE TABLE IF NOT EXISTS llx_smartauth_email_validation (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    fk_user         INTEGER NOT NULL,
    purpose         VARCHAR(32) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    ip_address      VARCHAR(45),
    context         TEXT,
    datec           DATETIME NOT NULL,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=innodb DEFAULT CHARSET=utf8mb4;

ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_token_hash (token_hash);
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_fk_user (fk_user);
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_purpose (purpose);
ALTER TABLE llx_smartauth_email_validation ADD INDEX idx_expires_at (expires_at);
