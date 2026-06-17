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

        self::ensureDependencies();

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

        $subject = $this->resolveVapidSubject();

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

        $results = [];
        $sent = 0;
        $failed = 0;
        foreach ($subscriptions as $sub) {
            list($httpStatus, $reason, $expired) = $this->dispatchOne($sub, $payload, $vapidKeys, $subject, $ttl, $urgency);
            $success = ($httpStatus >= 200 && $httpStatus < 300);
            $row = ['subscription_id' => (int) $sub['rowid'], 'success' => $success];
            if ($success) {
                $sent++;
                $this->updateSubscriptionSuccess((int) $sub['rowid']);
            } else {
                $failed++;
                $row['error'] = $reason;
                dol_syslog('PushSender::send failed sub='.((int) $sub['rowid']).' reason='.$reason, LOG_WARNING);
                if ($expired) {
                    $this->removeSubscription((int) $sub['rowid']);
                    $row['removed'] = true;
                } else {
                    $this->updateSubscriptionError((int) $sub['rowid'], (string) $reason);
                }
            }
            $this->logSend($sub, $message, $httpStatus, $success, $reason);
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
        $sql = "SELECT rowid, endpoint, key_p256dh, key_auth,";
        $sql .= " subject_type, fk_user, fk_societe_account, fk_adherent, entity";
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
                    'rowid'              => (int) $obj->rowid,
                    'endpoint'           => $obj->endpoint,
                    'key_p256dh'         => $obj->key_p256dh,
                    'key_auth'           => $obj->key_auth,
                    'subject_type'       => $obj->subject_type,
                    'fk_user'            => (int) $obj->fk_user,
                    'fk_societe_account' => isset($obj->fk_societe_account) ? (int) $obj->fk_societe_account : null,
                    'fk_adherent'        => isset($obj->fk_adherent) ? (int) $obj->fk_adherent : null,
                    'entity'             => (int) $obj->entity,
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
     * Encrypt and POST one notification to its push service. Returns the
     * outcome as [httpStatus, reason, expired] so send() can update bookkeeping.
     * A 404/410 means the subscription is gone (expired=true -> delete it).
     *
     * @param array  $sub       Subscription row (endpoint, key_p256dh, key_auth)
     * @param string $payload   JSON payload to encrypt
     * @param array  $vapidKeys ['publicKey'=>..., 'privateKey'=>...]
     * @param string $subject   VAPID subject (mailto:/https)
     * @param int    $ttl       Time-to-live seconds
     * @param string $urgency   Urgency header value
     * @return array{0:int,1:?string,2:bool} [httpStatus, reason, expired]
     */
    private function dispatchOne(array $sub, $payload, array $vapidKeys, $subject, $ttl, $urgency)
    {
        require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

        try {
            $enc = WebPushCrypto::encryptPayload($payload, $sub['key_p256dh'], $sub['key_auth']);
            $authorization = WebPushCrypto::vapidAuthorization(
                $sub['endpoint'],
                $vapidKeys['publicKey'],
                $vapidKeys['privateKey'],
                $subject
            );
        } catch (\Throwable $e) {
            dol_syslog('PushSender::dispatchOne crypto failed sub='.((int) $sub['rowid']).': '.$e->getMessage(), LOG_ERR);
            return [0, 'crypto_error: '.$e->getMessage(), false];
        }

        $headers = array_merge($enc['headers'], [
            'TTL: '.(int) $ttl,
            'Urgency: '.$urgency,
            'Authorization: '.$authorization,
        ]);

        // POSTALREADYFORMATED sends the raw binary body as-is. getURLContent
        // honours Dolibarr's proxy/TLS config (MAIN_PROXY_*), unlike a bundled
        // HTTP client. followlocation=0: push services never redirect.
        $res = getURLContent($sub['endpoint'], 'POSTALREADYFORMATED', $enc['body'], 0, $headers, array('https'));

        $status = isset($res['http_code']) ? (int) $res['http_code'] : 0;
        $expired = in_array($status, [404, 410], true);

        $reason = null;
        if ($status < 200 || $status >= 300) {
            if (!empty($res['curl_error_msg'])) {
                $reason = 'curl: '.$res['curl_error_msg'];
            } else {
                $reason = 'HTTP '.$status;
                if (!empty($res['content'])) {
                    $reason .= ': '.substr((string) $res['content'], 0, 200);
                }
            }
        }

        return [$status, $reason, $expired];
    }

    /**
     * Make sure the crypto helper and firebase/php-jwt are autoloadable in
     * contexts that do not boot the SmartAuth API front controller (cron,
     * triggers, admin pages).
     *
     * @return void
     */
    private static function ensureDependencies()
    {
        if (class_exists('SmartAuth\\Api\\WebPushCrypto') && class_exists('Firebase\\JWT\\JWT')) {
            return;
        }
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        if (!class_exists('SmartAuth\\Api\\WebPushCrypto')) {
            require_once __DIR__.'/WebPushCrypto.php';
        }
    }

    /**
     * Persist one send-log row for a single recipient.
     *
     * Best-effort audit only: a logging failure never breaks the send (it is
     * already logged to syslog by the DAO). No-op when SMARTAUTH_PUSH_LOG_ENABLED
     * is off (the DAO short-circuits).
     *
     * @param array   $sub        Subscription row (rowid + subject identity)
     * @param array   $message    Message payload (title, body, data)
     * @param int     $httpStatus Push Service HTTP status (0 if no response)
     * @param bool    $success    Whether the Push Service accepted the message
     * @param ?string $reason     Failure reason (null on success)
     * @return void
     */
    private function logSend(array $sub, array $message, $httpStatus, $success, $reason)
    {
        if (!getDolGlobalInt('SMARTAUTH_PUSH_LOG_ENABLED', 1)) {
            return;
        }

        dol_include_once('/smartauth/class/smartauthpushlog.class.php');
        if (!class_exists('SmartAuthPushLog')) {
            dol_syslog('PushSender::logSend SmartAuthPushLog class not found, skip log', LOG_WARNING);
            return;
        }

        $data = isset($message['data']) && is_array($message['data']) ? $message['data'] : [];
        $type = isset($data['type']) ? (string) $data['type'] : null;

        $log = new \SmartAuthPushLog($this->db);
        $log->recordSend([
            'fk_subscription'    => (int) $sub['rowid'],
            'subject_type'       => !empty($sub['subject_type']) ? $sub['subject_type'] : 'user',
            'fk_user'            => isset($sub['fk_user']) ? (int) $sub['fk_user'] : 0,
            'fk_societe_account' => isset($sub['fk_societe_account']) ? $sub['fk_societe_account'] : null,
            'fk_adherent'        => isset($sub['fk_adherent']) ? $sub['fk_adherent'] : null,
            'entity'             => isset($sub['entity']) ? (int) $sub['entity'] : 1,
            'notification_type'  => $type,
            'notification_title' => isset($message['title']) ? (string) $message['title'] : null,
            'notification_body'  => isset($message['body']) ? (string) $message['body'] : null,
            'notification_data'  => !empty($data) ? json_encode($data) : null,
            'http_status'        => ((int) $httpStatus) > 0 ? (int) $httpStatus : null,
            'success'            => $success ? 1 : 0,
            'error_message'      => $reason,
        ]);
    }
}
