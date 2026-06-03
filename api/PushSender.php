<?php

/**
 * PushSender.php
 *
 * Web Push sending engine. No authorization logic on purpose: the HTTP
 * permission gate lives in PushController::send(). This class is reused from
 * triggers and cron, where there is no request context / $user.
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

namespace SmartAuth\Api;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushSender
{
    const DEFAULT_TTL = 86400;   // 24h
    const MAX_ERROR_COUNT = 3;
    const VAPID_SUBJECT_CONFIG = 'SMARTAUTH_VAPID_SUBJECT';

    /** @var \DoliDB */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Resolve target subscriptions, encrypt + dispatch, update bookkeeping.
     *
     * @param array $target  ['subscription_id'|'subject_type'+'subject_id'|'device_id']
     * @param array $message ['title','body','icon','badge','tag','data']
     * @param array $options ['ttl','urgency']
     * @return array [responseArray, httpCode]
     */
    public function send(array $target, array $message, array $options = [])
    {
        global $conf;

        self::ensureWebPushLoaded();

        $subscriptions = $this->getTargetSubscriptions($target, (int) $conf->entity);
        if (empty($subscriptions)) {
            dol_syslog('PushSender::send no active subscription for target '.json_encode($target), LOG_NOTICE);
            return [['sent' => 0, 'failed' => 0, 'results' => [], 'error' => 'No active subscriptions found'], 404];
        }

        $vapidKeys = VapidKeyHelper::readKeys($this->db);
        if (empty($vapidKeys['publicKey']) || empty($vapidKeys['privateKey'])) {
            dol_syslog('PushSender::send VAPID keys not configured', LOG_ERR);
            return [['error' => 'VAPID keys not configured'], 500];
        }

        $auth = ['VAPID' => [
            'subject'    => $this->resolveVapidSubject(),
            'publicKey'  => $vapidKeys['publicKey'],
            'privateKey' => $vapidKeys['privateKey'],
        ]];
        $webPush = new WebPush($auth);

        $payload = json_encode([
            'title' => $message['title'],
            'body'  => $message['body'],
            'icon'  => $message['icon'] ?? null,
            'badge' => $message['badge'] ?? null,
            'tag'   => $message['tag'] ?? null,
            'data'  => $message['data'] ?? [],
        ]);

        $ttl = isset($options['ttl']) ? (int) $options['ttl'] : self::DEFAULT_TTL;
        $urgency = isset($options['urgency']) ? $options['urgency'] : 'normal';

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => ['p256dh' => $sub['key_p256dh'], 'auth' => $sub['key_auth']],
                ]),
                $payload,
                ['TTL' => $ttl, 'urgency' => $urgency]
            );
        }

        $results = [];
        $sent = 0;
        $failed = 0;
        foreach ($webPush->flush() as $index => $report) {
            $sub = $subscriptions[$index];
            $row = ['subscription_id' => (int) $sub['rowid'], 'success' => $report->isSuccess()];
            if ($report->isSuccess()) {
                $sent++;
                $this->updateSubscriptionSuccess((int) $sub['rowid']);
            } else {
                $failed++;
                $reason = $report->getReason();
                $row['error'] = $reason;
                dol_syslog('PushSender::send failed sub='.((int) $sub['rowid']).' reason='.$reason, LOG_WARNING);
                if ($report->isSubscriptionExpired()) {
                    $this->removeSubscription((int) $sub['rowid']);
                    $row['removed'] = true;
                } else {
                    $this->updateSubscriptionError((int) $sub['rowid'], $reason);
                }
            }
            $results[] = $row;
        }

        $httpCode = ($failed > 0 && $sent > 0) ? 207 : 200;
        return [['sent' => $sent, 'failed' => $failed, 'results' => $results], $httpCode];
    }

    /**
     * Resolve the subscriptions to target. No valid target -> empty array
     * (never broadcast implicitly).
     *
     * @param array $target
     * @param int   $entity
     * @return array<int, array{rowid:int, endpoint:string, key_p256dh:string, key_auth:string}>
     */
    private function getTargetSubscriptions(array $target, $entity)
    {
        $sql = "SELECT rowid, endpoint, key_p256dh, key_auth";
        $sql .= " FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE status = 1 AND entity = ".(int) $entity;

        if (!empty($target['subscription_id'])) {
            $sql .= " AND rowid = ".(int) $target['subscription_id'];
        } elseif (!empty($target['device_id'])) {
            $sql .= " AND fk_device = ".(int) $target['device_id'];
        } elseif (!empty($target['subject_id'])) {
            $type = !empty($target['subject_type']) ? $target['subject_type'] : 'user';
            $sql .= " AND subject_type = '".$this->db->escape($type)."'";
            if ($type === 'account') {
                $sql .= " AND fk_societe_account = ".(int) $target['subject_id'];
            } elseif ($type === 'member') {
                $sql .= " AND fk_adherent = ".(int) $target['subject_id'];
            } else {
                $sql .= " AND fk_user = ".(int) $target['subject_id'];
            }
        } else {
            // No valid target -> never broadcast implicitly.
            dol_syslog('PushSender::getTargetSubscriptions called without a valid target', LOG_NOTICE);
            return [];
        }

        $resql = $this->db->query($sql);
        $out = [];
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $out[] = [
                    'rowid'      => (int) $obj->rowid,
                    'endpoint'   => $obj->endpoint,
                    'key_p256dh' => $obj->key_p256dh,
                    'key_auth'   => $obj->key_auth,
                ];
            }
        } else {
            dol_syslog('PushSender::getTargetSubscriptions query failed: '.$this->db->lasterror(), LOG_ERR);
        }
        return $out;
    }

    /**
     * VAPID subject: mailto: or https URL. NEVER derived from
     * $_SERVER['HTTP_HOST'] (absent in cron/trigger context and spoofable).
     *
     * @return string
     */
    private function resolveVapidSubject()
    {
        global $mysoc;

        $configured = getDolGlobalString(self::VAPID_SUBJECT_CONFIG, '');
        if (!empty($configured)) {
            return $configured;
        }
        if (!empty($mysoc->email)) {
            return 'mailto:'.$mysoc->email;
        }
        // Last-resort constant fallback (should be set at install).
        return 'mailto:'.getDolGlobalString('MAIN_INFO_SOCIETE_MAIL', 'admin@localhost');
    }

    /**
     * @param int $id
     * @return void
     */
    private function updateSubscriptionSuccess($id)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET date_last_used = '".$this->db->idate(dol_now())."', success_count = success_count + 1, error_count = 0";
        $sql .= " WHERE rowid = ".(int) $id;
        if (!$this->db->query($sql)) {
            dol_syslog('PushSender::updateSubscriptionSuccess failed sub='.((int) $id).': '.$this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * @param int    $id
     * @param string $error
     * @return void
     */
    private function updateSubscriptionError($id, $error)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " SET date_last_error = '".$this->db->idate(dol_now())."', error_count = error_count + 1";
        $sql .= ", last_error = '".$this->db->escape(substr((string) $error, 0, 255))."'";
        $sql .= " WHERE rowid = ".(int) $id;
        if (!$this->db->query($sql)) {
            dol_syslog('PushSender::updateSubscriptionError failed sub='.((int) $id).': '.$this->db->lasterror(), LOG_ERR);
        }

        // Disable after MAX_ERROR_COUNT consecutive failures.
        $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions SET status = 9";
        $sql .= " WHERE rowid = ".(int) $id." AND error_count >= ".self::MAX_ERROR_COUNT;
        if (!$this->db->query($sql)) {
            dol_syslog('PushSender::updateSubscriptionError disable failed sub='.((int) $id).': '.$this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * @param int $id
     * @return void
     */
    private function removeSubscription($id)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions WHERE rowid = ".(int) $id;
        if (!$this->db->query($sql)) {
            dol_syslog('PushSender::removeSubscription failed sub='.((int) $id).': '.$this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Make sure the minishlink/web-push classes are autoloadable in contexts
     * that do not boot the SmartAuth API front controller (cron, triggers).
     *
     * @return void
     */
    private static function ensureWebPushLoaded()
    {
        if (class_exists('Minishlink\\WebPush\\WebPush')) {
            return;
        }
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}
