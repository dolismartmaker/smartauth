<?php

/**
 * NewLoginNotifier.php
 *
 * Sends an "alerte de nouvelle connexion" email to a user when a token
 * is issued from a (user, ip) tuple OR a (user, device) tuple that has
 * not been seen in the last N days. Behaviour mimics Google's "new
 * sign-in" alerts so the user can react quickly if they did not
 * initiate the login.
 *
 * Plugged into AuthController::login() and
 * AuthController::generateTokenForAuthenticatedUser() (the QR pair
 * issuance path). All failures are caught by the caller; an email
 * delivery problem must never break the login itself.
 *
 * Configuration (admin/setup.php):
 *   - SMARTAUTH_NEW_LOGIN_NOTIFY (bool)         : opt-in switch
 *   - SMARTAUTH_NEW_LOGIN_NOTIFY_LOOKBACK_DAYS  : window for "known"
 *                                                 IP/device, default 30
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\Account;

use SmartAuth\Api\OAuth2\OAuthConfig;

dol_include_once('/smartauth/api/OAuth2/OAuthConfig.php');

class NewLoginNotifier
{
    public const REASON_FIRST_LOGIN_SKIPPED = 'first_login';
    public const REASON_KNOWN = 'known';
    public const REASON_NEW_IP = 'new_ip';
    public const REASON_NEW_DEVICE = 'new_device';
    public const REASON_NEW_BOTH = 'new_both';
    public const REASON_DISABLED = 'disabled';
    public const REASON_SENT = 'sent';
    public const REASON_SEND_FAILED = 'send_failed';

    /**
     * @var \DoliDB
     */
    private $db;

    /**
     * @var callable|null Optional injected sender:
     *     fn(string $to, string $subject, string $textBody, string $htmlBody): bool
     * When set, the real Dolibarr CMailFile is bypassed -- used by tests.
     */
    private $emailSender;

    public function __construct($db, ?callable $emailSender = null)
    {
        $this->db = $db;
        $this->emailSender = $emailSender;
    }

    /**
     * Inspect the current authentication context and, if the (ip, device)
     * tuple is new for this user, fire a notification email. The method
     * returns one of the REASON_* constants for tracing/testing.
     *
     * Never throws: all errors are logged via dol_syslog and converted
     * to a REASON_SEND_FAILED return value.
     *
     * @param object $user   Dolibarr User object (must expose id / email)
     * @param string $ip     Client IP recorded for the new token
     * @param int    $deviceId   smartauth_devices.rowid for the new token
     * @return string  One of the REASON_* constants
     */
    public function notifyIfNewLogin($user, string $ip, int $deviceId): string
    {
        try {
            if (!getDolGlobalString('SMARTAUTH_NEW_LOGIN_NOTIFY')) {
                return self::REASON_DISABLED;
            }
            if (!is_object($user) || empty($user->id) || empty($user->email)) {
                return self::REASON_DISABLED;
            }

            $userId = (int) $user->id;
            $lookbackDays = (int) getDolGlobalString('SMARTAUTH_NEW_LOGIN_NOTIFY_LOOKBACK_DAYS');
            if ($lookbackDays <= 0) {
                $lookbackDays = 30;
            }
            $cutoff = dol_now() - ($lookbackDays * 24 * 3600);

            // Total token count for this user (across all time, not just
            // the lookback window). Without this guard, the very first
            // login of a freshly created account would always fire an
            // alert, which is noise rather than security.
            if ($this->countAllTokensForUser($userId) <= 1) {
                return self::REASON_FIRST_LOGIN_SKIPPED;
            }

            $ipKnown = $ip !== '' && $this->ipSeenWithinWindow($userId, $ip, $cutoff);
            $deviceKnown = $deviceId > 0 && $this->deviceSeenWithinWindow($userId, $deviceId);

            if ($ipKnown && $deviceKnown) {
                return self::REASON_KNOWN;
            }

            $reason = self::REASON_NEW_BOTH;
            if ($ipKnown && !$deviceKnown) {
                $reason = self::REASON_NEW_DEVICE;
            } elseif (!$ipKnown && $deviceKnown) {
                $reason = self::REASON_NEW_IP;
            }

            $deviceLabel = $this->lookupDeviceLabel($deviceId);
            $ok = $this->sendNotification($user, $ip, $deviceLabel, $reason);

            return $ok ? self::REASON_SENT : self::REASON_SEND_FAILED;
        } catch (\Throwable $e) {
            dol_syslog('SmartAuth NewLoginNotifier failed: ' . $e->getMessage(), LOG_ERR);
            return self::REASON_SEND_FAILED;
        }
    }

    /**
     * @return int
     */
    private function countAllTokensForUser(int $userId): int
    {
        $sql = "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE fk_authid = " . $userId;
        $sql .= " AND auth_element = 'user'";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj ? (int) $obj->n : 0;
    }

    private function ipSeenWithinWindow(int $userId, string $ip, int $cutoffTs): bool
    {
        // The current insert is what the caller just produced; we must
        // exclude rows newer than now() so the freshly-created token does
        // not count as "previously seen" for itself. The simplest is to
        // bound by date_creation < now() - 1s.
        $cutoffStart = $this->db->idate($cutoffTs);
        $cutoffEnd = $this->db->idate(dol_now() - 1);
        $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE fk_authid = " . $userId;
        $sql .= " AND auth_element = 'user'";
        $sql .= " AND ip = '" . $this->db->escape($ip) . "'";
        $sql .= " AND date_creation > '" . $cutoffStart . "'";
        $sql .= " AND date_creation < '" . $cutoffEnd . "'";
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj !== false && $obj !== null;
    }

    private function deviceSeenWithinWindow(int $userId, int $deviceId): bool
    {
        // For the device check we look at *any* historical row that
        // referenced this device for this user, even older than the
        // lookback window. A device a user has used before is not "new"
        // even if dormant. The lookback parameter is purely an IP-side
        // filter to keep alerts useful for travelling users.
        //
        // Logical-device layer: once Thomas has declared "mon iPhone"
        // and any PWA on that phone has earned a fk_user_device parent,
        // every other PWA on the same phone (sharing the parent) is
        // considered "known device" too. Without this, installing a 4th
        // PWA on a known phone would still fire a new-login alert and
        // waste the user's attention.
        $parentUserDevice = $this->parentUserDeviceOf($deviceId);

        if ($parentUserDevice > 0) {
            $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "smartauth_auth a";
            $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "smartauth_devices d ON d.rowid = a.fk_device_id";
            $sql .= " WHERE a.fk_authid = " . $userId;
            $sql .= " AND a.auth_element = 'user'";
            $sql .= " AND d.fk_user_device = " . $parentUserDevice;
            $sql .= " AND a.date_creation < '" . $this->db->idate(dol_now() - 1) . "'";
            $sql .= " LIMIT 1";
            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                if ($obj !== false && $obj !== null) {
                    return true;
                }
            }
            // Fall through to the strict per-device check too. The
            // technical device might be brand new but already attached
            // to a parent that no other PWA has used yet (legacy data),
            // in which case we still want to honour the "exact same
            // fk_device_id seen before" semantics.
        }

        $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "smartauth_auth";
        $sql .= " WHERE fk_authid = " . $userId;
        $sql .= " AND auth_element = 'user'";
        $sql .= " AND fk_device_id = " . $deviceId;
        $sql .= " AND date_creation < '" . $this->db->idate(dol_now() - 1) . "'";
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj !== false && $obj !== null;
    }

    /**
     * Resolve the logical-device parent of a technical smartauth_devices
     * row, if any. Returns 0 when the row has no parent (legacy or not
     * yet sorted by the user).
     */
    private function parentUserDeviceOf(int $deviceId): int
    {
        if ($deviceId <= 0) {
            return 0;
        }
        $sql = "SELECT fk_user_device FROM " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " WHERE rowid = " . $deviceId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj || $obj->fk_user_device === null || $obj->fk_user_device === '') {
            return 0;
        }
        return (int) $obj->fk_user_device;
    }

    private function lookupDeviceLabel(int $deviceId): string
    {
        if ($deviceId <= 0) {
            return '';
        }
        $sql = "SELECT label, ref, uuid, fk_user_device FROM " . MAIN_DB_PREFIX . "smartauth_devices";
        $sql .= " WHERE rowid = " . $deviceId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            return '';
        }
        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            return '';
        }
        // Prefer the logical-device label when this technical row has a
        // parent: it is the name the user himself chose ("mon iPhone")
        // rather than whatever happened to be saved on the per-PWA row.
        if (!empty($obj->fk_user_device)) {
            $sql = "SELECT label FROM " . MAIN_DB_PREFIX . "smartauth_user_devices";
            $sql .= " WHERE rowid = " . (int) $obj->fk_user_device;
            $resql = $this->db->query($sql);
            if ($resql) {
                $parent = $this->db->fetch_object($resql);
                if ($parent && !empty($parent->label)) {
                    return (string) $parent->label;
                }
            }
        }
        if (!empty($obj->label)) {
            return (string) $obj->label;
        }
        if (!empty($obj->ref)) {
            return (string) $obj->ref;
        }
        return '';
    }

    /**
     * Render the email templates and dispatch via Dolibarr CMailFile (or
     * the injected callable for tests).
     */
    private function sendNotification($user, string $ip, string $deviceLabel, string $reason): bool
    {
        global $langs, $mysoc;

        if (is_object($langs)) {
            $langs->loadLangs(['smartauth@smartauth']);
        }

        $issuer = OAuthConfig::getIssuer();
        $sessionsUrl = $this->buildSessionsUrl((int) $user->id);
        $loginName = (string) ($user->login ?? $user->email ?? '');
        $companyName = is_object($mysoc) && !empty($mysoc->name) ? (string) $mysoc->name : 'SmartAuth';

        $vars = [
            'issuer' => $issuer,
            'sessionsUrl' => $sessionsUrl,
            'login' => $loginName,
            'firstname' => (string) ($user->firstname ?? ''),
            'lastname' => (string) ($user->lastname ?? ''),
            'ip' => $ip,
            'deviceLabel' => $deviceLabel !== '' ? $deviceLabel : $this->translate('NewLoginUnknownDevice'),
            'reason' => $reason,
            'reasonText' => $this->reasonText($reason),
            'when' => dol_print_date(dol_now(), 'dayhour'),
            'companyName' => $companyName,
        ];

        $textTpl = dirname(__DIR__, 2) . '/tpl/email/new_login_notification.txt.php';
        $htmlTpl = dirname(__DIR__, 2) . '/tpl/email/new_login_notification.html.php';

        $textBody = $this->renderTemplate($textTpl, $vars);
        $htmlBody = $this->renderTemplate($htmlTpl, $vars);
        $subject = $this->translate('NewLoginEmailSubject');

        $to = (string) $user->email;

        if ($this->emailSender !== null) {
            return (bool) call_user_func($this->emailSender, $to, $subject, $textBody, $htmlBody);
        }

        return $this->dispatchEmailViaDolibarr($to, $subject, $htmlBody);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderTemplate(string $path, array $vars): string
    {
        if (!file_exists($path)) {
            dol_syslog('SmartAuth NewLoginNotifier: missing template ' . $path, LOG_ERR);
            return '';
        }
        ob_start();
        extract($vars, EXTR_SKIP);
        include $path;
        return (string) ob_get_clean();
    }

    private function dispatchEmailViaDolibarr(string $to, string $subject, string $htmlBody): bool
    {
        if (!class_exists('CMailFile')) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        }
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', '');
        if ($from === '') {
            $from = 'no-reply@' . (parse_url(OAuthConfig::getIssuer(), PHP_URL_HOST) ?: 'localhost');
        }
        $mail = new \CMailFile(
            $subject,
            $to,
            $from,
            $htmlBody,
            [],
            [],
            [],
            '',
            '',
            0,
            1,
            '',
            '',
            '',
            '',
            'mail'
        );
        if (!empty($mail->error)) {
            dol_syslog('SmartAuth NewLoginNotifier: CMailFile init failed: ' . $mail->error, LOG_ERR);
            return false;
        }
        if (!$mail->sendfile()) {
            dol_syslog('SmartAuth NewLoginNotifier: send failed: ' . ($mail->error ?? ''), LOG_ERR);
            return false;
        }
        return true;
    }

    private function buildSessionsUrl(int $userId): string
    {
        // dol_buildpath wants Dolibarr to be bootstrapped; under the API
        // entrypoint it might not be. Compose a stable URL by hand from
        // the issuer host.
        $issuer = OAuthConfig::getIssuer();
        $host = parse_url($issuer, PHP_URL_SCHEME) . '://' . parse_url($issuer, PHP_URL_HOST);
        if ($host === '://') {
            $host = $issuer;
        }
        return $host . '/custom/smartauth/user_tab.php?id=' . $userId;
    }

    private function reasonText(string $reason): string
    {
        switch ($reason) {
            case self::REASON_NEW_IP:
                return $this->translate('NewLoginReasonNewIp');
            case self::REASON_NEW_DEVICE:
                return $this->translate('NewLoginReasonNewDevice');
            case self::REASON_NEW_BOTH:
            default:
                return $this->translate('NewLoginReasonNewBoth');
        }
    }

    private function translate(string $key): string
    {
        global $langs;
        if (is_object($langs) && method_exists($langs, 'transnoentities')) {
            return (string) $langs->transnoentities($key);
        }
        return $key;
    }
}
