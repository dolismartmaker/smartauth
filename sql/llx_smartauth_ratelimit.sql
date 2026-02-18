CREATE TABLE llx_smartauth_ratelimit (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    identifier varchar(255) NOT NULL,
    action varchar(50) NOT NULL,
    attempt_time integer NOT NULL,
    success tinyint(1) DEFAULT 0
) ENGINE=innodb;

