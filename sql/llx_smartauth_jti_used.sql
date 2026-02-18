-- Table to track used JWT IDs (jti) to prevent token replay attacks
-- The jti is marked as used BEFORE full token validation, making replay detection atomic

CREATE TABLE llx_smartauth_jti_used (
    jti VARCHAR(32) NOT NULL PRIMARY KEY,
    used_at INTEGER NOT NULL,
    token_id INTEGER DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
