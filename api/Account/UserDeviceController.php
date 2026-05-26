<?php

/**
 * UserDeviceController.php
 *
 * HTTP entry points for the "logical user device" feature.
 *
 * A user (Thomas) installs 5 PWAs on his iPhone. Each PWA generates its
 * own UUID in localStorage and the backend stores one row per UUID in
 * llx_smartauth_devices. Without grouping, Thomas sees 5 lines in his
 * Dolibarr device list, has to revoke 5 times if he loses his phone,
 * and receives 5 "new login" e-mails on first install of each PWA.
 *
 * llx_smartauth_user_devices introduces a logical layer on top: Thomas
 * names his physical device "mon iPhone" once (when the first PWA logs
 * in), the next 4 PWAs let him pick that label from a list, and all 5
 * technical rows share the same fk_user_device parent. Revoking "mon
 * iPhone" cascades through every PWA session at once.
 *
 *   GET    /account/user-devices                       list active devices
 *   POST   /account/user-devices                       body {label, icon, viewport_mode?} -> create + link
 *   POST   /account/user-devices/{id}/link             link current JWT device to existing user_device
 *   POST   /account/user-devices/{id}/rename           body {label}
 *   POST   /account/user-devices/{id}/viewport-mode    body {viewport_mode} (auto|mobile|tablet|desktop or null)
 *   DELETE /account/user-devices/{id}                  cascade revoke
 *
 * All routes are JWT-protected and operate on the authenticated user
 * only; user_id from the JWT payload is the only source of truth.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

dol_include_once('/smartauth/class/smartauthuserdevice.class.php');

use SmartAuthUserDevice;
use SmartAuth\Api\InputSanitizer;

class UserDeviceController
{
    /**
     * @var \DoliDB|null
     */
    private $injectedDb;

    /**
     * @var SmartAuthUserDevice|null
     */
    private $injectedRepo;

    public function __construct($db = null, ?SmartAuthUserDevice $repo = null)
    {
        $this->injectedDb = $db;
        $this->injectedRepo = $repo;
    }

    /**
     * @api {get} /account/user-devices List logical devices of the authenticated user
     */
    public function index($payload)
    {
        $userId = $this->resolveUserId($payload);
        if ($userId <= 0) {
            return [['error' => 'unauthorized'], 401];
        }
        $entity = (int) ($payload['entity'] ?? 1);

        $repo = $this->resolveRepo();
        $rows = $repo->listForUser($userId, $entity);

        return [
            [
                'devices' => array_map([$this, 'projectForApi'], $rows),
            ],
            200,
        ];
    }

    /**
     * @api {post} /account/user-devices Create a new logical device and link the current JWT device to it
     *
     * Body: {
     *   label:          string (1..100),
     *   icon?:          'phone'|'tablet'|'laptop'|'desktop',
     *   viewport_mode?: 'auto'|'mobile'|'tablet'|'desktop'
     * }
     *
     * Always tries to link the current JWT device row (the technical
     * smartauth_devices row corresponding to the X-DEVICEID UUID used to
     * authenticate this request). If the JWT was issued without a known
     * device_id (e.g. ancient token), the device is still created and
     * returned with `linked: false` so the caller can react.
     *
     * If viewport_mode is omitted, the row is created with a sensible
     * default derived from `icon` (phone -> mobile, tablet -> tablet,
     * laptop/desktop -> desktop). An explicit but unrecognized value
     * yields a 400 rather than a silent fallback.
     */
    public function create($payload)
    {
        $userId = $this->resolveUserId($payload);
        if ($userId <= 0) {
            return [['error' => 'unauthorized'], 401];
        }
        $entity = (int) ($payload['entity'] ?? 1);

        $labelRaw = isset($payload['label']) ? (string) $payload['label'] : '';
        $iconRaw = isset($payload['icon']) ? (string) $payload['icon'] : SmartAuthUserDevice::ICON_PHONE;

        $label = SmartAuthUserDevice::normaliseLabel(InputSanitizer::sanitizeString($labelRaw, SmartAuthUserDevice::LABEL_MAX_LENGTH));
        if ($label === '') {
            return [['error' => 'invalid_label'], 400];
        }
        $icon = SmartAuthUserDevice::normaliseIcon($iconRaw);

        $viewportModeRaw = isset($payload['viewport_mode']) ? (string) $payload['viewport_mode'] : null;
        // Explicit-but-invalid -> 400 instead of silent fallback to the
        // icon default. Empty / missing -> let create() derive it.
        if ($viewportModeRaw !== null && $viewportModeRaw !== '') {
            if (SmartAuthUserDevice::normaliseViewportMode($viewportModeRaw) === null) {
                dol_syslog("SmartAuth UserDeviceController::create rejected invalid viewport_mode='$viewportModeRaw' user=$userId", LOG_WARNING);
                return [['error' => 'invalid_viewport_mode'], 400];
            }
        }

        $repo = $this->resolveRepo();
        $rowid = $repo->create($userId, $label, $icon, $entity, $viewportModeRaw);
        if ($rowid === -2) {
            return [['error' => 'label_already_used'], 409];
        }
        if ($rowid <= 0) {
            return [['error' => 'create_failed'], 500];
        }

        $jwtDeviceId = (int) ($payload['jwt_device_id'] ?? 0);
        $linked = false;
        if ($jwtDeviceId > 0) {
            $linked = $repo->linkTechnicalDevice($rowid, $jwtDeviceId, $userId, $entity);
        }

        // Re-fetch to surface the final stored viewport_mode (derived
        // from the icon when not provided). Cheap single-row lookup,
        // worth the round-trip so the client gets the canonical value.
        $row = $repo->findById($rowid, $entity);
        $viewportMode = $row['viewport_mode'] ?? null;

        dol_syslog("SmartAuth UserDeviceController::create rowid=$rowid user=$userId label='$label' viewport_mode='" . ($viewportMode ?? '') . "' linked=" . ($linked ? '1' : '0'), LOG_INFO);

        return [
            [
                'id' => $rowid,
                'label' => $label,
                'icon' => $icon,
                'viewport_mode' => $viewportMode,
                'linked' => $linked,
            ],
            201,
        ];
    }

    /**
     * @api {post} /account/user-devices/{id}/link Attach the current JWT device to an existing user_device
     */
    public function link($payload)
    {
        $userId = $this->resolveUserId($payload);
        if ($userId <= 0) {
            return [['error' => 'unauthorized'], 401];
        }
        $entity = (int) ($payload['entity'] ?? 1);

        $userDeviceId = (int) ($payload['id'] ?? 0);
        if ($userDeviceId <= 0) {
            return [['error' => 'invalid_id'], 400];
        }
        $jwtDeviceId = (int) ($payload['jwt_device_id'] ?? 0);
        if ($jwtDeviceId <= 0) {
            return [['error' => 'no_current_device'], 400];
        }

        $repo = $this->resolveRepo();
        $target = $repo->findById($userDeviceId, $entity);
        if ($target === null || (int) $target['fk_user'] !== $userId) {
            return [['error' => 'not_found'], 404];
        }
        if ((int) $target['status'] !== SmartAuthUserDevice::STATUS_ACTIVE) {
            return [['error' => 'revoked'], 410];
        }

        $ok = $repo->linkTechnicalDevice($userDeviceId, $jwtDeviceId, $userId, $entity);
        if (!$ok) {
            return [['error' => 'link_failed'], 500];
        }

        dol_syslog("SmartAuth UserDeviceController::link user_device=$userDeviceId tech_device=$jwtDeviceId user=$userId", LOG_INFO);

        return [
            [
                'id' => $userDeviceId,
                'label' => $target['label'],
                'icon' => $target['icon'],
                'viewport_mode' => $target['viewport_mode'] ?? null,
                'linked' => true,
            ],
            200,
        ];
    }

    /**
     * @api {post} /account/user-devices/{id}/rename
     */
    public function rename($payload)
    {
        $userId = $this->resolveUserId($payload);
        if ($userId <= 0) {
            return [['error' => 'unauthorized'], 401];
        }
        $entity = (int) ($payload['entity'] ?? 1);

        $userDeviceId = (int) ($payload['id'] ?? 0);
        if ($userDeviceId <= 0) {
            return [['error' => 'invalid_id'], 400];
        }
        $labelRaw = isset($payload['label']) ? (string) $payload['label'] : '';
        $newLabel = SmartAuthUserDevice::normaliseLabel(InputSanitizer::sanitizeString($labelRaw, SmartAuthUserDevice::LABEL_MAX_LENGTH));
        if ($newLabel === '') {
            return [['error' => 'invalid_label'], 400];
        }

        $repo = $this->resolveRepo();
        $row = $repo->findById($userDeviceId, $entity);
        if ($row === null || (int) $row['fk_user'] !== $userId) {
            return [['error' => 'not_found'], 404];
        }
        // Conflict check (own row excluded) is also done inside ::rename;
        // surface a clean 409 here so the picker can react cleanly.
        $conflict = $repo->findByLabel($userId, $newLabel, $entity);
        if ($conflict !== null && (int) $conflict['rowid'] !== $userDeviceId) {
            return [['error' => 'label_already_used'], 409];
        }
        if (!$repo->rename($userDeviceId, $userId, $newLabel, $entity)) {
            return [['error' => 'rename_failed'], 500];
        }

        // Propagate label to every technical device row (kept in sync so
        // legacy code reading smartauth_devices.label still sees the new
        // name immediately).
        $this->propagateLabelToTechnicalDevices($userDeviceId, $userId, $entity, $newLabel);

        dol_syslog("SmartAuth UserDeviceController::rename user_device=$userDeviceId user=$userId new_label='$newLabel'", LOG_INFO);

        return [
            [
                'id' => $userDeviceId,
                'label' => $newLabel,
                'icon' => $row['icon'],
            ],
            200,
        ];
    }

    /**
     * @api {post} /account/user-devices/{id}/viewport-mode Update the persistent viewport mode of a logical device
     *
     * Body: { viewport_mode: 'auto'|'mobile'|'tablet'|'desktop' | null | '' }
     *
     * Empty / null / missing -> clear back to NULL (legacy "never
     * set" state). Anything else not in the allowed list -> 400.
     * Scoped to the authenticated user; a device that belongs to
     * another user 404s.
     */
    public function setViewportMode($payload)
    {
        $userId = $this->resolveUserId($payload);
        if ($userId <= 0) {
            return [['error' => 'unauthorized'], 401];
        }
        $entity = (int) ($payload['entity'] ?? 1);

        $userDeviceId = (int) ($payload['id'] ?? 0);
        if ($userDeviceId <= 0) {
            return [['error' => 'invalid_id'], 400];
        }

        $repo = $this->resolveRepo();
        $row = $repo->findById($userDeviceId, $entity);
        if ($row === null || (int) $row['fk_user'] !== $userId) {
            dol_syslog("SmartAuth UserDeviceController::setViewportMode not_found user_device=$userDeviceId user=$userId", LOG_WARNING);
            return [['error' => 'not_found'], 404];
        }

        $modeRaw = $payload['viewport_mode'] ?? null;
        // Explicit-but-invalid -> 400. Empty / null / missing -> clear
        // back to NULL (handled by the repo via normaliseViewportMode).
        if ($modeRaw !== null && $modeRaw !== '') {
            if (SmartAuthUserDevice::normaliseViewportMode($modeRaw) === null) {
                dol_syslog("SmartAuth UserDeviceController::setViewportMode rejected invalid mode='" . (string) $modeRaw . "' user_device=$userDeviceId user=$userId", LOG_WARNING);
                return [['error' => 'invalid_viewport_mode'], 400];
            }
        }

        if (!$repo->setViewportMode($userDeviceId, $userId, $modeRaw, $entity)) {
            return [['error' => 'update_failed'], 500];
        }

        // Re-fetch so the client gets the canonical stored value (null
        // when cleared, otherwise the normalised string).
        $row = $repo->findById($userDeviceId, $entity);
        $stored = $row['viewport_mode'] ?? null;

        dol_syslog("SmartAuth UserDeviceController::setViewportMode user_device=$userDeviceId user=$userId mode='" . ($stored ?? '') . "'", LOG_INFO);

        return [
            [
                'id' => $userDeviceId,
                'viewport_mode' => $stored,
            ],
            200,
        ];
    }

    /**
     * @api {delete} /account/user-devices/{id} Cascade-revoke
     *
     * Cancels every technical device row that points to this user_device,
     * revokes every token family on those rows, and marks the user_device
     * itself revoked. The currently-authenticated session is therefore
     * killed too if the caller asks to revoke the device it is logged in
     * from. That is the expected behaviour ("I lost my phone").
     */
    public function revoke($payload)
    {
        $userId = $this->resolveUserId($payload);
        if ($userId <= 0) {
            return [['error' => 'unauthorized'], 401];
        }
        $entity = (int) ($payload['entity'] ?? 1);

        $userDeviceId = (int) ($payload['id'] ?? 0);
        if ($userDeviceId <= 0) {
            return [['error' => 'invalid_id'], 400];
        }

        $repo = $this->resolveRepo();
        $row = $repo->findById($userDeviceId, $entity);
        if ($row === null || (int) $row['fk_user'] !== $userId) {
            return [['error' => 'not_found'], 404];
        }

        $sessionsRevoked = $repo->revoke($userDeviceId, $userId, $entity);

        dol_syslog("SmartAuth UserDeviceController::revoke user_device=$userDeviceId user=$userId sessions=$sessionsRevoked", LOG_INFO);

        return [
            [
                'id' => $userDeviceId,
                'revoked' => true,
                'sessions_revoked' => $sessionsRevoked,
            ],
            200,
        ];
    }

    /**
     * Propagate the new logical label to every technical device row that
     * still points to this parent. Cheap one-shot UPDATE.
     */
    private function propagateLabelToTechnicalDevices(int $userDeviceId, int $userId, int $entity, string $newLabel): void
    {
        $db = $this->resolveDb();
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " SET label = '" . $db->escape($newLabel) . "'";
        $sql .= " WHERE fk_user_device = " . (int) $userDeviceId;
        $sql .= " AND fk_user_creat = " . (int) $userId;
        $sql .= " AND entity = " . (int) $entity;
        if (!$db->query($sql)) {
            dol_syslog('SmartAuth UserDeviceController::propagateLabelToTechnicalDevices update failed', LOG_ERR);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function projectForApi(array $row): array
    {
        return [
            'id' => (int) $row['rowid'],
            'label' => (string) $row['label'],
            'icon' => (string) $row['icon'],
            'viewport_mode' => $row['viewport_mode'] ?? null,
            'date_creation' => $row['date_creation'],
            'date_lastseen' => $row['date_lastseen'],
            'session_count' => (int) ($row['session_count'] ?? 0),
        ];
    }

    private function resolveUserId($payload): int
    {
        if (!is_array($payload)) {
            return 0;
        }
        if (!empty($payload['user']) && is_object($payload['user']) && !empty($payload['user']->id)) {
            return (int) $payload['user']->id;
        }
        return (int) ($payload['user_id'] ?? 0);
    }

    private function resolveDb()
    {
        if ($this->injectedDb !== null) {
            return $this->injectedDb;
        }
        global $db;
        return $db;
    }

    private function resolveRepo(): SmartAuthUserDevice
    {
        if ($this->injectedRepo !== null) {
            return $this->injectedRepo;
        }
        return new SmartAuthUserDevice($this->resolveDb());
    }
}
