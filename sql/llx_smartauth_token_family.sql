
-- detect token replay attacks
CREATE TABLE llx_smartauth_token_family (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    family_id VARCHAR(64) NOT NULL UNIQUE,
    fk_user INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    last_refresh_at INTEGER NOT NULL,
    refresh_count INTEGER DEFAULT 0,
    revoked TINYINT(1) DEFAULT 0,
    INDEX idx_family_id (family_id),
    INDEX idx_fk_user (fk_user)
) ENGINE=innodb;


