<?php

/**
 * PushController.php
 *
 * HTTP controller for Web Push Notifications. Subscription management is
 * subject-aware. Actual sending is delegated to PushSender so the engine can
 * be reused from triggers/cron without $user.
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

// WebPush/Subscription are used by PushSender, not by the controller.

class PushController
{
    /**
     * Get the VAPID public key (public, read-only route).
     *
     * @param array|null $arr
     * @return array [responseArray, httpCode]
     */
    public function getVapidPublicKey($arr = null)
    {
        global $db;

        // Read-only on this PUBLIC route: never generate keys here (a write
        // triggered by an unauthenticated GET is undesirable). Keys are created
        // at module install (modSmartauth::init) or via the admin button.
        $publicKey = VapidKeyHelper::readPublicKey($db);

        if (empty($publicKey)) {
            dol_syslog('PushController::getVapidPublicKey VAPID keys not configured', LOG_WARNING);
            return [['error' => 'VAPID keys not configured'], 500];
        }

        return [['publicKey' => $publicKey], 200];
    }

    /**
     * Resolve the authenticated subject for write operations.
     *
     * KNOWN LIMITATION (2026-06): the Bearer route layer is user-scoped and
     * rejects acc:/mbr: subjects on these protected routes (silo A
     * enforcement). So today this always resolves to subject_type='user'. The
     * schema and this helper are already subject-aware: when external-subject
     * push is needed (silo B / SSO), the route admission is widened and this
     * helper reads the TokenSubject from the auth context instead of global
     * $user. Closed by default, widened on demand -- never the reverse.
     *
     * @return array{subject_type:string, fk_user:int, fk_societe_account:?int, fk_adherent:?int}
     */
    private function resolveSubject()
    {
        global $user;

        return [
            'subject_type'       => 'user',
            'fk_user'            => (int) $user->id,
            'fk_societe_account' => null,
            'fk_adherent'        => null,
        ];
    }

    /**
     * Build a subject-aware SQL WHERE fragment (without leading AND).
     *
     * @param array $subject Output of resolveSubject()
     * @return string
     */
    private function subjectWhere($subject)
    {
        global $db;

        $w = "subject_type = '".$db->escape($subject['subject_type'])."'";
        if ($subject['subject_type'] === 'account') {
            $w .= " AND fk_societe_account = ".(int) $subject['fk_societe_account'];
        } elseif ($subject['subject_type'] === 'member') {
            $w .= " AND fk_adherent = ".(int) $subject['fk_adherent'];
        } else {
            $w .= " AND fk_user = ".(int) $subject['fk_user'];
        }
        return $w;
    }

    /**
     * Register (or re-bind) a push subscription for the authenticated subject.
     *
     * @param array|null $arr
     * @return array [responseArray, httpCode]
     */
    public function subscribe($arr = null)
    {
        global $db, $user, $conf;

        // Rate limiting (per IP and per subject). A valid token is required to
        // reach this route, but throttle anyway to bound subscription churn.
        $rateLimiter = new RateLimiter($db);
        $clientIp = RouteController::get_client_ip();
        $ipMax = getDolGlobalInt('SMARTAUTH_RATELIMIT_PUSH_SUBSCRIBE_IP_MAX', 30);
        $ipWindow = getDolGlobalInt('SMARTAUTH_RATELIMIT_PUSH_SUBSCRIBE_IP_WINDOW', 60);
        $rateIp = $rateLimiter->enforceLimit($clientIp, 'push_subscribe_ip', $ipMax, $ipWindow);
        if (!$rateIp['allowed']) {
            dol_syslog('PushController::subscribe rate limit exceeded (ip)', LOG_WARNING);
            return [['error' => 'Too many requests', 'retry_after' => $rateIp['retry_after']], 429];
        }
        $subjMax = getDolGlobalInt('SMARTAUTH_RATELIMIT_PUSH_SUBSCRIBE_SUBJECT_MAX', 30);
        $subjWindow = getDolGlobalInt('SMARTAUTH_RATELIMIT_PUSH_SUBSCRIBE_SUBJECT_WINDOW', 60);
        $rateSubj = $rateLimiter->enforceLimit('user:'.((int) $user->id), 'push_subscribe_subject', $subjMax, $subjWindow);
        if (!$rateSubj['allowed']) {
            dol_syslog('PushController::subscribe rate limit exceeded (subject)', LOG_WARNING);
            return [['error' => 'Too many requests', 'retry_after' => $rateSubj['retry_after']], 429];
        }

        // Validate presence.
        if (empty($arr['subscription']['endpoint']) ||
            empty($arr['subscription']['keys']['p256dh']) ||
            empty($arr['subscription']['keys']['auth'])) {
            dol_syslog('PushController::subscribe invalid subscription format', LOG_WARNING);
            return [['error' => 'Invalid subscription format'], 400];
        }

        $endpoint = $arr['subscription']['endpoint'];
        $keyP256dh = $arr['subscription']['keys']['p256dh'];
        $keyAuth = $arr['subscription']['keys']['auth'];

        // Validate endpoint: must be a valid HTTPS URL.
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || strpos($endpoint, 'https://') !== 0) {
            dol_syslog('PushController::subscribe endpoint not a valid https URL', LOG_WARNING);
            return [['error' => 'Endpoint must be a valid HTTPS URL'], 400];
        }
        // Validate keys: base64url charset.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $keyP256dh) || !preg_match('/^[A-Za-z0-9_-]+$/', $keyAuth)) {
            dol_syslog('PushController::subscribe key not base64url', LOG_WARNING);
            return [['error' => 'Invalid key format (expected base64url)'], 400];
        }

        $label = isset($arr['label']) ? substr($arr['label'], 0, 128) : null;
        $deviceId = isset($arr['device_id']) ? (int) $arr['device_id'] : null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $subject = $this->resolveSubject();

        // UPSERT semantics: an endpoint is globally unique (one push channel per
        // browser install). If it already exists, re-bind it to the CURRENT
        // subject and refresh keys/status -- never return 409. This prevents a
        // shared device from keeping a stale subscription pointed at a previous
        // subject (a stale row would leak notifications to the wrong person).
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE endpoint = '".$db->escape($endpoint)."'";
        $sql .= " AND entity = ".(int) $conf->entity;
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::subscribe lookup failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        if ($db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $id = (int) $obj->rowid;

            $sql = "UPDATE ".MAIN_DB_PREFIX."smartauth_push_subscriptions SET";
            $sql .= " subject_type = '".$db->escape($subject['subject_type'])."'";
            $sql .= ", fk_user = ".(int) $subject['fk_user'];
            $sql .= ", fk_societe_account = ".($subject['fk_societe_account'] !== null ? (int) $subject['fk_societe_account'] : "NULL");
            $sql .= ", fk_adherent = ".($subject['fk_adherent'] !== null ? (int) $subject['fk_adherent'] : "NULL");
            $sql .= ", fk_device = ".($deviceId ? (int) $deviceId : "NULL");
            $sql .= ", key_p256dh = '".$db->escape($keyP256dh)."'";
            $sql .= ", key_auth = '".$db->escape($keyAuth)."'";
            $sql .= ", user_agent = ".($userAgent ? "'".$db->escape($userAgent)."'" : "NULL");
            $sql .= ", label = ".($label ? "'".$db->escape($label)."'" : "NULL");
            $sql .= ", error_count = 0, last_error = NULL, status = 1";
            $sql .= " WHERE rowid = ".$id;
            if (!$db->query($sql)) {
                dol_syslog('PushController::subscribe update failed: '.$db->lasterror(), LOG_ERR);
                return [['error' => 'Database error'], 500];
            }
            return [['id' => $id, 'message' => 'Subscription updated successfully'], 200];
        }

        // Insert new subscription.
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " (subject_type, fk_user, fk_societe_account, fk_adherent, fk_device, entity,";
        $sql .= " endpoint, key_p256dh, key_auth, user_agent, label, date_creation, status)";
        $sql .= " VALUES (";
        $sql .= "'".$db->escape($subject['subject_type'])."', ";
        $sql .= (int) $subject['fk_user'].", ";
        $sql .= ($subject['fk_societe_account'] !== null ? (int) $subject['fk_societe_account'] : "NULL").", ";
        $sql .= ($subject['fk_adherent'] !== null ? (int) $subject['fk_adherent'] : "NULL").", ";
        $sql .= ($deviceId ? (int) $deviceId : "NULL").", ";
        $sql .= (int) $conf->entity.", ";
        $sql .= "'".$db->escape($endpoint)."', ";
        $sql .= "'".$db->escape($keyP256dh)."', ";
        $sql .= "'".$db->escape($keyAuth)."', ";
        $sql .= ($userAgent ? "'".$db->escape($userAgent)."'" : "NULL").", ";
        $sql .= ($label ? "'".$db->escape($label)."'" : "NULL").", ";
        $sql .= "'".$db->idate(dol_now())."', ";
        $sql .= "1";
        $sql .= ")";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::subscribe insert failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        $id = (int) $db->last_insert_id(MAIN_DB_PREFIX."smartauth_push_subscriptions");

        return [['id' => $id, 'message' => 'Subscription registered successfully'], 201];
    }

    /**
     * Remove a push subscription owned by the authenticated subject.
     *
     * @param array|null $arr
     * @return array [responseArray, httpCode]
     */
    public function unsubscribe($arr = null)
    {
        global $db, $conf;

        $endpoint = isset($arr['endpoint']) ? $arr['endpoint'] : null;
        $id = isset($arr['id']) ? (int) $arr['id'] : null;

        if (empty($endpoint) && empty($id)) {
            dol_syslog('PushController::unsubscribe missing endpoint and id', LOG_WARNING);
            return [['error' => 'Either endpoint or id is required'], 400];
        }

        $subject = $this->resolveSubject();

        // A subject can only delete its own subscriptions (subject-aware scope).
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE ".$this->subjectWhere($subject);
        $sql .= " AND entity = ".(int) $conf->entity;

        if ($id) {
            $sql .= " AND rowid = ".(int) $id;
        } else {
            $sql .= " AND endpoint = '".$db->escape($endpoint)."'";
        }

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::unsubscribe delete failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        if ((int) $db->affected_rows($resql) === 0) {
            dol_syslog('PushController::unsubscribe subscription not found for subject', LOG_NOTICE);
            return [['error' => 'Subscription not found'], 404];
        }

        return [['message' => 'Subscription removed successfully'], 200];
    }

    /**
     * List the subscriptions of the authenticated subject.
     *
     * @param array|null $arr
     * @return array [responseArray, httpCode]
     */
    public function listSubscriptions($arr = null)
    {
        global $db, $conf;

        $subject = $this->resolveSubject();

        $sql = "SELECT rowid, label, user_agent, date_creation, date_last_used, success_count, error_count, status";
        $sql .= " FROM ".MAIN_DB_PREFIX."smartauth_push_subscriptions";
        $sql .= " WHERE ".$this->subjectWhere($subject);
        $sql .= " AND entity = ".(int) $conf->entity;
        $sql .= " ORDER BY date_creation DESC";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog('PushController::listSubscriptions query failed: '.$db->lasterror(), LOG_ERR);
            return [['error' => 'Database error'], 500];
        }

        $subscriptions = [];
        while ($obj = $db->fetch_object($resql)) {
            $subscriptions[] = [
                'id' => (int) $obj->rowid,
                'label' => $obj->label,
                'user_agent' => $obj->user_agent,
                'created_at' => $obj->date_creation,
                'last_used_at' => $obj->date_last_used,
                'success_count' => (int) $obj->success_count,
                'status' => (int) $obj->status,
            ];
        }

        return [['subscriptions' => $subscriptions], 200];
    }

    /**
     * Send a push notification (OPTIONAL M2M handler).
     *
     * NOT registered as an HTTP route by default (cf LocalRoutes.php). It only
     * becomes reachable if a server-to-server need is wired as 'oauth2'. The
     * gate is the OAuth scope, NOT a Dolibarr right: an M2M token has no $user
     * with rights, and external subjects (acc:/mbr:) carry no Dolibarr right
     * either. For internal sends (triggers, cron, admin) call PushSender
     * directly -- do NOT go through this handler.
     *
     * @param array|null $arr
     * @return array [responseArray, httpCode]
     */
    public function send($arr = null)
    {
        global $db;

        // HTTP GATE ONLY, for the optional 'oauth2' M2M route. The 'oauth2'
        // protection layer injects oauth_scopes into $arr. With a 'true' (JWT
        // end-user) token, oauth_scopes is empty -> refused.
        $scopes = isset($arr['oauth_scopes']) ? (array) $arr['oauth_scopes'] : [];
        if (!in_array('smartauth:push.send', $scopes, true)) {
            dol_syslog('PushController::send denied: missing scope smartauth:push.send', LOG_WARNING);
            return [['error' => 'insufficient_scope'], 403];
        }

        if (empty($arr['title']) || empty($arr['body'])) {
            dol_syslog('PushController::send missing title or body', LOG_WARNING);
            return [['error' => 'title and body are required'], 400];
        }

        // Resolve the target. Today only user-scoped targeting is wired; the
        // schema supports account/member targeting (set subject_type +
        // subject_id) for when silo B push is enabled.
        $target = [
            'subscription_id' => isset($arr['subscription_id']) ? (int) $arr['subscription_id'] : null,
            'subject_type'    => isset($arr['subject_type']) ? (string) $arr['subject_type'] : 'user',
            'subject_id'      => isset($arr['subject_id']) ? (int) $arr['subject_id'] : null,
            'user_id'         => isset($arr['user_id']) ? (int) $arr['user_id'] : null,
            'device_id'       => isset($arr['device_id']) ? (int) $arr['device_id'] : null,
        ];
        // Back-compat: user_id implies subject_type=user.
        if ($target['user_id'] && !$target['subject_id']) {
            $target['subject_type'] = 'user';
            $target['subject_id'] = $target['user_id'];
        }

        $sender = new PushSender($db);
        list($result, $httpCode) = $sender->send(
            $target,
            [
                'title' => $arr['title'],
                'body'  => $arr['body'],
                'icon'  => $arr['icon'] ?? null,
                'badge' => $arr['badge'] ?? null,
                'tag'   => $arr['tag'] ?? null,
                'data'  => $arr['data'] ?? [],
            ],
            $arr['options'] ?? []
        );

        return [$result, $httpCode];
    }
}
