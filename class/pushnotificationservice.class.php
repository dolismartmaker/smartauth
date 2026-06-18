<?php

/**
 * pushnotificationservice.class.php
 *
 * Trigger/cron facing facade for Web Push notifications. Thin wrapper on top of
 * \SmartAuth\Api\PushSender: it carries NO authorization logic (the HTTP gate
 * lives in PushController::send) so it can be called from Dolibarr triggers and
 * scheduled jobs where there is no request context / $user.
 *
 * Usage in a trigger:
 *   dol_include_once('/smartauth/class/pushnotificationservice.class.php');
 *   $pushService = new \SmartAuth\PushNotificationService($this->db);
 *   $pushService->notifyUser($userId, 'Title', 'Body', ['url' => '/foo/123']);
 *
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

namespace SmartAuth;

/**
 * Service for sending push notifications from Dolibarr triggers and cron jobs.
 */
class PushNotificationService
{
    /** @var \DoliDB */
    private $db;

    /**
     * @param \DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Send a notification to a specific internal user (all their devices).
     *
     * @param int    $userId  Target llx_user rowid
     * @param string $title   Notification title (use transnoentities, not trans)
     * @param string $body    Notification body (use transnoentities, not trans)
     * @param array  $data    Additional data forwarded to the Service Worker (url, type, ...)
     * @param array  $options Push options (ttl, urgency, tag)
     * @return array{sent:int, failed:int}
     */
    public function notifyUser($userId, $title, $body, $data = [], $options = [])
    {
        // Silo A internal user -> subject_type 'user'.
        return $this->notifySubject('user', (int) $userId, $title, $body, $data, $options);
    }

    /**
     * Send a notification to a subject (subject-aware: user/account/member).
     *
     * Goes through PushSender directly -> NO permission gate. There is no
     * self-service HTTP send route; the optional 'oauth2' scope gate in
     * PushController::send() does not apply to internal trigger/cron callers.
     *
     * @param string $subjectType 'user' | 'account' | 'member'
     * @param int    $subjectId   llx_user / llx_societe_account / llx_adherent rowid
     * @param string $title       Notification title
     * @param string $body        Notification body
     * @param array  $data        Additional data (url, type, ...)
     * @param array  $options     Push options (ttl, urgency, tag)
     * @return array{sent:int, failed:int}
     */
    public function notifySubject($subjectType, $subjectId, $title, $body, $data = [], $options = [])
    {
        if (empty($subjectId)) {
            dol_syslog('[SmartAuth] PushNotificationService::notifySubject called without a subjectId, skip', LOG_WARNING);
            return ['sent' => 0, 'failed' => 0];
        }

        // Load the sending engine. dol_include_once also pulls SmartAuth\Api\
        // psr-4 + the minishlink autoloader (PushSender::ensureWebPushLoaded),
        // so this works even when the smartauth API front controller is not booted.
        dol_include_once('/smartauth/api/PushSender.php');
        if (!class_exists('\\SmartAuth\\Api\\PushSender')) {
            dol_syslog('[SmartAuth] PushNotificationService::notifySubject PushSender class not found, smartauth incomplete, skip', LOG_ERR);
            return ['sent' => 0, 'failed' => 0];
        }

        $message = [
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'icon'  => isset($data['icon']) ? $data['icon'] : null,
            'badge' => isset($data['badge']) ? $data['badge'] : null,
            'tag'   => isset($options['tag']) ? $options['tag'] : null,
        ];

        $sender = new \SmartAuth\Api\PushSender($this->db);
        list($result, $httpCode) = $sender->send(
            ['subject_type' => $subjectType, 'subject_id' => (int) $subjectId],
            $message,
            $options
        );

        if ($httpCode >= 500) {
            dol_syslog('[SmartAuth] PushNotificationService::notifySubject send failed httpCode='.((int) $httpCode).' '.json_encode($result), LOG_ERR);
        }

        return [
            'sent'   => isset($result['sent']) ? (int) $result['sent'] : 0,
            'failed' => isset($result['failed']) ? (int) $result['failed'] : 0,
        ];
    }

    /**
     * Send the same notification to several internal users.
     *
     * @param int[]  $userIds Target llx_user rowids
     * @param string $title   Notification title
     * @param string $body    Notification body
     * @param array  $data    Additional data (url, type, ...)
     * @param array  $options Push options (ttl, urgency, tag)
     * @return array{sent:int, failed:int}
     */
    public function notifyUsers($userIds, $title, $body, $data = [], $options = [])
    {
        $totalSent = 0;
        $totalFailed = 0;

        foreach ($userIds as $userId) {
            $result = $this->notifyUser($userId, $title, $body, $data, $options);
            $totalSent += $result['sent'];
            $totalFailed += $result['failed'];
        }

        return ['sent' => $totalSent, 'failed' => $totalFailed];
    }

    /**
     * Send a notification to every active user holding a given Dolibarr right.
     *
     * @param string $module  Module name (rights_def.module)
     * @param string $right   Permission name (rights_def.perms)
     * @param string $title   Notification title
     * @param string $body    Notification body
     * @param array  $data    Additional data (url, type, ...)
     * @param array  $options Push options (ttl, urgency, tag)
     * @return array{sent:int, failed:int}
     */
    public function notifyUsersWithRight($module, $right, $title, $body, $data = [], $options = [])
    {
        global $conf;

        $sql = "SELECT DISTINCT u.rowid";
        $sql .= " FROM ".MAIN_DB_PREFIX."user as u";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."user_rights as ur ON ur.fk_user = u.rowid";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."rights_def as rd ON rd.id = ur.fk_id";
        $sql .= " WHERE rd.module = '".$this->db->escape($module)."'";
        $sql .= " AND rd.perms = '".$this->db->escape($right)."'";
        $sql .= " AND u.statut = 1";
        $sql .= " AND u.entity IN (0, ".(int) $conf->entity.")";

        $userIds = [];
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $userIds[] = (int) $obj->rowid;
            }
        } else {
            dol_syslog('[SmartAuth] PushNotificationService::notifyUsersWithRight query failed: '.$this->db->lasterror(), LOG_ERR);
            return ['sent' => 0, 'failed' => 0];
        }

        if (empty($userIds)) {
            dol_syslog('[SmartAuth] PushNotificationService::notifyUsersWithRight no user holds '.$module.'/'.$right, LOG_NOTICE);
            return ['sent' => 0, 'failed' => 0];
        }

        return $this->notifyUsers($userIds, $title, $body, $data, $options);
    }
}
