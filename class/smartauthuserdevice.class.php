<?php

/**
 * smartauthuserdevice.class.php
 *
 * Data-access helper for the llx_smartauth_user_devices table.
 *
 * A "user device" is the logical "physical device" of an end-user, e.g.
 * "mon iPhone". It groups several technical rows of llx_smartauth_devices
 * (one per PWA installed on the same device) so the user only sees a
 * single line in Dolibarr and can revoke all sessions on that device in
 * one click when they lose the phone.
 *
 * The grouping is opt-in: a smartauth_devices row whose fk_user_device is
 * NULL behaves exactly like before this refactor (legacy / not yet sorted
 * by the user). It just does not benefit from the cascade-revoke or the
 * cross-PWA picker.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

class SmartAuthUserDevice
{
    public const STATUS_ACTIVE  = 1;
    public const STATUS_REVOKED = 9;

    public const ICON_PHONE   = 'phone';
    public const ICON_TABLET  = 'tablet';
    public const ICON_LAPTOP  = 'laptop';
    public const ICON_DESKTOP = 'desktop';

    public const LABEL_MAX_LENGTH = 100;

    private const TABLE = 'smartauth_user_devices';
    private const DEVICES_TABLE = 'smartauth_devices';
    private const AUTH_TABLE = 'smartauth_auth';
    private const FAMILY_TABLE = 'smartauth_token_family';

    /**
     * Status value used on llx_smartauth_devices to mark a row canceled.
     * Mirrors SmartAuthDevices::STATUS_CANCELED (=9) so we do not have to
     * load the heavy CommonObject class here.
     */
    private const DEVICE_STATUS_CANCELED = 9;

    /**
     * Status value used on llx_smartauth_auth to mark a token logged out.
     * Mirrors AuthController::STATUS_LOGOUT (=9).
     */
    private const AUTH_STATUS_LOGOUT = 9;

    /**
     * @var \DoliDB
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Allowed icon set. Anything else is normalised to ICON_PHONE.
     *
     * @return string[]
     */
    public static function allowedIcons(): array
    {
        return [self::ICON_PHONE, self::ICON_TABLET, self::ICON_LAPTOP, self::ICON_DESKTOP];
    }

    /**
     * Normalise a label: trim, collapse internal whitespace, cap to
     * LABEL_MAX_LENGTH. Returns empty string if the result is empty
     * (caller decides how to handle).
     */
    public static function normaliseLabel(string $raw): string
    {
        $label = preg_replace('/\s+/', ' ', trim($raw));
        if ($label === null) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($label, 0, self::LABEL_MAX_LENGTH);
        }
        return substr($label, 0, self::LABEL_MAX_LENGTH);
    }

    /**
     * Normalise an icon to an allowed value, defaulting to ICON_PHONE.
     */
    public static function normaliseIcon(string $raw): string
    {
        $icon = strtolower(trim($raw));
        return in_array($icon, self::allowedIcons(), true) ? $icon : self::ICON_PHONE;
    }

    /**
     * Insert a new logical device. Returns the rowid on success, -1 on
     * database failure, -2 on uniqueness violation (label already taken
     * by this user in this entity).
     */
    public function create(int $fkUser, string $label, string $icon, int $entity = 1): int
    {
        $label = self::normaliseLabel($label);
        if ($label === '') {
            return -3;
        }
        $icon = self::normaliseIcon($icon);

        if ($this->findByLabel($fkUser, $label, $entity) !== null) {
            return -2;
        }

        $now = dol_now();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " (fk_user, label, icon, date_creation, date_lastseen, status, entity)";
        $sql .= " VALUES (";
        $sql .= (int) $fkUser . ",";
        $sql .= " '" . $this->db->escape($label) . "',";
        $sql .= " '" . $this->db->escape($icon) . "',";
        $sql .= " '" . $this->db->idate($now) . "',";
        $sql .= " '" . $this->db->idate($now) . "',";
        $sql .= " " . self::STATUS_ACTIVE . ",";
        $sql .= " " . (int) $entity;
        $sql .= ")";

        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuthUserDevice: create failed: ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_ERR);
            return -1;
        }
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . self::TABLE);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $rowid, int $entity = 1): ?array
    {
        $sql = "SELECT rowid, fk_user, label, icon, date_creation, date_lastseen, status, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE rowid = " . (int) $rowid;
        $sql .= " AND entity = " . (int) $entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUserDevice: findById failed', LOG_ERR);
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return $this->hydrate($obj);
    }

    /**
     * Look up by exact label for a given user (any status).
     *
     * @return array<string,mixed>|null
     */
    public function findByLabel(int $fkUser, string $label, int $entity = 1): ?array
    {
        $label = self::normaliseLabel($label);
        if ($label === '') {
            return null;
        }
        $sql = "SELECT rowid, fk_user, label, icon, date_creation, date_lastseen, status, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " WHERE fk_user = " . (int) $fkUser;
        $sql .= " AND label = '" . $this->db->escape($label) . "'";
        $sql .= " AND entity = " . (int) $entity;
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUserDevice: findByLabel failed', LOG_ERR);
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return null;
        }
        return $this->hydrate($obj);
    }

    /**
     * List active user_devices for a user with the count of attached
     * technical devices (PWAs) currently active. Used to populate the
     * device picker shown in the login response and the user_tab UI.
     *
     * @param bool $includeRevoked when true, also return canceled rows
     *                              (useful for admin listings)
     * @return array<int,array<string,mixed>>
     */
    public function listForUser(int $fkUser, int $entity = 1, bool $includeRevoked = false): array
    {
        $sql = "SELECT ud.rowid, ud.fk_user, ud.label, ud.icon, ud.date_creation, ud.date_lastseen, ud.status, ud.entity,";
        $sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . self::DEVICES_TABLE . " d";
        $sql .= " WHERE d.fk_user_device = ud.rowid";
        $sql .= " AND d.status != " . self::DEVICE_STATUS_CANCELED . ") AS session_count";
        $sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE . " ud";
        $sql .= " WHERE ud.fk_user = " . (int) $fkUser;
        $sql .= " AND ud.entity = " . (int) $entity;
        if (!$includeRevoked) {
            $sql .= " AND ud.status = " . self::STATUS_ACTIVE;
        }
        $sql .= " ORDER BY ud.date_lastseen DESC, ud.rowid ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUserDevice: listForUser failed', LOG_ERR);
            return [];
        }
        $rows = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $row = $this->hydrate($obj);
            $row['session_count'] = (int) ($obj->session_count ?? 0);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Rename a user_device. Verifies ownership and label uniqueness
     * within the user's namespace. Returns true on success.
     */
    public function rename(int $rowid, int $expectedFkUser, string $newLabel, int $entity = 1): bool
    {
        $newLabel = self::normaliseLabel($newLabel);
        if ($newLabel === '') {
            return false;
        }
        $row = $this->findById($rowid, $entity);
        if ($row === null || (int) $row['fk_user'] !== (int) $expectedFkUser) {
            return false;
        }
        if ($row['label'] === $newLabel) {
            return true;
        }
        $existing = $this->findByLabel($expectedFkUser, $newLabel, $entity);
        if ($existing !== null && (int) $existing['rowid'] !== (int) $rowid) {
            return false;
        }
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " SET label = '" . $this->db->escape($newLabel) . "'";
        $sql .= " WHERE rowid = " . (int) $rowid;
        $sql .= " AND fk_user = " . (int) $expectedFkUser;
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuthUserDevice: rename failed: ' . (method_exists($this->db, 'lasterror') ? $this->db->lasterror() : ''), LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Update the icon. Verifies ownership.
     */
    public function setIcon(int $rowid, int $expectedFkUser, string $icon, int $entity = 1): bool
    {
        $icon = self::normaliseIcon($icon);
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " SET icon = '" . $this->db->escape($icon) . "'";
        $sql .= " WHERE rowid = " . (int) $rowid;
        $sql .= " AND fk_user = " . (int) $expectedFkUser;
        $sql .= " AND entity = " . (int) $entity;
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuthUserDevice: setIcon failed', LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Bump date_lastseen to now. Called on every successful login that
     * targets this user_device, so the UI can show "last seen X minutes
     * ago" and sort the picker by recency.
     */
    public function touchLastseen(int $rowid): void
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " SET date_lastseen = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE rowid = " . (int) $rowid;
        $this->db->query($sql);
    }

    /**
     * Attach a technical device (llx_smartauth_devices) to this user_device.
     * Verifies ownership of both rows. Returns true on success.
     *
     * The technical device's label is also overwritten with the parent
     * label so that legacy paths reading llx_smartauth_devices.label
     * directly (e.g. the e-mail notifier) still surface a consistent
     * name even before they are migrated to read the parent row.
     */
    public function linkTechnicalDevice(int $userDeviceId, int $technicalDeviceId, int $expectedFkUser, int $entity = 1): bool
    {
        $row = $this->findById($userDeviceId, $entity);
        if ($row === null) {
            return false;
        }
        if ((int) $row['fk_user'] !== (int) $expectedFkUser) {
            return false;
        }
        if ((int) $row['status'] !== self::STATUS_ACTIVE) {
            return false;
        }
        // Ownership of the technical device row.
        $sql = "SELECT rowid, fk_user_creat, entity FROM " . MAIN_DB_PREFIX . self::DEVICES_TABLE;
        $sql .= " WHERE rowid = " . (int) $technicalDeviceId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('SmartAuthUserDevice: linkTechnicalDevice select failed', LOG_ERR);
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj || (int) $obj->fk_user_creat !== (int) $expectedFkUser) {
            return false;
        }
        if ((int) $obj->entity !== (int) $entity) {
            return false;
        }
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::DEVICES_TABLE;
        $sql .= " SET fk_user_device = " . (int) $userDeviceId . ",";
        $sql .= " label = '" . $this->db->escape($row['label']) . "'";
        $sql .= " WHERE rowid = " . (int) $technicalDeviceId;
        if (!$this->db->query($sql)) {
            dol_syslog('SmartAuthUserDevice: linkTechnicalDevice update failed', LOG_ERR);
            return false;
        }
        $this->touchLastseen($userDeviceId);
        return true;
    }

    /**
     * Cascade-revoke: cancel every technical device row that points to
     * this user_device, revoke every token family on those devices, mark
     * their tokens as logged out, and finally mark the user_device row
     * itself revoked.
     *
     * Returns the number of token families revoked (= the number of
     * (device, app) sessions that just ended). On any database failure
     * along the way we still try to push through the remaining steps
     * so partial revocation is preferable to leaving live tokens behind.
     *
     * Idempotent: calling it again on a revoked user_device is a no-op
     * that returns 0.
     */
    public function revoke(int $rowid, int $expectedFkUser, int $entity = 1): int
    {
        $row = $this->findById($rowid, $entity);
        if ($row === null || (int) $row['fk_user'] !== (int) $expectedFkUser) {
            return 0;
        }
        if ((int) $row['status'] === self::STATUS_REVOKED) {
            return 0;
        }

        // 1. Find every technical device row attached to this logical device.
        $deviceIds = [];
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . self::DEVICES_TABLE;
        $sql .= " WHERE fk_user_device = " . (int) $rowid;
        $sql .= " AND fk_user_creat = " . (int) $expectedFkUser;
        $sql .= " AND entity = " . (int) $entity;
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $deviceIds[] = (int) $obj->rowid;
            }
        } else {
            dol_syslog('SmartAuthUserDevice: revoke select technical devices failed', LOG_ERR);
        }

        // 2. Collect all token families active on those devices and revoke
        // them in two atomic UPDATEs (family + auth) to keep the cost flat
        // regardless of session count.
        $sessionsRevoked = 0;
        if (!empty($deviceIds)) {
            $idsList = implode(',', array_map('intval', $deviceIds));

            $sql = "SELECT DISTINCT family_id FROM " . MAIN_DB_PREFIX . self::AUTH_TABLE;
            $sql .= " WHERE fk_user_creat = " . (int) $expectedFkUser;
            $sql .= " AND fk_device_id IN (" . $idsList . ")";
            $sql .= " AND entity = " . (int) $entity;
            $sql .= " AND family_id IS NOT NULL";
            $resql = $this->db->query($sql);
            $familyIds = [];
            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    if ($obj->family_id !== null && $obj->family_id !== '') {
                        $familyIds[] = (int) $obj->family_id;
                    }
                }
            }

            if (!empty($familyIds)) {
                $famList = implode(',', array_map('intval', $familyIds));

                $sql = "UPDATE " . MAIN_DB_PREFIX . self::FAMILY_TABLE;
                $sql .= " SET revoked = 1";
                $sql .= " WHERE rowid IN (" . $famList . ")";
                $this->db->query($sql);

                $sql = "UPDATE " . MAIN_DB_PREFIX . self::AUTH_TABLE;
                $sql .= " SET status = " . self::AUTH_STATUS_LOGOUT . ",";
                $sql .= " salt = 'user_device_revoked'";
                $sql .= " WHERE family_id IN (" . $famList . ")";
                $this->db->query($sql);

                $sessionsRevoked = count($familyIds);
            }

            // 3. Cancel the technical device rows so they no longer count
            // as "known device" in NewLoginNotifier and disappear from
            // any future picker rendered by the legacy code paths.
            $sql = "UPDATE " . MAIN_DB_PREFIX . self::DEVICES_TABLE;
            $sql .= " SET status = " . self::DEVICE_STATUS_CANCELED;
            $sql .= " WHERE rowid IN (" . $idsList . ")";
            $this->db->query($sql);
        }

        // 4. Mark the user_device itself revoked. From this point the row
        // is filtered out of every list query (status = STATUS_ACTIVE).
        $sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
        $sql .= " SET status = " . self::STATUS_REVOKED;
        $sql .= " WHERE rowid = " . (int) $rowid;
        $sql .= " AND fk_user = " . (int) $expectedFkUser;
        $this->db->query($sql);

        dol_syslog("SmartAuthUserDevice: revoked rowid=$rowid user=$expectedFkUser entity=$entity sessions=$sessionsRevoked", LOG_INFO);
        return $sessionsRevoked;
    }

    /**
     * Resolve the user_device id currently linked to a given technical
     * device row. Returns 0 when the technical device has no parent
     * (legacy / not yet sorted).
     */
    public function findParentOfTechnicalDevice(int $technicalDeviceId): int
    {
        if ($technicalDeviceId <= 0) {
            return 0;
        }
        $sql = "SELECT fk_user_device FROM " . MAIN_DB_PREFIX . self::DEVICES_TABLE;
        $sql .= " WHERE rowid = " . (int) $technicalDeviceId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj || $obj->fk_user_device === null) {
            return 0;
        }
        return (int) $obj->fk_user_device;
    }

    /**
     * @param object $obj
     * @return array<string,mixed>
     */
    private function hydrate($obj): array
    {
        return [
            'rowid' => (int) $obj->rowid,
            'fk_user' => (int) $obj->fk_user,
            'label' => (string) $obj->label,
            'icon' => (string) $obj->icon,
            'date_creation' => $obj->date_creation,
            'date_lastseen' => $obj->date_lastseen,
            'status' => (int) $obj->status,
            'entity' => (int) $obj->entity,
        ];
    }
}
