
-- detect token replay attacks
CREATE TABLE llx_smartauth_token_family (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_user INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    last_refresh_at INTEGER NOT NULL,
    refresh_count INTEGER DEFAULT 0,
    revoked TINYINT(1) DEFAULT 0
) ENGINE=innodb;


