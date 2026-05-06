<?php

/**
 * EmailValidationToken.php
 *
 * Tiny data-access helper for the llx_smartauth_email_validation table.
 *
 * Tokens are produced as 32 random bytes (base64url encoded) and stored as
 * sha256(plain). The plain value is returned only once at creation time so
 * the caller can email it to the user.
 *
 * Purposes:
 *   - register       : confirm a freshly created account (24h TTL)
 *   - email_change   : confirm an alternative email per service (24h TTL)
 *   - password_reset : reserved for the future password reset flow
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

class EmailValidationToken
{
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_EMAIL_CHANGE = 'email_change';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    private const TABLE = 'smartauth_email_validation';

    /**
     * @var \DoliDB
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generate a fresh plain token (32 random bytes, base64url-encoded).
     *
     * @return string
     */
    public static function generatePlainToken(): string
    {
        $raw = random_bytes(32);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Hash a plain token for safe storage / lookup.
     *
     * @param string $plain
     * @return string
     */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Persist a new validation token for a user.
     *
     * Returns the row id of the inserted token, or -1 on database failure.
     *
     * @param int         $fkUser    User row id (the account being validated)
     * @param string      $purpose   Token purpose (one of the PURPOSE_* constants)
     * @param string      $tokenHash sha256 of the plain token
     * @param int         $ttl       Token lifetime in seconds
     * @param string|null $ip        Source IP (audit, optional)
     * @param array|null  $context   Free-form payload (e.g. ['continue' => '...'])
     * @param int         $entity    Dolibarr entity id (default 1)
     * @return int Row id, or -1 on error
     */
    public function create(
        int $fkUser,
        string $purpose,
        string $tokenHash,
        int $ttl,
        ?string $ip = null,
        ?array $context = null,
        int $entity = 1
    ): int {
        $now = dol_now();
        $expiresAt = $now + max(60, $ttl);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " (token_hash, fk_user, purpose, expires_at, used_at, ip_address, context, datec, entity)";
        $sql .= " VALUES ('" . $this->db->escape($tokenHash) . "',";
        $sql .= " " . ((int) $fkUser) . ",";
        $sql .= " '" . $this->db->escape($purpose) . "',";
        $sql .= " '" . $this->db->idate($expiresAt) . "',";
        $sql .= " NULL,";
        $sql .= " " . ($ip !== null ? "'" . $this->db->escape($ip) . "'" : "NULL") . ",";
        $sql .= " " . ($context !== null ? "'" . $this->db->escape(json_encode($context)) . "'" : "NULL") . ",";
        $sql .= " '" . $this->db->idate($now) . "',";
        $sql .= " " . ((int) $entity) . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth EmailValidationToken: insert failed: ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_ERR);
            return -1;
        }

        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . self::TABLE);
    }

    /**
     * Find an unused, non-expired token by hash + purpose.
     *
     * @param string $tokenHash sha256 of the plain token
     * @param string $purpose
     * @param int    $entity
     * @return array|null Row as associative array or null if not found / expired / consumed
     */
    public function findActive(string $tokenHash, string $purpose, int $entity = 1): ?array
    {
        $sql = "SELECT rowid, token_hash, fk_user, purpose, expires_at, used_at, context, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE token_hash = '" . $this->db->escape($tokenHash) . "'";
        $sql .= " AND purpose = '" . $this->db->escape($purpose) . "'";
        $sql .= " AND used_at IS NULL";
        $sql .= " AND expires_at > '" . $this->db->idate(dol_now()) . "'";
        $sql .= " AND entity = " . ((int) $entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth EmailValidationToken: lookup failed: ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_ERR);
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }

        return [
            'rowid' => (int) $obj->rowid,
            'token_hash' => (string) $obj->token_hash,
            'fk_user' => (int) $obj->fk_user,
            'purpose' => (string) $obj->purpose,
            'expires_at' => $obj->expires_at,
            'used_at' => $obj->used_at,
            'context' => $obj->context !== null ? (string) $obj->context : null,
            'entity' => (int) $obj->entity,
        ];
    }

    /**
     * Mark a token row as consumed.
     *
     * @param int $rowid
     * @return bool
     */
    public function markUsed(int $rowid): bool
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " SET used_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE rowid = " . ((int) $rowid);
        $sql .= " AND used_at IS NULL";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth EmailValidationToken: markUsed failed for rowid ' . $rowid, LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Invalidate every active token of a given purpose for a user (used when
     * resending a confirmation: the previous token is burned).
     *
     * @param int    $fkUser
     * @param string $purpose
     * @return int Number of tokens invalidated, or -1 on error
     */
    public function invalidateActiveForUser(int $fkUser, string $purpose): int
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " SET used_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE fk_user = " . ((int) $fkUser);
        $sql .= " AND purpose = '" . $this->db->escape($purpose) . "'";
        $sql .= " AND used_at IS NULL";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuth EmailValidationToken: invalidate failed', LOG_ERR);
            return -1;
        }
        return (int) $this->db->affected_rows($resql);
    }

    /**
     * Returns the most recently issued non-consumed token for (user, purpose).
     * Used by the resend flow to enforce a cooldown.
     *
     * @param int    $fkUser
     * @param string $purpose
     * @return int|null Unix timestamp of the latest datec, or null if none
     */
    public function lastActiveDatec(int $fkUser, string $purpose): ?int
    {
        $sql = "SELECT MAX(datec) AS last_datec";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE fk_user = " . ((int) $fkUser);
        $sql .= " AND purpose = '" . $this->db->escape($purpose) . "'";
        $sql .= " AND used_at IS NULL";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj || empty($obj->last_datec)) {
            return null;
        }
        return is_numeric($obj->last_datec) ? (int) $obj->last_datec : strtotime($obj->last_datec);
    }
}
