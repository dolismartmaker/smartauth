<?php

/**
 * smartauthusertokenadmin.class.php
 *
 * Data-access helper for the token administration actions exposed on the
 * SmartAuth tab of the Dolibarr user card (user_tab.php).
 *
 * It encapsulates the four token operations the card offers so they can be
 * unit-tested in isolation, mirroring SmartAuthUserDevice on the logical
 * device side:
 *   - revoke()      : disable a token (status = STATUS_REVOKED), keep the row
 *   - delete()      : really remove the token row (hard delete)
 *   - massRevoke()  : revoke every selected token in one POST
 *   - massDelete()  : delete every selected token in one POST
 *
 * Every operation is ownership-checked: a token is only touched when its
 * fk_authid matches the user whose card is being edited. A forged id that
 * belongs to another user is skipped (logged at LOG_WARNING), never acted on.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

class SmartAuthUserTokenAdmin
{
    /** Token status meaning "revoked / logged out". Mirrors AuthController::STATUS_LOGOUT. */
    public const STATUS_REVOKED = 9;

    /** Salt marker written when a token is revoked from the user card. */
    public const SALT_REVOKED = 'revoked_by_user';

    /** Single-operation result: the token was acted on. */
    public const RES_OK = 1;

    /** Single-operation result: no token matched (absent or not owned). */
    public const RES_NOT_FOUND = 0;

    /** Single-operation result: a database error occurred. */
    public const RES_DB_ERROR = -1;

    private const AUTH_TABLE = 'smartauth_auth';

    private const FAMILY_TABLE = 'smartauth_token_family';

    /**
     * @var \DoliDB
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Ownership check: does this token row exist AND belong to $fkUser?
     */
    private function ownsToken(int $tokenId, int $fkUser): bool
    {
        if ($tokenId <= 0) {
            return false;
        }
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . self::AUTH_TABLE;
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $sql .= " AND fk_authid = " . (int) $fkUser;
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: ownership check failed: ' . $this->db->lasterror(), LOG_ERR);
            return false;
        }
        return $this->db->num_rows($resql) > 0;
    }

    /**
     * Family id of a token row, or 0 when the row carries none (legacy tokens
     * predate the family mechanism).
     */
    private function familyOfToken(int $tokenId): int
    {
        $sql = "SELECT family_id FROM " . MAIN_DB_PREFIX . self::AUTH_TABLE;
        $sql .= " WHERE rowid = " . (int) $tokenId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: family lookup failed on token #' . $tokenId . ' : ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        return is_object($obj) ? (int) $obj->family_id : 0;
    }

    /**
     * Revoke a whole token family: the family flag plus every token of the
     * family, in two flat UPDATEs.
     *
     * Revoking the single access-token row is NOT enough, and that gap is what
     * the user card promised to close: the matching refresh token stays valid,
     * so the device mints a new access token on its next /refresh and the
     * session the admin believes killed simply carries on. Same cascade as
     * SmartAuthUserDevice::revoke on the logical-device side.
     *
     * @return bool true when both updates went through
     */
    private function revokeFamily(int $familyId, int $fkUser): bool
    {
        $ok = true;

        $sql = "UPDATE " . MAIN_DB_PREFIX . self::FAMILY_TABLE;
        $sql .= " SET revoked = 1";
        $sql .= " WHERE rowid = " . (int) $familyId;
        if (!$this->db->query($sql)) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: family flag update failed for family #' . $familyId . ' : ' . $this->db->lasterror(), LOG_ERR);
            $ok = false;
        }

        // fk_authid scopes the cascade to the user whose card is open: a family
        // is per-user by construction, but a corrupted row must not let this
        // action reach another user's session.
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::AUTH_TABLE;
        $sql .= " SET status = " . self::STATUS_REVOKED . ", salt = '" . self::SALT_REVOKED . "'";
        $sql .= " WHERE family_id = " . (int) $familyId;
        $sql .= " AND fk_authid = " . (int) $fkUser;
        if (!$this->db->query($sql)) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: family token update failed for family #' . $familyId . ' : ' . $this->db->lasterror(), LOG_ERR);
            $ok = false;
        }

        return $ok;
    }

    /**
     * Revoke a single token (disable it: status = STATUS_REVOKED), keeping the
     * row. When the token belongs to a family, the whole family goes with it --
     * otherwise the session survives through /refresh. Ownership-checked.
     *
     * @return int RES_OK | RES_NOT_FOUND | RES_DB_ERROR
     */
    public function revoke(int $tokenId, int $fkUser): int
    {
        if (!$this->ownsToken($tokenId, $fkUser)) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: revoke skipped, token #' . $tokenId . ' not owned by user ' . $fkUser, LOG_WARNING);
            return self::RES_NOT_FOUND;
        }

        $familyId = $this->familyOfToken($tokenId);
        if ($familyId > 0) {
            if (!$this->revokeFamily($familyId, $fkUser)) {
                return self::RES_DB_ERROR;
            }
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: token #' . $tokenId . ' revoked with its family #' . $familyId . ' (user ' . $fkUser . ')', LOG_INFO);
            return self::RES_OK;
        }

        // Legacy token with no family: revoke the row alone.
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::AUTH_TABLE;
        $sql .= " SET status = " . self::STATUS_REVOKED . ", salt = '" . self::SALT_REVOKED . "'";
        $sql .= " WHERE rowid = " . (int) $tokenId;
        if (!$this->db->query($sql)) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: revoke failed on token #' . $tokenId . ' : ' . $this->db->lasterror(), LOG_ERR);
            return self::RES_DB_ERROR;
        }
        return self::RES_OK;
    }

    /**
     * Hard-delete a single token row (distinct from revoke which only flips the
     * status). Ownership-checked.
     *
     * @return int RES_OK | RES_NOT_FOUND | RES_DB_ERROR
     */
    public function delete(int $tokenId, int $fkUser): int
    {
        if (!$this->ownsToken($tokenId, $fkUser)) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: delete skipped, token #' . $tokenId . ' not owned by user ' . $fkUser, LOG_WARNING);
            return self::RES_NOT_FOUND;
        }
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . self::AUTH_TABLE;
        $sql .= " WHERE rowid = " . (int) $tokenId;
        if (!$this->db->query($sql)) {
            dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: delete failed on token #' . $tokenId . ' : ' . $this->db->lasterror(), LOG_ERR);
            return self::RES_DB_ERROR;
        }
        return self::RES_OK;
    }

    /**
     * Revoke every selected token in one pass. Ownership enforced per row, so a
     * forged id belonging to another user is silently skipped.
     *
     * @param array<int|string> $tokenIds
     * @return int number of tokens actually revoked
     */
    public function massRevoke(array $tokenIds, int $fkUser): int
    {
        return $this->massApply($tokenIds, $fkUser, false);
    }

    /**
     * Hard-delete every selected token in one pass. Ownership enforced per row.
     *
     * @param array<int|string> $tokenIds
     * @return int number of tokens actually deleted
     */
    public function massDelete(array $tokenIds, int $fkUser): int
    {
        return $this->massApply($tokenIds, $fkUser, true);
    }

    /**
     * Shared mass-action loop. Iterates the selection, enforces ownership per
     * row, then revokes or deletes. Returns the number of rows acted on.
     *
     * @param array<int|string> $tokenIds
     */
    private function massApply(array $tokenIds, int $fkUser, bool $hardDelete): int
    {
        $done = 0;
        foreach ($tokenIds as $one) {
            $one = (int) $one;
            if ($one <= 0) {
                continue;
            }
            if (!$this->ownsToken($one, $fkUser)) {
                dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: mass action skipped, token #' . $one . ' not owned by user ' . $fkUser, LOG_WARNING);
                continue;
            }
            if (!$hardDelete) {
                // Same family cascade as revoke(): a mass revocation that left
                // the refresh tokens alive would be even more misleading, since
                // it is the action an admin uses to cut off a lost device.
                $familyId = $this->familyOfToken($one);
                if ($familyId > 0) {
                    if ($this->revokeFamily($familyId, $fkUser)) {
                        $done++;
                    }
                    continue;
                }
            }

            if ($hardDelete) {
                $sql = "DELETE FROM " . MAIN_DB_PREFIX . self::AUTH_TABLE . " WHERE rowid = " . $one;
            } else {
                $sql = "UPDATE " . MAIN_DB_PREFIX . self::AUTH_TABLE;
                $sql .= " SET status = " . self::STATUS_REVOKED . ", salt = '" . self::SALT_REVOKED . "'";
                $sql .= " WHERE rowid = " . $one;
            }
            if ($this->db->query($sql)) {
                $done++;
            } else {
                dol_syslog('[SmartAuth] SmartAuthUserTokenAdmin: mass ' . ($hardDelete ? 'delete' : 'revoke') . ' failed on token #' . $one . ' : ' . $this->db->lasterror(), LOG_ERR);
            }
        }
        return $done;
    }
}
