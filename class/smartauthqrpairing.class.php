<?php

/**
 * smartauthqrpairing.class.php
 *
 * Tiny data-access helper for the llx_smartauth_qr_pairings table.
 *
 * Lifecycle (see sql/llx_smartauth_qr_pairings.sql):
 *   pending -> claimed -> confirmed -> consumed
 *           \-> cancelled
 *           \-> expired
 *
 * The PC side (Dolibarr session) creates pending rows and confirms them.
 * The mobile side (public HTTP) claims and polls a pairing_id, and finally
 * exchanges it for an access+refresh JWT pair when status reaches consumed.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

class SmartAuthQrPairing
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CLAIMED   = 'claimed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CONSUMED  = 'consumed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const PENDING_TTL_SECONDS = 60;   // before a mobile claim
    public const CLAIMED_TTL_SECONDS = 300;  // after claim, until consumed

    private const TABLE = 'smartauth_qr_pairings';

    /**
     * @var \DoliDB
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generate a fresh pairing_id (32 hex chars, 128 bits of entropy).
     */
    public static function generatePairingId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a fresh plain claim_token (43 chars base64url, 256 bits entropy).
     */
    public static function generateClaimToken(): string
    {
        $raw = random_bytes(32);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function hashClaimToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Insert a new pending pairing for a logged-in Dolibarr user.
     *
     * @return int rowid, or -1 on database failure
     */
    public function createPending(
        string $pairingId,
        int $fkUser,
        ?string $initiatorIp = null,
        int $entity = 1
    ): int {
        $now = dol_now();
        $expiresAt = $now + self::PENDING_TTL_SECONDS;

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " (pairing_id, fk_user, status, initiator_ip, expires_at, datec, entity)";
        $sql .= " VALUES ('" . $this->db->escape($pairingId) . "',";
        $sql .= " " . ((int) $fkUser) . ",";
        $sql .= " '" . $this->db->escape(self::STATUS_PENDING) . "',";
        $sql .= " " . ($initiatorIp !== null ? "'" . $this->db->escape($initiatorIp) . "'" : "NULL") . ",";
        $sql .= " '" . $this->db->idate($expiresAt) . "',";
        $sql .= " '" . $this->db->idate($now) . "',";
        $sql .= " " . ((int) $entity) . ")";

        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuthQrPairing: createPending failed: ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_ERR);
            return -1;
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . self::TABLE);
    }

    /**
     * Returns the most recent pending/claimed pairing for the given user
     * that has not yet expired. Used by user_tab.php to re-display the
     * existing QR after a page refresh instead of generating a fresh one
     * (which would invalidate any in-flight scan).
     *
     * @return array<string,mixed>|null
     */
    public function findActiveForUser(int $fkUser, int $entity = 1): ?array
    {
        $sql = "SELECT rowid, pairing_id, claim_token_hash, fk_user, status,";
        $sql .= " device_label, device_uuid_hash, initiator_ip, claim_ip,";
        $sql .= " claim_user_agent, expires_at, datec, confirmed_at, consumed_at, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE fk_user = " . ((int) $fkUser);
        $sql .= " AND entity = " . ((int) $entity);
        $sql .= " AND status IN ('" . self::STATUS_PENDING . "','" . self::STATUS_CLAIMED . "')";
        $sql .= " AND expires_at >= '" . $this->db->idate(dol_now()) . "'";
        $sql .= " ORDER BY datec DESC";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: findActiveForUser failed', LOG_ERR);
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'rowid' => (int) $obj->rowid,
            'pairing_id' => (string) $obj->pairing_id,
            'claim_token_hash' => $obj->claim_token_hash !== null ? (string) $obj->claim_token_hash : null,
            'fk_user' => (int) $obj->fk_user,
            'status' => (string) $obj->status,
            'device_label' => $obj->device_label !== null ? (string) $obj->device_label : null,
            'device_uuid_hash' => $obj->device_uuid_hash !== null ? (string) $obj->device_uuid_hash : null,
            'initiator_ip' => $obj->initiator_ip !== null ? (string) $obj->initiator_ip : null,
            'claim_ip' => $obj->claim_ip !== null ? (string) $obj->claim_ip : null,
            'claim_user_agent' => $obj->claim_user_agent !== null ? (string) $obj->claim_user_agent : null,
            'expires_at' => $obj->expires_at,
            'datec' => $obj->datec,
            'confirmed_at' => $obj->confirmed_at,
            'consumed_at' => $obj->consumed_at,
            'entity' => (int) $obj->entity,
        ];
    }

    /**
     * Find a row by pairing_id. Returns null if absent.
     *
     * @return array<string,mixed>|null
     */
    public function findByPairingId(string $pairingId, int $entity = 1): ?array
    {
        $sql = "SELECT rowid, pairing_id, claim_token_hash, fk_user, status,";
        $sql .= " device_label, device_uuid_hash, initiator_ip, claim_ip,";
        $sql .= " claim_user_agent, expires_at, datec, confirmed_at, consumed_at, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE pairing_id = '" . $this->db->escape($pairingId) . "'";
        $sql .= " AND entity = " . ((int) $entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: findByPairingId failed', LOG_ERR);
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return [
            'rowid' => (int) $obj->rowid,
            'pairing_id' => (string) $obj->pairing_id,
            'claim_token_hash' => $obj->claim_token_hash !== null ? (string) $obj->claim_token_hash : null,
            'fk_user' => (int) $obj->fk_user,
            'status' => (string) $obj->status,
            'device_label' => $obj->device_label !== null ? (string) $obj->device_label : null,
            'device_uuid_hash' => $obj->device_uuid_hash !== null ? (string) $obj->device_uuid_hash : null,
            'initiator_ip' => $obj->initiator_ip !== null ? (string) $obj->initiator_ip : null,
            'claim_ip' => $obj->claim_ip !== null ? (string) $obj->claim_ip : null,
            'claim_user_agent' => $obj->claim_user_agent !== null ? (string) $obj->claim_user_agent : null,
            'expires_at' => $obj->expires_at,
            'datec' => $obj->datec,
            'confirmed_at' => $obj->confirmed_at,
            'consumed_at' => $obj->consumed_at,
            'entity' => (int) $obj->entity,
        ];
    }

    /**
     * Mark a pending row as claimed by the mobile that scanned the QR.
     * Atomic update: succeeds only if the row is still pending and not expired.
     *
     * @return bool
     */
    public function markClaimed(
        int $rowid,
        string $claimTokenHash,
        ?string $deviceLabel,
        ?string $deviceUuidHash,
        ?string $claimIp,
        ?string $claimUserAgent
    ): bool {
        $now = dol_now();
        $newExpiresAt = $now + self::CLAIMED_TTL_SECONDS;

        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
        $sql .= " status = '" . $this->db->escape(self::STATUS_CLAIMED) . "',";
        $sql .= " claim_token_hash = '" . $this->db->escape($claimTokenHash) . "',";
        $sql .= " device_label = " . ($deviceLabel !== null ? "'" . $this->db->escape(substr($deviceLabel, 0, 128)) . "'" : "NULL") . ",";
        $sql .= " device_uuid_hash = " . ($deviceUuidHash !== null ? "'" . $this->db->escape($deviceUuidHash) . "'" : "NULL") . ",";
        $sql .= " claim_ip = " . ($claimIp !== null ? "'" . $this->db->escape($claimIp) . "'" : "NULL") . ",";
        $sql .= " claim_user_agent = " . ($claimUserAgent !== null ? "'" . $this->db->escape(substr($claimUserAgent, 0, 255)) . "'" : "NULL") . ",";
        $sql .= " expires_at = '" . $this->db->idate($newExpiresAt) . "'";
        $sql .= " WHERE rowid = " . ((int) $rowid);
        $sql .= " AND status = '" . $this->db->escape(self::STATUS_PENDING) . "'";
        $sql .= " AND expires_at >= '" . $this->db->idate($now) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: markClaimed failed', LOG_ERR);
            return false;
        }
        return ((int) $this->db->affected_rows($resql)) === 1;
    }

    /**
     * Mark a claimed row as confirmed by the PC user. Atomic.
     *
     * @return bool
     */
    public function markConfirmed(int $rowid, int $expectedFkUser): bool
    {
        $now = dol_now();

        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
        $sql .= " status = '" . $this->db->escape(self::STATUS_CONFIRMED) . "',";
        $sql .= " confirmed_at = '" . $this->db->idate($now) . "'";
        $sql .= " WHERE rowid = " . ((int) $rowid);
        $sql .= " AND fk_user = " . ((int) $expectedFkUser);
        $sql .= " AND status = '" . $this->db->escape(self::STATUS_CLAIMED) . "'";
        $sql .= " AND expires_at >= '" . $this->db->idate($now) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: markConfirmed failed', LOG_ERR);
            return false;
        }
        return ((int) $this->db->affected_rows($resql)) === 1;
    }

    /**
     * Mark a confirmed row as consumed (single-use) once tokens are issued.
     * Atomic update: succeeds only if the row is still confirmed.
     *
     * @return bool
     */
    public function markConsumed(int $rowid, int $expectedFkUser): bool
    {
        $now = dol_now();

        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
        $sql .= " status = '" . $this->db->escape(self::STATUS_CONSUMED) . "',";
        $sql .= " consumed_at = '" . $this->db->idate($now) . "'";
        $sql .= " WHERE rowid = " . ((int) $rowid);
        $sql .= " AND fk_user = " . ((int) $expectedFkUser);
        $sql .= " AND status = '" . $this->db->escape(self::STATUS_CONFIRMED) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: markConsumed failed', LOG_ERR);
            return false;
        }
        return ((int) $this->db->affected_rows($resql)) === 1;
    }

    /**
     * Mark a row as cancelled (PC user clicked "refuser") regardless of status.
     *
     * @return bool
     */
    public function markCancelled(int $rowid, int $expectedFkUser): bool
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
        $sql .= " status = '" . $this->db->escape(self::STATUS_CANCELLED) . "'";
        $sql .= " WHERE rowid = " . ((int) $rowid);
        $sql .= " AND fk_user = " . ((int) $expectedFkUser);
        $sql .= " AND status IN ('" . $this->db->escape(self::STATUS_PENDING) . "','" . $this->db->escape(self::STATUS_CLAIMED) . "','" . $this->db->escape(self::STATUS_CONFIRMED) . "')";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: markCancelled failed', LOG_ERR);
            return false;
        }
        return ((int) $this->db->affected_rows($resql)) >= 1;
    }

    /**
     * Returns true when expires_at has passed (ascii datetime comparison
     * works reliably with idate ISO format).
     *
     * @param array<string,mixed> $row
     */
    public static function isExpired(array $row): bool
    {
        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '') {
            return true;
        }
        $ts = is_numeric($expiresAt) ? (int) $expiresAt : strtotime((string) $expiresAt);
        if ($ts === false) {
            return true;
        }
        return $ts < dol_now();
    }

    /**
     * Hard-delete pairing rows older than $maxAgeSeconds (based on datec).
     *
     * Rows live a few minutes at most while a pairing is in flight; keeping
     * them around afterwards only serves a short audit window. This is
     * called from SmartAuth::doScheduledJob() so the table cannot grow
     * unbounded across years of usage.
     *
     * Default retention: 7 days (long enough to investigate any reported
     * pairing issue, short enough to keep the table small).
     *
     * @param int $maxAgeSeconds
     * @return int Number of rows deleted, or -1 on database failure
     */
    public function deleteOld(int $maxAgeSeconds = 604800): int
    {
        if ($maxAgeSeconds <= 0) {
            return 0;
        }
        $cutoff = dol_now() - $maxAgeSeconds;
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE datec < '" . $this->db->idate($cutoff) . "'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthQrPairing: deleteOld failed: ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_ERR);
            return -1;
        }
        return (int) $this->db->affected_rows($resql);
    }
}
