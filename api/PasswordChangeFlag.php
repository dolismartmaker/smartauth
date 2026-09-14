<?php

/**
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Api;

/**
 * Persistent "this user still has to change its password" marker, stored as a
 * per-user Dolibarr parameter (llx_user_param).
 *
 * Why a marker instead of reading llx_user.datepreviouslogin on every login:
 * that column is only refreshed by the Dolibarr WEB interface, so an account
 * used exclusively through a PWA kept a NULL datepreviouslogin forever and was
 * asked to change its password at EVERY login, changing it or not. The date
 * answers "has this user ever logged in", never "has this user ever changed the
 * password the admin gave them" - which is the actual question.
 *
 * The marker is raised on the genuine first login, and only cleared when the
 * password is really changed. It therefore survives a logout/login round trip:
 * a user cannot dodge the requirement by simply reconnecting. Accounts that
 * already logged in before this code existed carry no marker and are never
 * bothered.
 */
class PasswordChangeFlag
{
    /**
     * Key used in llx_user_param.
     */
    const PARAM = 'SMARTAUTH_MUST_CHANGE_PASSWORD';

    /**
     * Is the user still required to change its password?
     *
     * @param \DoliDB $db     Database handler
     * @param int     $userId Target user id
     * @param int     $entity Entity the user belongs to
     * @return bool true when the marker is raised
     */
    public static function isRequired($db, int $userId, int $entity): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "user_param";
        $sql .= " WHERE fk_user = " . ((int) $userId);
        $sql .= " AND entity = " . ((int) $entity);
        $sql .= " AND param = '" . $db->escape(self::PARAM) . "'";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('[SmartAuth] PasswordChangeFlag::isRequired - SQL error: ' . $db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $db->fetch_object($resql);
        $db->free($resql);

        return !empty($obj) && !empty($obj->value);
    }

    /**
     * Raise the marker. Idempotent: re-raising it on an already flagged user
     * leaves a single row.
     *
     * @param \DoliDB $db     Database handler
     * @param int     $userId Target user id
     * @param int     $entity Entity the user belongs to
     * @return bool true on success
     */
    public static function markRequired($db, int $userId, int $entity): bool
    {
        if ($userId <= 0) {
            dol_syslog('[SmartAuth] PasswordChangeFlag::markRequired - refused, invalid user id', LOG_ERR);
            return false;
        }

        if (self::isRequired($db, $userId, $entity)) {
            return true;
        }

        if (!self::deleteMarker($db, $userId, $entity)) {
            return false;
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "user_param(fk_user, entity, param, value)";
        $sql .= " VALUES (" . ((int) $userId) . ", " . ((int) $entity) . ",";
        $sql .= " '" . $db->escape(self::PARAM) . "', '1')";

        if (!$db->query($sql)) {
            dol_syslog('[SmartAuth] PasswordChangeFlag::markRequired - SQL error: ' . $db->lasterror(), LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Drop the marker: the user changed its password, stop asking.
     *
     * @param \DoliDB $db     Database handler
     * @param int     $userId Target user id
     * @param int     $entity Entity the user belongs to
     * @return bool true on success
     */
    public static function clear($db, int $userId, int $entity): bool
    {
        if ($userId <= 0) {
            dol_syslog('[SmartAuth] PasswordChangeFlag::clear - refused, invalid user id', LOG_ERR);
            return false;
        }

        return self::deleteMarker($db, $userId, $entity);
    }

    /**
     * Remove any marker row for this (user, entity) pair.
     *
     * @param \DoliDB $db     Database handler
     * @param int     $userId Target user id
     * @param int     $entity Entity the user belongs to
     * @return bool true on success
     */
    private static function deleteMarker($db, int $userId, int $entity): bool
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "user_param";
        $sql .= " WHERE fk_user = " . ((int) $userId);
        $sql .= " AND entity = " . ((int) $entity);
        $sql .= " AND param = '" . $db->escape(self::PARAM) . "'";

        if (!$db->query($sql)) {
            dol_syslog('[SmartAuth] PasswordChangeFlag - SQL error while deleting marker: ' . $db->lasterror(), LOG_ERR);
            return false;
        }

        return true;
    }
}
